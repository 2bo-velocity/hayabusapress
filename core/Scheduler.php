<?php
/**
 * HayabusaPress - Scheduled-publish firing and next_schedule precompiler.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Scheduler — scheduled-publish firing and next_schedule.php precompilation
 *
 * Design philosophy:
 *   - Bake "the next published_at to fire (UNIX seconds)" into a single PHP file
 *   - On the FPC fast path, just one integer comparison (zero DB) — pass through if nothing is scheduled / in the future
 *   - On firing, merge into the existing publish flow (equivalent to AdminPostController::save)
 *   - SCHEDULE_CHECK_MIN_INTERVAL suppresses repeated runs right after firing
 *
 * SCHEDULE_MODE:
 *   pseudo (default): an integer comparison check at the very start of index.php -> runDue() only when due
 *   cron            : run posts:publish-due periodically via OS cron
 *   manual          : posts:publish-due directly from an admin button or externally
 *
 * Contents of storage/cache/next_schedule.php:
 *   <?php return 1748000000;  // the next published_at to fire (UNIX seconds). PHP_INT_MAX if nothing is scheduled
 */
class Scheduler
{
    private const CACHE_FILE = STORAGE_PATH . '/cache/next_schedule.php';

    /**
     * Pseudo-cron firing check (called at the very start of index.php).
     * The last_run flag for suppressing re-firing within SCHEDULE_CHECK_MIN_INTERVAL seconds
     * is also kept inside next_schedule.php.
     */
    public static function checkAndRunDue(): void
    {
        $mode = class_exists('Env') ? Env::get('SCHEDULE_MODE', 'pseudo') : ($_ENV['SCHEDULE_MODE'] ?? 'pseudo');
        if ($mode !== 'pseudo') {
            return;
        }

        $next = @include self::CACHE_FILE;
        if (!is_array($next)) {
            // Recompile if the file is missing or in the old format
            self::recompileNext();
            $next = @include self::CACHE_FILE;
        }

        $nextTs   = (int)($next['next'] ?? PHP_INT_MAX);
        $lastRun  = (int)($next['last_run'] ?? 0);
        $interval = class_exists('Env') ? Env::int('SCHEDULE_CHECK_MIN_INTERVAL', 60) : (int)($_ENV['SCHEDULE_CHECK_MIN_INTERVAL'] ?? 60);
        $now      = time();

        if ($nextTs > $now) {
            return;
        }
        if ($now - $lastRun < $interval) {
            return;
        }

        self::runDue();
    }

    /**
     * Promote due scheduled posts to published.
     * Called from CLI posts:publish-due, pseudo-cron, and manual firing.
     */
    public static function runDue(): void
    {
        $now = gmdate('Y-m-d H:i:s'); // UTC

        $due = DB::fetchAll(
            "SELECT id, lang, type, slug, category_id, tags_cache, trans_group, published_at, user_id
               FROM posts
              WHERE status = 'scheduled' AND published_at <= ?",
            [$now]
        );

        if (empty($due)) {
            self::recompileNext();
            return;
        }

        if (!class_exists('Counters')) {
            require_once CORE_PATH . '/Counters.php';
        }
        require_once CORE_PATH . '/Series.php';

        foreach ($due as $post) {
            $counterBefore = Counters::snapshot((int)$post['id']);
            $seriesBefore  = Series::snapshot((int)$post['id']);

            // Promote status to published (keep published_at at the scheduled time)
            DB::execute(
                "UPDATE posts SET status = 'published', updated_at = ? WHERE id = ? AND status = 'scheduled'",
                [gmdate('Y-m-d H:i:s'), $post['id']]
            );

            // Carry the publish onto the junction rows, otherwise the tag listing (which reads
            // post_tags.status) would keep hiding a post that is now live
            if (!class_exists('PostTagIndex')) {
                require_once CORE_PATH . '/PostTagIndex.php';
            }
            PostTagIndex::touch((int)$post['id'], [
                'type'         => (string)($post['type'] ?? 'post'),
                'lang'         => (string)($post['lang'] ?? ''),
                'status'       => 'published',
                'published_at' => $post['published_at'] ?? null,
            ]);

            // The post just became publicly visible, so the category/tag counts move with it
            Counters::applyPostChange($counterBefore, Counters::snapshot((int)$post['id']));
            // A post that goes live in a series gives its neighbours a new previous / next link
            Series::applyPostChange($seriesBefore, Series::snapshot((int)$post['id']));

            // Merge into the existing publish flow (precise FPC purge + sitemap + feed regeneration)
            self::fireCacheInvalidation($post);
        }

        // Record the actual firing time (the basis for pseudo's repeated-run suppression)
        self::recompileNext(time());
    }

    /**
     * Fetch the single next published_at to fire and bake it into next_schedule.php.
     * Always call after saving/changing/firing scheduled posts.
     *
     * @param int|null $lastRun the actual UNIX seconds fired. For a recompute without firing (e.g. post save),
     *                          pass null to keep the existing last_run.
     *                          last_run represents "the time runDue() last ran" and is used for
     *                          checkAndRunDue()'s SCHEDULE_CHECK_MIN_INTERVAL suppression.
     *                          Overwriting it with time() every call would make save operations
     *                          falsely trigger suppression, so update it only on firing.
     */
    public static function recompileNext(?int $lastRun = null): void
    {
        $now = gmdate('Y-m-d H:i:s');

        try {
            $row = DB::fetchOne(
                "SELECT MIN(published_at) AS next_at FROM posts
                  WHERE status = 'scheduled' AND published_at > ?",
                [$now]
            );
            $nextTs = ($row && $row['next_at']) ? strtotime($row['next_at']) : PHP_INT_MAX;
        } catch (Throwable) {
            $nextTs = PHP_INT_MAX;
        }

        // On a recompute without firing, carry over the existing last_run (so save operations do not falsely trigger suppression)
        if ($lastRun === null) {
            $prev    = @include self::CACHE_FILE;
            $lastRun = is_array($prev) ? (int)($prev['last_run'] ?? 0) : 0;
        }

        $data = ['next' => $nextTs, 'last_run' => $lastRun];
        $php  = "<?php return " . var_export($data, true) . ";\n";
        $tmp  = self::CACHE_FILE . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $php, LOCK_EX);
        rename($tmp, self::CACHE_FILE);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::CACHE_FILE, true);   // see SidebarCompiler::forgetCompiled()
        }
    }

    // ----------------------------------------------------------------
    //  Private: post-firing cache invalidation / regeneration
    // ----------------------------------------------------------------

    private static function fireCacheInvalidation(array $post): void
    {
        $lang = $post['lang'] ?? '';

        // Get tag slugs (from the tags_cache JSON)
        $tagSlugs = [];
        if (!empty($post['tags_cache'])) {
            $decoded  = json_decode($post['tags_cache'], true) ?? [];
            $tagSlugs = array_column($decoded, 'slug');
        }

        // Get the category slug
        $catSlug = '';
        if (!empty($post['category_id'])) {
            try {
                $cat     = DB::fetchOne('SELECT slug FROM categories WHERE id = ? LIMIT 1', [(int)$post['category_id']]);
                $catSlug = $cat['slug'] ?? '';
            } catch (Throwable) {}
        }

        // Precise FPC purge
        if (class_exists('CacheManager')) {
            CacheManager::purgePost($post, $tagSlugs, $catSlug);
        }

        // Regenerate the sitemap
        if (class_exists('SiteFileCompiler')) {
            SiteFileCompiler::compileSitemap();
        }

        // Regenerate the feed
        if ($post['type'] === 'post' && $lang !== '' && class_exists('FeedCompiler')) {
            FeedCompiler::compile($lang);
        }

        // Update the sidebar archive widget, then the widget areas that embed it
        if (class_exists('SidebarCompiler')) {
            SidebarCompiler::compileArchiveWidget($lang);
            if (SidebarCompiler::compileAllLayouts($lang) && class_exists('CacheManager')) {
                CacheManager::purgeAll();
            }
        }

        // hreflang sync via TranslationCompiler
        if (!empty($post['trans_group']) && class_exists('TranslationCompiler')) {
            TranslationCompiler::sync((string)$post['trans_group']);
        }
    }
}
