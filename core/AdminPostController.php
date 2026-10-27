<?php
/**
 * HayabusaPress - Post and static-page management controller (save / trash / media).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Post / static-page management controller
 * removed author_*_cache / related_posts_cache, uses the AuthorCompiler singleton
 *     trans_group / hreflang_cache support, HtmlSanitizer integration
 */
class AdminPostController
{
    /**
     * Hard limit for posts.title, mirroring VARCHAR(255) in schema.{mysql,pgsql}.sql.
     * Server-side truncation, the input's maxlength, and the editor's character counter all read
     * this one constant, so the column and the UI cannot drift apart.
     */
    public const TITLE_MAX = 255;

    /** Set by save() when the chosen series was of another language and was left out. */
    private static bool $seriesDropped = false;

    public static function seriesDropped(): bool
    {
        return self::$seriesDropped;
    }

    /**
     * Post listing (with search, filtering, pagination)
     * $opts receives normal/trash view switching, various filters, and paging.
     * Driven by idx_posts_admin_list (type, deleted_at, updated_at) / idx_posts_created.
     *
     * @param array $opts {
     *   view: 'active'|'trash', q: string (title partial match), type: 'post'|'page',
     *   status: 'published'|'draft'|'scheduled', category_id: int, tag_id: int, series_id: int,
     *   pub_from/pub_to: publish-time range (YYYY-MM-DD), cre_from/cre_to: created-time range,
     *   page: int
     * }
     * @return array{posts:array,total:int,page:int,pages:int,size:int}
     */
    public static function index(array $opts = []): array
    {
        $view = ($opts['view'] ?? '') === 'trash' ? 'trash' : 'active';

        // Build the WHERE conditions dynamically (all parameterized)
        $where  = [$view === 'trash' ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL'];
        $params = [];

        // Permission scope: Author/Contributor without post:edit_other see only their own content
        if (!Auth::isSuperUser() && Gate::cannot('post:edit_other')) {
            $where[]  = 'user_id = ?';
            $params[] = Auth::id();
        }

        $q = trim((string)($opts['q'] ?? ''));
        if ($q !== '') {
            $where[]  = 'title LIKE ?';
            $params[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        }
        if (in_array($opts['type'] ?? '', ['post', 'page'], true)) {
            $where[]  = 'type = ?';
            $params[] = $opts['type'];
        }
        if (in_array($opts['status'] ?? '', ['published', 'draft', 'scheduled'], true)) {
            $where[]  = 'status = ?';
            $params[] = $opts['status'];
        }
        if (!empty($opts['category_id'])) {
            $where[]  = 'category_id = ?';
            $params[] = (int)$opts['category_id'];
        }
        if (!empty($opts['series_id'])) {
            $where[]  = 'series_id = ?';
            $params[] = (int)$opts['series_id'];
        }
        if (!empty($opts['tag_id'])) {
            $where[]  = 'EXISTS (SELECT 1 FROM post_tags pt WHERE pt.post_id = posts.id AND pt.tag_id = ?)';
            $params[] = (int)$opts['tag_id'];
        }
        // Publish-time range (YYYY-MM-DD -> 00:00:00..23:59:59 of that day)
        foreach (['pub_from' => ['published_at', '>=', ' 00:00:00'], 'pub_to' => ['published_at', '<=', ' 23:59:59'],
                  'cre_from' => ['created_at', '>=', ' 00:00:00'],   'cre_to' => ['created_at', '<=', ' 23:59:59']] as $key => $spec) {
            $val = trim((string)($opts[$key] ?? ''));
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                $where[]  = "{$spec[0]} {$spec[1]} ?";
                $params[] = $val . $spec[2];
            }
        }

        $whereSql = implode(' AND ', $where);

        // Total count (once, with the same conditions)
        $totalRow = DB::fetchOne("SELECT COUNT(*) AS c FROM posts WHERE {$whereSql}", $params);
        $total    = (int)($totalRow['c'] ?? 0);

        // Pagination
        $size  = (int)(class_exists('Env') ? Env::get('LIST_PAGE_SIZE', '10') : 10);
        $size  = $size > 0 ? $size : 10;
        $pages = max(1, (int)ceil($total / $size));
        $page  = max(1, (int)($opts['page'] ?? 1));
        $page  = min($page, $pages);
        $offset = ($page - 1) * $size;

        $orderBy = $view === 'trash' ? 'deleted_at DESC' : 'COALESCE(published_at, created_at) DESC';
        $cols    = 'id, type, lang, title, slug, status, category_id, user_id, comment_count, published_at, created_at, deleted_at';

        $posts = DB::fetchAll(
            "SELECT {$cols} FROM posts WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$size} OFFSET {$offset}",
            $params
        );

        return [
            'posts' => $posts,
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
            'size'  => $size,
        ];
    }

    /**
     * Fetch data for the edit form.
     * $prefill (new posts only): ['type' => 'page', 'page_template' => 'hbp-contact'] to pre-seed the form,
     * e.g. from the "create a page with this template" link on the plugins screen.
     */
    public static function getEditData(?int $postId, array $prefill = []): array
    {
        $post = $postId ? DB::fetchOne('SELECT * FROM posts WHERE id = ? LIMIT 1', [$postId]) : null;

        // Permission check (cannot edit others' posts)
        if ($post && !Auth::isSuperUser() && Gate::cannot('post:edit_other')) {
            if ((int)$post['user_id'] !== Auth::id()) {
                http_response_code(403);
                exit('403 Forbidden');
            }
        }

        $categories = DB::fetchAll('SELECT id, type, lang, name FROM categories ORDER BY lang, type, name');
        $seriesList = DB::fetchAll('SELECT id, lang, title FROM series ORDER BY lang, title');
        $tags       = DB::fetchAll('SELECT id, type, lang, name FROM tags ORDER BY lang, type, name');
        $selectedTags = [];

        if ($post) {
            $rows = DB::fetchAll('SELECT tag_id FROM post_tags WHERE post_id = ?', [(int)$post['id']]);
            $selectedTags = array_column($rows, 'tag_id');
        }

        // list of attached images of the content being edited (attachments is the source of truth).
        //   thumb: management delivery URL if unpublished (exists in storage/upload), production shard URL if published.
        //   insert_url is the production URL for body insertion (/assets/articles/{ab}/{cd}/{file}).
        $attachments = [];
        if ($post) {
            $attRows = DB::fetchAll(
                'SELECT filename, original_name, mime_type FROM attachments WHERE post_id = ? ORDER BY id DESC',
                [(int)$post['id']]
            );
            foreach ($attRows as $r) {
                $fn = basename((string)$r['filename']);
                if ($fn === '' || self::isWebpTwin($fn)) {
                    continue;   // WebP twins follow their original; the editor never shows them
                }
                $attachments[] = [
                    'filename'      => $fn,
                    'original_name' => (string)($r['original_name'] ?? $fn),
                    'mime'          => (string)($r['mime_type'] ?? ''),
                    'is_image'      => str_starts_with((string)($r['mime_type'] ?? ''), 'image/'),
                    'is_draft'      => Media::uploadExists($fn), // In storage/upload = unpublished
                    'insert_url'    => Media::articleUrl($fn),
                ];
            }
        }

        // List of page templates (from enabled plugins)
        $pageTemplates = [];
        if (class_exists('PluginManager')) {
            foreach (PluginManager::getList() as $plugin) {
                if ($plugin['active'] && !empty($plugin['page_template'])) {
                    $pageTemplates[$plugin['page_template']] = $plugin['name'];
                }
            }
        }

        // New post: pre-seed type / page_template from the prefill (e.g. the plugins screen's
        // "create a page with this template" link). page_template is honored only when it maps to an
        // active page-template plugin, so a stale/forged value cannot leak in.
        if (!$post && $prefill) {
            $seed = [];
            if (($prefill['type'] ?? '') === 'page') {
                $seed['type'] = 'page';
                $pt = (string)($prefill['page_template'] ?? '');
                if ($pt !== '' && isset($pageTemplates[$pt])) {
                    $seed['page_template'] = $pt;
                }
            }
            // A post template (copied, never linked: editing the template later changes nothing here)
            $tplId = (int)($prefill['template'] ?? 0);
            $tpl   = $tplId > 0 ? DB::fetchOne('SELECT * FROM post_templates WHERE id = ? LIMIT 1', [$tplId]) : null;
            if ($tpl) {
                $today = (new DateTimeImmutable('now', new DateTimeZone(Env::get('APP_TIMEZONE', 'UTC'))))->format('Y-m-d');
                $seed  = [
                    'type'             => (string)$tpl['type'],
                    'lang'             => (string)$tpl['lang'],
                    'title'            => str_replace('{date}', $today, (string)$tpl['title']),
                    'content_markdown' => str_replace('{date}', $today, (string)$tpl['body']),
                    'category_id'      => $tpl['category_id'],
                    'layout'           => $tpl['layout'],
                    'show_toc'         => (int)!empty($tpl['show_toc']),
                    'is_sponsored'     => (int)!empty($tpl['is_sponsored']),
                ];
                // Tags are kept by name; offer the ones that exist for this type and language
                $names = array_map('mb_strtolower', preg_split('/\s*,\s*/u', (string)($tpl['tags'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []);
                foreach ($tags as $t) {
                    if ($t['type'] === $seed['type'] && $t['lang'] === $seed['lang'] && in_array(mb_strtolower((string)$t['name']), $names, true)) {
                        $selectedTags[] = $t['id'];
                    }
                }
            }
            if ($seed) {
                $post = $seed;
            }
        }

        // Layouts of the active theme (posts.layout). Only offered when the theme declares more than one.
        $layouts = [];
        if (class_exists('ThemeManager')) {
            $uiLocale = class_exists('View') ? View::resolveCurrentLocale('admin/edit') : 'en';
            foreach (ThemeManager::layouts() as $id => $def) {
                $layouts[$id] = ThemeManager::layoutLabel($def, $uiLocale);
            }
        }

        // Raw-HTML trust recorded on the post, for the editor badge and the "your save strips it" warning
        require_once CORE_PATH . '/PostCompiler.php';
        $recorded = $post && $post['content_saved_by'] !== null;
        $rawTrust = [
            'recorded'   => $recorded,
            'allowed'    => $recorded && !empty($post['raw_html_allowed']),
            'saver'      => $recorded ? PostCompiler::saverName((int)$post['content_saved_by']) : '',
            'mayUseRaw'  => PostCompiler::currentMayUseRaw(),
        ];

        return [
            // On new creation, return an empty array instead of null (so $post['x'] ?? '' works in templates and avoids warnings)
            'post'           => $post ?: [],
            'rawTrust'       => $rawTrust,
            'postTemplates'  => $postId ? [] : DB::fetchAll('SELECT id, name FROM post_templates ORDER BY name'),
            'publishSlots'   => (function () use ($post) { require_once CORE_PATH . '/PublishSlots.php'; return !empty($post['id']) ? PublishSlots::slots() : []; })(),
            'nextFreeSlot'   => (function () use ($post) { require_once CORE_PATH . '/PublishSlots.php'; return !empty($post['id']) ? PublishSlots::nextFree() : null; })(),
            'redirects'      => $post ? (function () use ($post) { require_once CORE_PATH . '/RedirectCompiler.php'; return RedirectCompiler::forPost((int)$post['id']); })() : [],
            'categories'     => $categories,
            'seriesList'     => $seriesList,
            // Chapter headings already used in this post's series, offered as suggestions
            'seriesParts'    => !empty($post['series_id']) ? array_column(DB::fetchAll(
                'SELECT DISTINCT series_part FROM posts WHERE series_id = ? AND series_part IS NOT NULL ORDER BY series_part',
                [(int)$post['series_id']]
            ), 'series_part') : [],
            'tags'           => $tags,
            'selected_tags'  => $selectedTags,
            'contentLocales' => class_exists('HbpLocale') ? HbpLocale::getAvailableContentLocales() : ['ja' => 'JA', 'en' => 'EN'],
            'defaultLang'    => Env::get('SITE_DEFAULT_LANG', 'en'),
            'pageTemplates'  => $pageTemplates,
            'layouts'        => $layouts,
            'attachments'    => $attachments,
            'titleMax'       => self::TITLE_MAX,
        ];
    }

    /**
     * physically delete an attached image (the "delete" in the list UI / the delete API).
     * Treats attachments as the source of truth and physically deletes its row + real files (both storage/public, shard/legacy-flat).
     * Does not auto-rewrite `![]()` in the body (content_markdown) (safe side; removing broken links is manual by the user).
     * The caller must have already verified media:manage + (ownership if no post:edit_other) + CSRF.
     */
    public static function deleteAttachment(int $postId, string $filename): void
    {
        $fn = basename($filename); // Path-traversal defense
        if ($postId <= 0 || $fn === '') {
            throw new RuntimeException(HbpLocale::t('err_delete_target_invalid', 'The deletion target is invalid.'));
        }
        // Target only rows linked to this post_id (do not touch other content's images)
        $row = DB::fetchOne(
            'SELECT id FROM attachments WHERE post_id = ? AND filename = ? LIMIT 1',
            [$postId, $fn]
        );
        if (!$row) {
            throw new RuntimeException(HbpLocale::t('err_attachment_not_found', 'The specified attachment was not found.'));
        }

        // Delete the real files (all candidates: published/isolated, shard/legacy-flat)
        $candidates = [
            Media::articlePath($fn),
            Media::uploadPath($fn),
            PUBLIC_PATH  . '/assets/articles/' . $fn, // legacy flat
            STORAGE_PATH . '/upload/' . $fn,          // legacy flat
        ];
        foreach ($candidates as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }

        DB::execute('DELETE FROM attachments WHERE id = ?', [(int)$row['id']]);

        // The WebP twin goes with its original
        if (!self::isWebpTwin($fn)) {
            $twin = $fn . '.webp';
            foreach ([Media::articlePath($twin), Media::uploadPath($twin)] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            DB::execute('DELETE FROM attachments WHERE post_id = ? AND filename = ?', [$postId, $twin]);
        }
    }

    /** Save a post (shared for new and update) */
    public static function save(array $input): int
    {
        $postId    = (int)($input['id'] ?? 0);
        $type      = in_array($input['type'] ?? 'post', ['post', 'page']) ? $input['type'] : 'post';
        $lang      = trim($input['lang'] ?? Env::get('SITE_DEFAULT_LANG', 'en'));
        if (!preg_match('/^[a-z]{2,5}$/', $lang)) {
            $lang = Env::get('SITE_DEFAULT_LANG', 'en');
        }
        $title     = trim($input['title'] ?? '');
        $markdown  = $input['content_markdown'] ?? '';
        $status    = in_array($input['status'] ?? 'draft', ['published', 'draft', 'scheduled']) ? $input['status'] : 'draft';
        // scheduled-publish datetime (used only for scheduled; UTC conversion)
        $scheduledAt = null;
        if ($status === 'scheduled' && !empty($input['scheduled_at'])) {
            $tz = class_exists('Env') ? Env::get('APP_TIMEZONE', 'UTC') : 'UTC';
            try {
                $dt          = new DateTimeImmutable($input['scheduled_at'], new DateTimeZone($tz));
                $scheduledAt = $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            } catch (Throwable) {
                $status = 'draft'; // Fall back to draft on parse failure
            }
        }

        // the first save of a new creation always creates the row as a draft (status='draft', published_at=NULL).
        // Publish/schedule can only transition via explicit operations on the later edit screen (and while holding post:publish).
        // This structurally prevents the accident of "accidentally publishing while still entering a new post".
        // Also, roles without post:publish (Contributor, etc.) cannot transition to publish/schedule.
        $canPublish = Auth::isSuperUser() || Gate::can('post:publish');
        if ($postId === 0 || !$canPublish) {
            if ($status !== 'draft') {
                $status      = 'draft';
                $scheduledAt = null;
            }
        }

        $slug      = self::buildSlug($input['slug'] ?? '', $title, $type, $lang, $postId, $scheduledAt);
        $catId     = !empty($input['category_id']) ? (int)$input['category_id'] : null;

        // Series: posts only, and only a series of the post's own language. Anything else is dropped
        // (and reported to the editor) rather than stored, because the series pages are per language.
        self::$seriesDropped = false;
        $seriesId   = $type === 'post' && !empty($input['series_id']) ? (int)$input['series_id'] : null;
        $seriesPart = null;
        $seriesOrder = 0;
        if ($seriesId !== null) {
            require_once CORE_PATH . '/Series.php';
            $seriesRow = DB::fetchOne('SELECT id, lang FROM series WHERE id = ? LIMIT 1', [$seriesId]);
            if (!$seriesRow || $seriesRow['lang'] !== $lang) {
                self::$seriesDropped = $seriesRow !== false;
                $seriesId = null;
            } else {
                $seriesPart = Series::cleanPart($input['series_part'] ?? null);
                // Keep the position while the post stays in the same series; join at the end otherwise
                $prevSeries  = $postId > 0 ? DB::fetchOne('SELECT series_id, series_order FROM posts WHERE id = ? LIMIT 1', [$postId]) : null;
                $seriesOrder = $prevSeries && (int)($prevSeries['series_id'] ?? 0) === $seriesId
                    ? (int)$prevSeries['series_order']
                    : Series::nextOrder($seriesId);
            }
        }

        // Flags
        $allowComment     = isset($input['allow_comment'])     ? 1 : 0;
        $showRelatedPosts = isset($input['show_related_posts']) ? 1 : 0;
        $showShare        = isset($input['show_share'])         ? 1 : 0;
        $showAuthor       = isset($input['show_author'])        ? 1 : 0;
        $showToc          = isset($input['show_toc'])           ? 1 : 0;
        $isSponsored      = isset($input['is_sponsored'])       ? 1 : 0;
        $metaDescription  = trim($input['meta_description'] ?? '');
        $metaKeywords     = trim($input['meta_keywords'] ?? '');
        // page_template must name an ENABLED page-template plugin. Accepting it unchecked would
        // (a) blow past the VARCHAR(50) column on a long value and abort the save with a PDO error,
        // and (b) persist a stale/forged key that RouterCompiler cannot resolve, turning the page
        // into a silent 404. The edit screen applies the same allowlist when pre-filling (see load()).
        $pageTemplate = $type === 'page' && !empty($input['page_template'])
            ? self::validatePageTemplate(trim($input['page_template']))
            : null;

        // layout: an ID declared by the active theme, or NULL for its default. An unknown value is
        // dropped rather than stored, like page_template (posts.layout is VARCHAR(50)).
        $layout = self::validateLayout(trim((string)($input['layout'] ?? '')));

        // featured image (resolve the fallback once at save time and bake it)
        $featuredImage = self::resolveFeaturedImage(trim($input['featured_image'] ?? ''), $markdown);
        $featuredAlt   = trim($input['featured_image_alt'] ?? '') ?: null;

        // Translation group (P-series)
        $transGroup = trim($input['trans_group'] ?? '');
        if ($transGroup !== '' && !preg_match('/^[0-9a-f\-]{36}$/', $transGroup)) {
            $transGroup = '';
        }

        if ($title === '') {
            throw new InvalidArgumentException(HbpLocale::t('err_title_required', 'Title is required.'));
        }
        // Truncate to the schema limit (posts.title VARCHAR(255); pgsql/strict MySQL errors on overflow)
        $title           = mb_substr($title, 0, self::TITLE_MAX);
        $metaDescription = mb_substr($metaDescription, 0, 255);
        $metaKeywords    = mb_substr($metaKeywords, 0, 255);
        $featuredAlt     = $featuredAlt !== null ? mb_substr($featuredAlt, 0, 255) : null;

        // Markdown -> HTML compile. Raw HTML follows whoever last saved the body (PostCompiler):
        // an unchanged body keeps the recorded trust, a changed one takes the saver's permission.
        require_once CORE_PATH . '/PostCompiler.php';
        $previous = $postId > 0
            ? DB::fetchOne('SELECT content_markdown, raw_html_allowed, content_saved_by FROM posts WHERE id = ? LIMIT 1', [$postId])
            : null;
        [$allowRawHtml, $contentSavedBy] = PostCompiler::trustForSave($previous ?: null, $markdown);
        require_once CORE_PATH . '/SeoCompiler.php';
        // The table of contents and the sponsored notice are baked here too (options of the same pass)
        $contentHtml  = PostCompiler::render([
            'content_markdown' => $markdown,
            'raw_html_allowed' => $allowRawHtml,
            'show_toc'         => (bool)$showToc,
            'is_sponsored'     => (bool)$isSponsored,
            'lang'             => $lang,
            'id'               => $postId > 0 ? $postId : null,
            'type'             => $type,
        ]);
        // Description used when meta_description is left empty (SeoCompiler::buildMeta)
        $metaDescAuto = SeoCompiler::excerpt($contentHtml);
        $metaDescAuto = $metaDescAuto !== '' ? mb_substr($metaDescAuto, 0, 255) : null;
        // Character count and reading time of what the reader will see
        [$charCount, $readingMinutes] = PostCompiler::textStats($contentHtml);

        // Social-post text: typed (kept as typed, NULL when empty) and generated (always refreshed)
        $shareText = trim(str_replace(["\r\n", "\r"], "\n", (string)($input['share_text'] ?? '')));
        $shareText = $shareText !== '' ? mb_substr($shareText, 0, 500) : null;
        $catTags   = $catId ? (DB::fetchOne('SELECT hashtags FROM categories WHERE id = ? LIMIT 1', [$catId])['hashtags'] ?? null) : null;
        $shareAuto = PostCompiler::shareText($title, $metaDescription !== '' ? $metaDescription : (string)$metaDescAuto, $catTags);

        // Author ID (NULL for Super User)
        $userId = Auth::id() === 0 ? null : Auth::id();

        // Tag cache
        $tagIds    = array_map('intval', $input['tags'] ?? []);
        $tagsCache = self::buildTagsCache($tagIds, $type);

        $now = gmdate('Y-m-d H:i:s'); // UTC (the DB stores UTC)

        // published_at finalization logic
        // Immediate publish: NOW(UTC) / scheduled: specified time (UTC) / draft: NULL
        // Finalized only on first publish; unchanged on re-edit (the update date is updated_at)
        $publishedAt = null;
        if ($status === 'published') {
            $publishedAt = $now; // Immediate publish
        } elseif ($status === 'scheduled') {
            $publishedAt = $scheduledAt; // Scheduled time (already UTC-converted above)
        }
        // Stays NULL for draft

        // Capture the pre-change counter state (null for a brand-new post)
        require_once CORE_PATH . '/Counters.php';
        require_once CORE_PATH . '/Series.php';
        $counterBefore = Counters::snapshot($postId);
        $seriesBefore  = Series::snapshot($postId);

        // Remember this before the INSERT overwrites $postId, so the post_saved hook can tell
        // a first save from an edit.
        $isNew = $postId === 0;

        if ($postId === 0) {
            // New creation
            DB::execute(
                'INSERT INTO posts
                 (type, lang, slug, title, content_markdown, content_html, char_count, reading_minutes,
                  status, category_id, series_id, series_order, series_part,
                  tags_cache, allow_comment, show_related_posts,
                  show_share, meta_description, meta_keywords, meta_description_auto,
                  share_text, share_text_auto,
                  user_id, show_author, show_toc, is_sponsored, raw_html_allowed, content_saved_by,
                  page_template, layout, trans_group,
                  featured_image, featured_image_alt,
                  comment_count, published_at, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?)',
                [
                    $type, $lang, $slug, $title, $markdown, $contentHtml, $charCount, $readingMinutes,
                    $status, $catId, $seriesId, $seriesOrder, $seriesPart,
                    $tagsCache, $allowComment, $showRelatedPosts,
                    $showShare, $metaDescription, $metaKeywords, $metaDescAuto,
                    $shareText, $shareAuto,
                    $userId, $showAuthor, $showToc, $isSponsored, $allowRawHtml ? 1 : 0, $contentSavedBy,
                    $pageTemplate, $layout, $transGroup ?: null,
                    $featuredImage, $featuredAlt,
                    $publishedAt, $now, $now,
                ]
            );
            $postId = (int)DB::lastInsertId();
        } else {
            // Update
            $existing      = DB::fetchOne('SELECT type, lang, slug, status, trans_group, published_at FROM posts WHERE id = ? LIMIT 1', [$postId]);
            $oldStatus     = $existing['status'] ?? 'draft';
            $oldTransGroup = $existing['trans_group'] ?? '';
            // If already first-published, do not change published_at (so the posting time does not change on re-edit)
            $existingPublishedAt = $existing['published_at'] ?? null;
            if ($existingPublishedAt !== null && $status === 'published') {
                $publishedAt = $existingPublishedAt; // Keep the existing published_at
            }

            // publish order: featured image resolved (done) -> file move -> row save -> precise FPC purge.
            // Switching publish <-> draft physically moves media (the body URL is unchanged = fixed to production).
            if ($oldStatus !== $status) {
                self::moveMedia($postId, $status);
            }

            DB::execute(
                'UPDATE posts SET
                   type=?, lang=?, slug=?, title=?, content_markdown=?, content_html=?,
                   char_count=?, reading_minutes=?, status=?,
                   category_id=?, series_id=?, series_order=?, series_part=?, tags_cache=?, allow_comment=?, show_related_posts=?,
                   show_share=?, meta_description=?, meta_keywords=?, meta_description_auto=?,
                   share_text=?, share_text_auto=?,
                   show_author=?, show_toc=?, is_sponsored=?, raw_html_allowed=?, content_saved_by=?,
                   page_template=?, layout=?, trans_group=?,
                   featured_image=?, featured_image_alt=?, published_at=?, updated_at=?
                 WHERE id=?',
                [
                    $type, $lang, $slug, $title, $markdown, $contentHtml,
                    $charCount, $readingMinutes, $status,
                    $catId, $seriesId, $seriesOrder, $seriesPart, $tagsCache, $allowComment, $showRelatedPosts,
                    $showShare, $metaDescription, $metaKeywords, $metaDescAuto,
                    $shareText, $shareAuto,
                    $showAuthor, $showToc, $isSponsored, $allowRawHtml ? 1 : 0, $contentSavedBy,
                    $pageTemplate, $layout, $transGroup ?: null,
                    $featuredImage, $featuredAlt, $publishedAt, $now, $postId,
                ]
            );

            // Linked hreflang sync on translation-group change
            if (class_exists('TranslationCompiler')) {
                if ($oldTransGroup !== $transGroup) {
                    if ($oldTransGroup !== '' && $oldTransGroup !== null) {
                        TranslationCompiler::detach($postId, $oldTransGroup);
                    }
                }
            }
        }

        // Old URL of a post that was public keeps working as a 301 (and a reused slug wins over a redirect)
        require_once CORE_PATH . '/RedirectCompiler.php';
        $wasPublic = isset($existing) && ($existing['status'] ?? '') === 'published';
        RedirectCompiler::recordRename(
            $postId,
            (string)($existing['type'] ?? $type), (string)($existing['lang'] ?? $lang), $wasPublic ? (string)$existing['slug'] : '',
            $type, $lang, $slug
        );
        RedirectCompiler::compile();

        // Update the tag junction table (also stamps the listing keys onto each junction row)
        require_once CORE_PATH . '/PostTagIndex.php';
        PostTagIndex::sync($postId, $tagIds, [
            'type'         => $type,
            'lang'         => $lang,
            'status'       => $status,
            'published_at' => $publishedAt,
        ]);

        // Move categories.post_count / tags.post_count by the delta this save caused
        Counters::applyPostChange($counterBefore, Counters::snapshot($postId));
        // Series: counters cache, neighbours and table of contents of the old and new position
        Series::applyPostChange($seriesBefore, Series::snapshot($postId));

        // Translation-group sync (recompute hreflang_cache)
        if ($transGroup !== '' && class_exists('TranslationCompiler')) {
            TranslationCompiler::sync($transGroup);
        }

        // If comments were turned OFF, delete the comment cache
        if (!$allowComment) {
            $cacheFile = STORAGE_PATH . '/comments/' . $postId . '.cache.php';
            if (file_exists($cacheFile)) {
                unlink($cacheFile);
            }
        }

        // Precise purge (linked to the dependency graph)
        require_once CORE_PATH . '/CacheManager.php';
        $catSlug = '';
        if ($catId) {
            $cat     = DB::fetchOne('SELECT slug FROM categories WHERE id = ? LIMIT 1', [$catId]);
            $catSlug = $cat['slug'] ?? '';
        }
        $tagSlugs = [];
        if ($tagsCache !== null) {
            $decodedTags = json_decode($tagsCache, true) ?? [];
            $tagSlugs    = array_column($decodedTags, 'slug');
        }
        // pass published_at to purgePost to compute the archive key correctly
        $postRecord = [
            'lang'         => $lang,
            'type'         => $type,
            'slug'         => $slug,
            'published_at' => $publishedAt,
            'created_at'   => $now,
            'category_id'  => $catId,
        ];
        CacheManager::purgePost($postRecord, $tagSlugs, $catSlug);

        // Author-profile change linkage (AuthorCompiler regeneration is done on the AdminUserController side)

        // Recompile related caches (in RouterCompiler is not triggered on post save)
        CategoryCompiler::compile();
        TagCompiler::compile();
        SidebarCompiler::compileArchiveWidget($lang);
        // Every cached page embeds the widget areas (recent posts, category counts, ...): when the
        // save changed them, the pinpoint purge above is not enough.
        if (SidebarCompiler::compileAllLayouts($lang)) {
            CacheManager::purgeAll();
        }
        SiteFileCompiler::compileSitemap();

        // regenerate feeds (only when the post type's publish/unpublish changes)
        if ($type === 'post') {
            $feedPath = CORE_PATH . '/FeedCompiler.php';
            if (file_exists($feedPath)) {
                require_once $feedPath;
                FeedCompiler::compile($lang);
            }
        }

        // Nothing to do here for search: it reads the DB's own index at request time
        // (core/PostSearch.php), so a save never has to regenerate a search index.

        // recompile next_schedule.php for scheduled publishing (on scheduled save; updates the firing time)
        $schedulerPath = CORE_PATH . '/Scheduler.php';
        if (file_exists($schedulerPath)) {
            require_once $schedulerPath;
            Scheduler::recompileNext();
        }

        self::firePostSaved([
            'post_id'   => $postId,
            'type'      => $type,
            'lang'      => $lang,
            'slug'      => $slug,
            'status'    => $status,
            'is_new'    => $isNew,
            'series_id' => $seriesId,
        ]);

        return $postId;
    }

    /**
     * Fire the post_saved event.
     *
     * Deliberately last: the row is committed and every cache has already been rebuilt, so a
     * listener observes the finished state rather than a half-saved one.
     *
     * Unlike before_comment_create, this is a **notification, not an interceptor** — it cannot
     * cancel or alter the save, because there is nothing left to cancel. That is also why a
     * throwing listener is contained here: the content is already stored, and letting a plugin's
     * failure bubble up would show the author an error for a save that actually succeeded.
     *
     * @param array{post_id:int,type:string,lang:string,slug:string,status:string,is_new:bool,series_id:?int} $context
     */
    private static function firePostSaved(array $context): void
    {
        $pluginManager = CORE_PATH . '/PluginManager.php';
        if (!is_file($pluginManager)) {
            return;
        }
        require_once $pluginManager;

        foreach (PluginManager::getHooks('post_saved') as $hook) {
            // hooks.cache.php holds absolute paths resolved at compile time. Verify the file,
            // class and method anyway: a stale cache left by a deleted plugin must not be fatal.
            if (empty($hook['file']) || !is_file($hook['file'])) {
                continue;
            }
            require_once $hook['file'];
            if (empty($hook['class']) || empty($hook['method'])
                || !method_exists($hook['class'], $hook['method'])) {
                continue;
            }
            try {
                call_user_func_array([$hook['class'], $hook['method']], [&$context]);
            } catch (Throwable $e) {
                error_log(sprintf(
                    '[HBP WARNING] post_saved listener %s::%s failed for post %d: %s',
                    $hook['class'],
                    $hook['method'],
                    $context['post_id'],
                    $e->getMessage()
                ));
                if (class_exists('PluginErrors')) {
                    PluginErrors::record(PluginErrors::pluginOfFile($hook['file']) ?? '?', 'post_saved', $e->getMessage());
                }
            }
        }
    }

    /**
     * move to trash (logical delete).
     * Sets deleted_at, unpublishes status (method A), reverse-moves media to storage/upload,
     * and precisely purges the FPC to remove it from the public face. The DB row and real files remain; recoverable via restore().
     */
    public static function trash(int $postId): void
    {
        $post = DB::fetchOne(
            'SELECT type, lang, slug, status, category_id, tags_cache, published_at, created_at, deleted_at
             FROM posts WHERE id = ? LIMIT 1',
            [$postId]
        );
        if (!$post || $post['deleted_at'] !== null) {
            return; // Absent or already in the trash
        }

        // Method A: publish -> unpublish (reverse-move media from the public area to the isolated area)
        if ($post['status'] === 'published') {
            self::moveMedia($postId, 'draft');
        }

        require_once CORE_PATH . '/Counters.php';
        require_once CORE_PATH . '/Series.php';
        $counterBefore = Counters::snapshot($postId);
        $seriesBefore  = Series::snapshot($postId);

        $now = gmdate('Y-m-d H:i:s');
        DB::execute(
            "UPDATE posts SET deleted_at = ?, status = 'draft', updated_at = ? WHERE id = ?",
            [$now, $now, $postId]
        );
        Counters::applyPostChange($counterBefore, Counters::snapshot($postId));
        Series::applyPostChange($seriesBefore, Series::snapshot($postId));

        // Carry the unpublish onto the junction rows so the tag listing stops returning it
        require_once CORE_PATH . '/PostTagIndex.php';
        PostTagIndex::touch($postId, [
            'type'         => (string)$post['type'],
            'lang'         => (string)$post['lang'],
            'status'       => 'draft',
            'published_at' => $post['published_at'],
        ]);

        self::purgePostCaches($post);
    }

    /**
     * restore from trash (clear deleted_at).
     * status stays draft (re-publishing is an explicit user operation). Rebuilds the listing/sidebar/sitemap.
     */
    public static function restore(int $postId): void
    {
        $post = DB::fetchOne(
            'SELECT type, lang, slug, status, category_id, tags_cache, published_at, created_at, deleted_at
             FROM posts WHERE id = ? LIMIT 1',
            [$postId]
        );
        if (!$post || $post['deleted_at'] === null) {
            return;
        }
        require_once CORE_PATH . '/Counters.php';
        require_once CORE_PATH . '/Series.php';
        $counterBefore = Counters::snapshot($postId);
        $seriesBefore  = Series::snapshot($postId);

        $now = gmdate('Y-m-d H:i:s');
        DB::execute('UPDATE posts SET deleted_at = NULL, updated_at = ? WHERE id = ?', [$now, $postId]);
        Counters::applyPostChange($counterBefore, Counters::snapshot($postId));
        Series::applyPostChange($seriesBefore, Series::snapshot($postId));

        // Keep the junction rows aligned with the post row (restore leaves status at draft)
        require_once CORE_PATH . '/PostTagIndex.php';
        PostTagIndex::touch($postId, [
            'type'         => (string)$post['type'],
            'lang'         => (string)$post['lang'],
            'status'       => (string)$post['status'],
            'published_at' => $post['published_at'],
        ]);

        self::purgePostCaches($post);
    }

    /** Shared processing for precise FPC purge + rebuilding related caches (category/sidebar/sitemap/feed/scheduler) */
    private static function purgePostCaches(array $post): void
    {
        // Redirects only point at published posts: trash/restore/delete change that set
        require_once CORE_PATH . '/RedirectCompiler.php';
        RedirectCompiler::compile();

        require_once CORE_PATH . '/CacheManager.php';
        $catSlug = '';
        if (!empty($post['category_id'])) {
            $cat     = DB::fetchOne('SELECT slug FROM categories WHERE id = ? LIMIT 1', [(int)$post['category_id']]);
            $catSlug = $cat['slug'] ?? '';
        }
        $tagSlugs = [];
        if (!empty($post['tags_cache'])) {
            $decoded  = json_decode($post['tags_cache'], true) ?? [];
            $tagSlugs = array_column($decoded, 'slug');
        }
        CacheManager::purgePost($post, $tagSlugs, $catSlug);

        CategoryCompiler::compile();
        $lang = $post['lang'] ?? '';
        if ($lang !== '') {
            SidebarCompiler::compileArchiveWidget($lang);
            if (SidebarCompiler::compileAllLayouts($lang)) {
                CacheManager::purgeAll();
            }
        }
        SiteFileCompiler::compileSitemap();

        if (($post['type'] ?? '') === 'post' && $lang !== '') {
            $feedPath = CORE_PATH . '/FeedCompiler.php';
            if (file_exists($feedPath)) {
                require_once $feedPath;
                FeedCompiler::compile($lang);
            }
        }
        $schedulerPath = CORE_PATH . '/Scheduler.php';
        if (file_exists($schedulerPath)) {
            require_once $schedulerPath;
            Scheduler::recompileNext();
        }
    }

    /** Delete a post (physical, complete deletion). Used by "delete permanently" from trash and content:purge-trash */
    public static function delete(int $postId): void
    {
        $post = DB::fetchOne(
            'SELECT type, lang, slug, category_id, tags_cache, trans_group, published_at, created_at FROM posts WHERE id = ? LIMIT 1',
            [$postId]
        );
        if (!$post) {
            return;
        }

        // Delete media files (shard path; also cleans up legacy flat placement during migration as a fallback)
        $attachments = DB::fetchAll('SELECT filename FROM attachments WHERE post_id = ?', [$postId]);
        foreach ($attachments as $att) {
            $fn = basename($att['filename']);
            $candidates = [
                Media::articlePath($fn),
                Media::uploadPath($fn),
                PUBLIC_PATH  . '/assets/articles/' . $fn, // legacy flat
                STORAGE_PATH . '/upload/' . $fn,          // legacy flat
            ];
            foreach ($candidates as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
        }

        // Detach from the translation group
        $transGroup = $post['trans_group'] ?? '';
        if ($transGroup !== '' && $transGroup !== null && class_exists('TranslationCompiler')) {
            TranslationCompiler::detach($postId, $transGroup);
        }

        // Snapshot before the row (and its post_tags rows, via FK CASCADE) disappears
        require_once CORE_PATH . '/Counters.php';
        require_once CORE_PATH . '/Series.php';
        $counterBefore = Counters::snapshot($postId);
        $seriesBefore  = Series::snapshot($postId);

        DB::execute('DELETE FROM posts WHERE id = ?', [$postId]);
        // slug_redirects rows go with the post (ON DELETE CASCADE); rebuild the compiled map
        require_once CORE_PATH . '/RedirectCompiler.php';
        RedirectCompiler::compile();

        // snapshot() now returns null, which is exactly "contributes nothing"
        Counters::applyPostChange($counterBefore, Counters::snapshot($postId));
        Series::applyPostChange($seriesBefore, null);

        // Delete the comment cache
        $cacheFile = STORAGE_PATH . '/comments/' . $postId . '.cache.php';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }

        // Precise purge
        require_once CORE_PATH . '/CacheManager.php';
        $catSlug = '';
        if (!empty($post['category_id'])) {
            $cat     = DB::fetchOne('SELECT slug FROM categories WHERE id = ? LIMIT 1', [(int)$post['category_id']]);
            $catSlug = $cat['slug'] ?? '';
        }
        $tagSlugs = [];
        if (!empty($post['tags_cache'])) {
            $decodedTags = json_decode($post['tags_cache'], true) ?? [];
            $tagSlugs    = array_column($decodedTags, 'slug');
        }
        CacheManager::purgePost($post, $tagSlugs, $catSlug);

        CategoryCompiler::compile();
        SidebarCompiler::compileArchiveWidget($post['lang'] ?? '');
        if (SidebarCompiler::compileAllLayouts($post['lang'] ?? '')) {
            CacheManager::purgeAll();
        }
        SiteFileCompiler::compileSitemap();

        // regenerate feeds when a post (post type) is deleted
        $deletedLang = $post['lang'] ?? '';
        if ($post['type'] === 'post' && $deletedLang !== '') {
            $feedPath = CORE_PATH . '/FeedCompiler.php';
            if (file_exists($feedPath)) {
                require_once $feedPath;
                FeedCompiler::compile($deletedLang);
            }
        }

        // recompute next_schedule.php when a scheduled post is deleted
        $schedulerPath = CORE_PATH . '/Scheduler.php';
        if (file_exists($schedulerPath)) {
            require_once $schedulerPath;
            Scheduler::recompileNext();
        }
    }

    // ---- Private helpers ----

    /**
     * Slug from the typed value, or — when it is empty — by the SEO setting slug_mode:
     *   title    : ASCII words of the title (the default)
     *   datetime : YYYYMMDD-HHMM in APP_TIMEZONE (the scheduled time for a scheduled post)
     *   id       : p{ID}
     * A title with no ASCII left, and "id" before the post has an ID, fall back to datetime.
     */
    private static function buildSlug(string $raw, string $title, string $type, string $lang, int $postId, ?string $scheduledAtUtc = null): string
    {
        $mode = 'title';
        if ($raw === '') {
            require_once CORE_PATH . '/SeoCompiler.php';
            $mode = (string)(SeoCompiler::loadGlobal()['slug_mode'] ?? 'title');
        }
        $slug = '';
        if ($raw !== '') {
            $slug = $raw;
        } elseif ($mode === 'id' && $postId > 0) {
            $slug = 'p' . $postId;
        } elseif ($mode === 'title') {
            $slug = mb_strtolower(preg_replace('/[^\w-]/u', '-', $title));
        }
        $slug = strtolower(preg_replace('/[^a-z0-9-]/i', '-', $slug));
        $slug = trim(preg_replace('/-+/', '-', $slug), '-');
        if ($slug === '') {
            $tz   = new DateTimeZone(Env::get('APP_TIMEZONE', 'UTC'));
            $when = $scheduledAtUtc !== null
                ? new DateTimeImmutable($scheduledAtUtc, new DateTimeZone('UTC'))
                : new DateTimeImmutable('now');
            $slug = $when->setTimezone($tz)->format('Ymd-Hi');
        }
        // slug length limit (200 chars)
        $slug = mb_substr($slug, 0, 200);

        // Duplicate check
        $exists = DB::fetchOne(
            'SELECT id FROM posts WHERE type = ? AND lang = ? AND slug = ? AND id != ? LIMIT 1',
            [$type, $lang, $slug, $postId]
        );
        if ($exists) {
            $slug .= '-' . time();
        }
        return $slug;
    }

    /**
     * resolve and bake the featured image once at save time.
     * Priority: explicit -> first image in the body -> site logo (SITE_LOGO) -> NULL.
     * Normalized to the post-publish production URL (/assets/articles/...) (does not save a draft URL).
     */
    private static function resolveFeaturedImage(string $explicit, string $markdown): ?string
    {
        // 1) Explicit (an uploaded image set in the drawer)
        //    take the trailing filename from any form (flat/shard/management-preview URL),
        //           and normalize to the sharded canonical URL (/assets/articles/{ab}/{cd}/{file}).
        if ($explicit !== '') {
            // Management-preview URL (/{ADMIN_PATH}/assets/file) / public URL (/assets/articles/{ab}/{cd}/file)
            // take the trailing filename from either. ADMIN_PATH is arbitrary, so do not depend on a literal.
            if (preg_match('#/assets/(?:articles/)?(?:[\w.-]+/)*([\w.-]+)$#', $explicit, $m)) {
                return Media::articleUrl($m[1]);
            }
            if (preg_match('#^https?://#', $explicit)) {
                return $explicit; // External URLs (site logo CDN, etc.) are kept as-is
            }
        }
        // 2) First image in the body (scanned once at save time; never scanned at runtime afterward)
        //    extract and normalize the trailing filename so sharded URLs (containing slashes) are also picked up.
        if (preg_match('#!\[[^\]]*\]\(/assets/articles/(?:[\w.-]+/)*([\w.-]+)\)#', $markdown, $m)) {
            return Media::articleUrl($m[1]);
        }
        // 3) Site logo (optional setting. NULL if unset)
        $logo = class_exists('Env') ? trim((string)Env::get('SITE_LOGO', '')) : '';
        return $logo !== '' ? $logo : null;
    }

    private static function buildTagsCache(array $tagIds, string $type): ?string
    {
        if (empty($tagIds)) {
            return null;
        }
        $placeholders = implode(',', array_fill(0, count($tagIds), '?'));
        $tags = DB::fetchAll(
            "SELECT id, name, slug FROM tags WHERE id IN ({$placeholders}) AND type = ?",
            array_merge($tagIds, [$type])
        );
        return empty($tags) ? null : json_encode($tags, JSON_UNESCAPED_UNICODE);
    }

    /**
     * just physically move media files, treating attachments as the source of truth.
     * The body (content_markdown / content_html) always keeps the production URL (/assets/articles/...)
     * and is never rewritten on publish/unpublish (an iron rule for keeping the FPC stable).
     * Display of draft images swaps the URL only on the management preview (/preview) side.
     * Atomic rename within the same partition. Already-moved files are skipped (idempotent).
     */
    private static function moveMedia(int $postId, string $newStatus): void
    {
        $attachments = DB::fetchAll('SELECT filename FROM attachments WHERE post_id = ?', [$postId]);
        if (empty($attachments)) {
            return;
        }

        foreach ($attachments as $att) {
            $fn = basename($att['filename']); // Path-traversal defense
            // save/serve paths are derived from shardPath(). pre-ensure the destination shard intermediate.
            if ($newStatus === 'published') {
                $src = Media::uploadPath($fn);
                $dst = Media::articlePath($fn);
            } else {
                // Unpublish (published -> draft/scheduled): reverse-move from the public area to the isolated area
                $src = Media::articlePath($fn);
                $dst = Media::uploadPath($fn);
            }
            if (is_file($src) && !file_exists($dst)) {
                Media::ensureDirForFile($dst);
                @rename($src, $dst);
            }
        }
    }

    /**
     * re-encode the image with GD and strip all metadata including EXIF, saving to $dest.
     * Only pixel data is written out, so neither GPS location nor an image-spoofing payload remains.
     * JPEG at quality 90, PNG/WebP nearly lossless, GIF re-saved.
     * public because avatar upload (AdminUserController) shares it too.
     */
    /** WebP twins are named after their original: "{hash}.jpg" -> "{hash}.jpg.webp". */
    public static function isWebpTwin(string $filename): bool
    {
        return (bool)preg_match('/\.(jpe?g|png)\.webp$/i', $filename);
    }

    /**
     * Write {file}.webp next to a re-encoded JPEG/PNG. Skipped when GD has no WebP support or
     * IMAGE_WEBP=off, and when the WebP would not be smaller.
     *
     * @return string|null the twin's filename
     */
    private static function makeWebpTwin(string $path, string $mime): ?string
    {
        if (!in_array($mime, ['image/jpeg', 'image/png'], true) || !function_exists('imagewebp')
            || strtolower(Env::get('IMAGE_WEBP', 'auto')) === 'off') {
            return null;
        }
        $img = $mime === 'image/jpeg' ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if ($img === false) {
            return null;
        }
        if ($mime === 'image/png') {
            imagepalettetotruecolor($img);
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        $twin = basename($path) . '.webp';
        $out  = Media::uploadPath($twin);
        $ok   = @imagewebp($img, $out, 82);
        imagedestroy($img);
        if (!$ok || !is_file($out)) {
            return null;
        }
        if (filesize($out) >= filesize($path)) {
            @unlink($out);
            return null;
        }
        return $twin;
    }

    public static function reencodeImage(string $src, string $dest, string $mime): void
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($src),
            'image/png'  => @imagecreatefrompng($src),
            'image/gif'  => @imagecreatefromgif($src),
            'image/webp' => @imagecreatefromwebp($src),
            default      => false,
        };
        if ($img === false) {
            throw new RuntimeException(HbpLocale::t('err_image_load_failed', 'Failed to load the image (corrupted or unsupported format).'));
        }

        // Preserve transparency (PNG/GIF/WebP)
        if (in_array($mime, ['image/png', 'image/gif', 'image/webp'], true)) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($img, $dest, 90),
            'image/png'  => imagepng($img, $dest, 6),
            'image/gif'  => imagegif($img, $dest),
            'image/webp' => imagewebp($img, $dest, 90),
            default      => false,
        };
        imagedestroy($img);

        if (!$ok) {
            throw new RuntimeException(HbpLocale::t('err_image_reencode_failed', 'Failed to re-encode the image.'));
        }
    }

    /**
     * auto-generate an empty draft row and return its id.
     * Called during a new creation when there is not yet a posts row, e.g. on the first image upload.
     * status='draft', title='', content_markdown='', published_at=NULL, user_id=the logged-in user.
     * One of the first-save triggers that maintains and extends (new content always starts as draft).
     */
    public static function createEmptyDraft(string $type = 'post', string $lang = ''): int
    {
        $type = in_array($type, ['post', 'page'], true) ? $type : 'post';
        if (!preg_match('/^[a-z]{2,5}$/', $lang)) {
            $lang = Env::get('SITE_DEFAULT_LANG', 'en');
        }
        $userId = Auth::id() === 0 ? null : Auth::id();
        $slug   = 'draft-' . bin2hex(random_bytes(6)); // Unique placeholder (may be regenerated at save time)
        $now    = gmdate('Y-m-d H:i:s');

        DB::execute(
            "INSERT INTO posts
             (type, lang, slug, title, content_markdown, content_html, status,
              user_id, published_at, comment_count, created_at, updated_at)
             VALUES (?,?,?,'','','','draft',?,NULL,0,?,?)",
            [$type, $lang, $slug, $userId, $now, $now]
        );
        return (int)DB::lastInsertId();
    }

    /** Media upload handling (if there is no post_id, auto-generate an empty draft and return its id) */
    public static function uploadMedia(int $postId, array $file): array
    {
        // Surface PHP's own upload errors first: without this, a file rejected for exceeding
        // upload_max_filesize arrives with an empty tmp_name and is reported as "Invalid upload",
        // which tells the operator nothing about what actually went wrong.
        $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
                throw new RuntimeException(HbpLocale::t(
                    'err_upload_too_large',
                    'The file is larger than this server allows (upload_max_filesize = :limit).',
                    ['limit' => (string)ini_get('upload_max_filesize')]
                ));
            }
            throw new RuntimeException(HbpLocale::t('err_upload_failed', 'File upload failed.'));
        }

        // Accept only genuine HTTP uploads (do not pass local-path spoofing to finfo/GD/move; defense in depth)
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException(HbpLocale::t('err_invalid_upload', 'Invalid upload.'));
        }

        $imageMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $imageExt  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $allowedMime = array_merge($imageMime, ['application/pdf', 'text/csv']);
        $allowedExt  = array_merge($imageExt, ['pdf', 'csv']);

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        // MIME validation via finfo (anti extension spoofing)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($file['tmp_name']);

        if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true)) {
            throw new RuntimeException(HbpLocale::t('err_file_type_not_allowed', 'This file type is not allowed.'));
        }

        $isImage = in_array($mime, $imageMime, true);

        // if post_id is undetermined (during new creation), auto-generate an empty draft and link to its id.
        //       Even if an id already exists, generate similarly if the row does not exist (prevents orphan attachments).
        if ($postId <= 0
            || !DB::fetchOne('SELECT id FROM posts WHERE id = ? LIMIT 1', [$postId])) {
            $postId = self::createEmptyDraft();
        }

        // image upload always strips all metadata including EXIF (GPS location, device, capture time)
        // before saving via GD re-encode. In environments without GD, image upload is rejected
        // (do not create a path that cannot guarantee removal = method A, strict).
        if ($isImage && !extension_loaded('gd')) {
            throw new RuntimeException(
                HbpLocale::t('err_gd_required_image', 'Image upload is unavailable because the PHP GD extension is disabled (EXIF/metadata removal cannot be guaranteed). Please ask your server administrator to enable gd.')
            );
        }

        // Hashed filename (bin2hex(random_bytes(16)))
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        // distributed across {ab}/{cd}/ shards. pre-ensure the destination (parent + shard intermediate) recursively.
        //        The order is "ensure directory -> file save succeeds -> DB record" (do not create orphan attachments on failure).
        $dest     = Media::uploadPath($filename);
        Media::ensureDirForFile($dest);

        if ($isImage) {
            // Re-write only the pixels via GD -> erase all metadata including EXIF + neutralize image-spoofing payloads
            self::reencodeImage($file['tmp_name'], $dest, $mime);
            @unlink($file['tmp_name']);
        } else {
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                throw new RuntimeException(HbpLocale::t('err_upload_failed', 'File upload failed.'));
            }
        }

        DB::execute(
            'INSERT INTO attachments (post_id, filename, original_name, mime_type, file_size, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())',
            [$postId, $filename, mb_substr(basename((string)$file['name']), 0, 255), $mime, (int)$file['size']]
        );

        // WebP twin ({file}.webp) for JPEG/PNG. It is an attachments row of its own, so publishing,
        // unpublishing, deletion and media:gc move or remove it exactly like the original.
        $webp = $isImage ? self::makeWebpTwin($dest, $mime) : null;
        if ($webp !== null) {
            DB::execute(
                'INSERT INTO attachments (post_id, filename, original_name, mime_type, file_size, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())',
                [$postId, $webp, mb_substr(basename((string)$file['name']) . '.webp', 0, 255), 'image/webp', (int)@filesize(Media::uploadPath($webp))]
            );
        }

        // always store the production URL in the body (because the body is not rewritten on publish).
        // Images added to an already-published post are immediately moved physically to public/ too.
        $post = DB::fetchOne('SELECT status FROM posts WHERE id = ? LIMIT 1', [$postId]);
        if ($post && $post['status'] === 'published') {
            $articleDest = Media::articlePath($filename);
            Media::ensureDirForFile($articleDest);     // ensure the destination shard intermediate
            @rename($dest, $articleDest);
            if ($webp !== null) {
                Media::ensureDirForFile(Media::articlePath($webp));
                @rename(Media::uploadPath($webp), Media::articlePath($webp));
            }
        }
        // Even when unpublished, return the production URL (sharded). The preview (/preview)
        // auto-swaps only files remaining in storage/upload to the management delivery URL, so it does not 404.
        // return post_id (the front end adopts the auto-generated draft thereafter).
        return ['url' => Media::articleUrl($filename), 'filename' => $filename, 'post_id' => $postId];
    }

    /** Resolve a submitted layout ID against the active theme. NULL = the theme's default layout. */
    private static function validateLayout(string $id): ?string
    {
        if ($id === '' || !class_exists('ThemeManager') || $id === ThemeManager::DEFAULT_LAYOUT) {
            return null;
        }
        return isset(ThemeManager::layouts()[$id]) ? $id : null;
    }

    /**
     * Resolve a submitted page_template against the enabled page-template plugins.
     * Returns null for anything not offered by an active plugin, so an unknown key is dropped
     * rather than stored (posts.page_template is VARCHAR(50); an unresolvable key 404s the page).
     */
    private static function validatePageTemplate(string $key): ?string
    {
        if ($key === '' || !class_exists('PluginManager')) {
            return null;
        }
        foreach (PluginManager::getList() as $plugin) {
            if (!empty($plugin['active'])
                && !empty($plugin['page_template'])
                && $plugin['page_template'] === $key) {
                return $key;
            }
        }
        return null;
    }
}
