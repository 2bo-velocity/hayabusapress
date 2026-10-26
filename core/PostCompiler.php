<?php
/**
 * HayabusaPress - Re-rendering of stored posts and the raw-HTML trust they carry.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Whether a post's raw HTML (embeds) survives the sanitizer is decided by the person who last saved
 * its body, and recorded on the post (posts.raw_html_allowed / content_saved_by). Re-rendering uses
 * that record, never anyone's current role, so the editor's save and posts:recompile agree.
 *
 * The record changes only when the body changes, or when an operator revokes it on purpose with
 * reassess() (posts:recompile --reassess, or a role downgrade in the admin).
 */
final class PostCompiler
{
    /** content_saved_by value that stands for the Super User (who has no users row). */
    public const SUPER_USER = 0;

    /** @var array<string,bool>|null role => holds post:raw_html */
    private static ?array $roleRaw = null;

    /** The current session's saver id for content_saved_by. */
    public static function currentSaver(): int
    {
        return Auth::isSuperUser() ? self::SUPER_USER : Auth::id();
    }

    /** Whether the current session may keep raw HTML in what it saves. */
    public static function currentMayUseRaw(): bool
    {
        return Auth::isSuperUser() || Gate::can('post:raw_html');
    }

    public static function roleAllowsRaw(string $role): bool
    {
        if (self::$roleRaw === null) {
            self::$roleRaw = [];
            foreach ((require CORE_PATH . '/permissions.php') as $r => $perms) {
                self::$roleRaw[(string)$r] = !empty($perms['post:raw_html']);
            }
        }
        return self::$roleRaw[$role] ?? false;
    }

    /**
     * The trust a save should record. An unchanged body keeps what was recorded, so someone without
     * post:raw_html can fix a category or a typo in the title without stripping an embed. A row that
     * has never been recorded (content_saved_by NULL) is treated as a changed body.
     *
     * @param array|null $previous the stored row (content_markdown, raw_html_allowed, content_saved_by), or null for a new post
     * @return array{0:bool,1:int} [raw_html_allowed, content_saved_by]
     */
    public static function trustForSave(?array $previous, string $markdown): array
    {
        if ($previous !== null
            && $previous['content_saved_by'] !== null
            && self::lf((string)$previous['content_markdown']) === self::lf($markdown)) {
            return [!empty($previous['raw_html_allowed']), (int)$previous['content_saved_by']];
        }
        return [self::currentMayUseRaw(), self::currentSaver()];
    }

    /** Render a stored post row with the trust recorded on it. */
    public static function render(array $post): string
    {
        require_once CORE_PATH . '/MarkdownCompiler.php';
        require_once CORE_PATH . '/HtmlSanitizer.php';
        return MarkdownCompiler::render((string)$post['content_markdown'], !empty($post['raw_html_allowed']), [
            'toc'       => !empty($post['show_toc']),
            'sponsored' => !empty($post['is_sponsored']),
            'lang'      => (string)$post['lang'],
        ]);
    }

    /**
     * Re-render posts with their recorded trust (and recount their characters and reading time).
     *
     * @param int[]|null $ids null = every post
     * @return int number of posts re-rendered
     */
    public static function recompile(?array $ids = null): int
    {
        $sql = 'SELECT id, lang, content_markdown, show_toc, is_sponsored, raw_html_allowed FROM posts';
        $params = [];
        if ($ids !== null) {
            if ($ids === []) {
                return 0;
            }
            $ids    = array_values(array_unique(array_map('intval', $ids)));
            $sql   .= ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = $ids;
        }
        require_once CORE_PATH . '/SeoCompiler.php';
        $count = 0;
        foreach (DB::fetchAll($sql, $params) as $post) {
            $html = self::render($post);
            $auto = SeoCompiler::excerpt($html);
            [$chars, $minutes] = self::textStats($html);
            DB::execute(
                'UPDATE posts SET content_html = ?, meta_description_auto = ?, char_count = ?, reading_minutes = ? WHERE id = ?',
                [$html, $auto !== '' ? mb_substr($auto, 0, 255) : null, $chars, $minutes, (int)$post['id']]
            );
            $count++;
        }
        if ($count > 0) {
            // Each series keeps the sum of its posts' char_count, which this pass may have changed
            require_once CORE_PATH . '/Counters.php';
            require_once CORE_PATH . '/SeriesCompiler.php';
            Counters::rebuildSeries();
            SeriesCompiler::compile();
        }
        return $count;
    }

    /**
     * Character count and reading time of a rendered body (posts.char_count / reading_minutes).
     *
     * Counted from the HTML the reader sees, so Markdown syntax never inflates it. Left out: ruby
     * readings (<rt>/<rp>: a novel's length is counted on the base text), code, the baked table of
     * contents and the sponsored notice (neither is prose of the post). Whitespace is not counted.
     *
     * Reading time adds the two ways text is read: CJK characters (Japanese, Chinese and Korean are
     * not written in space-separated words) by characters per minute, and the rest by words per
     * minute. A sum rather than a switch on the CJK ratio, so a body mixing the two has no cliff
     * where one extra character halves or doubles the result. The speeds come from .env; changing
     * them takes a posts:recompile, because the result is baked on save.
     *
     * @return array{0:int,1:int} [characters, minutes]; minutes is 0 only for an empty body
     */
    public static function textStats(string $html): array
    {
        $html = preg_replace([
            '#<(rt|rp|pre|code|script|style)\b[^>]*>.*?</\1>#is',
            '#<nav class="hbp-toc"[^>]*>.*?</nav>#is',
            '#<p class="hbp-sponsored-notice">.*?</p>#is',
        ], ' ', $html) ?? $html;
        // Tags become spaces so that words in adjacent blocks do not run together
        $text  = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $chars = mb_strlen(preg_replace('/\s+/u', '', $text) ?? '');
        if ($chars === 0) {
            return [0, 0];
        }
        // CJK characters are timed one by one, together with the CJK punctuation, the middle dot and
        // the long vowel mark ー (which Unicode puts in no script). What is left is split into words.
        $cjkClass = '[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\x{3000}-\x{303F}\x{30FB}\x{30FC}\x{FF01}-\x{FF60}\x{FF70}]';
        $cjk      = preg_match_all('/' . $cjkClass . '/u', $text);
        $rest     = preg_replace('/' . $cjkClass . '/u', ' ', $text) ?? '';
        $words    = preg_match_all('/[\p{L}\p{N}]+(?:[\'’.\-][\p{L}\p{N}]+)*/u', $rest);
        $minutes  = $cjk / max(1, Env::int('READING_SPEED_CJK', 500))
                  + $words / max(1, Env::int('READING_SPEED_WORDS', 220));
        return [$chars, (int)min(32767, max(1, (int)ceil($minutes)))];
    }

    /**
     * Set raw_html_allowed again from the current role of whoever last saved each body. A saver who
     * no longer exists loses the trust. Rows never recorded fall back to the post's author.
     *
     * @param int|null $userId limit to bodies last saved by this user (0 = the Super User)
     * @return int[] ids whose trust changed (re-render them)
     */
    public static function reassess(?int $userId = null): array
    {
        $roles = [];
        foreach (DB::fetchAll('SELECT id, role FROM users') as $u) {
            $roles[(int)$u['id']] = (string)$u['role'];
        }

        $sql    = 'SELECT id, user_id, content_saved_by, raw_html_allowed FROM posts';
        $params = [];
        if ($userId !== null) {
            $sql   .= ' WHERE COALESCE(content_saved_by, user_id, 0) = ?';
            $params = [$userId];
        }

        $changed = [];
        foreach (DB::fetchAll($sql, $params) as $row) {
            $saver   = $row['content_saved_by'] !== null ? (int)$row['content_saved_by'] : (int)($row['user_id'] ?? self::SUPER_USER);
            $allowed = $saver === self::SUPER_USER || (isset($roles[$saver]) && self::roleAllowsRaw($roles[$saver]));
            if ($row['content_saved_by'] === null || (bool)$row['raw_html_allowed'] !== $allowed) {
                DB::execute(
                    'UPDATE posts SET raw_html_allowed = ?, content_saved_by = ? WHERE id = ?',
                    [$allowed ? 1 : 0, $saver, (int)$row['id']]
                );
                if ((bool)$row['raw_html_allowed'] !== $allowed) {
                    $changed[] = (int)$row['id'];
                }
            }
        }
        return $changed;
    }

    /**
     * The generated social-post text (posts.share_text_auto): title, description, category hashtags.
     *
     * @param string|null $hashtags the category's space-separated hashtags
     */
    public static function shareText(string $title, string $description, ?string $hashtags): string
    {
        require_once CORE_PATH . '/ShareText.php';
        $tags = $hashtags !== null ? (preg_split('/[\s,]+/u', trim($hashtags), -1, PREG_SPLIT_NO_EMPTY) ?: []) : [];
        return ShareText::build($title, $description, $tags);
    }

    /**
     * Regenerate share_text_auto, e.g. after a category's hashtags changed.
     *
     * @param int|null $categoryId null = every post
     */
    public static function refreshShareText(?int $categoryId = null): int
    {
        $sql = 'SELECT p.id, p.title, p.meta_description, p.meta_description_auto, c.hashtags
                  FROM posts p LEFT JOIN categories c ON c.id = p.category_id';
        $params = [];
        if ($categoryId !== null) {
            $sql   .= ' WHERE p.category_id = ?';
            $params = [$categoryId];
        }
        $n = 0;
        foreach (DB::fetchAll($sql, $params) as $r) {
            $desc = (string)($r['meta_description'] ?? '') !== '' ? (string)$r['meta_description'] : (string)($r['meta_description_auto'] ?? '');
            DB::execute('UPDATE posts SET share_text_auto = ? WHERE id = ?', [
                self::shareText((string)$r['title'], $desc, $r['hashtags'] ?? null), (int)$r['id'],
            ]);
            $n++;
        }
        return $n;
    }

    /** Display name of a saver for the editor badge. */
    public static function saverName(?int $saver): string
    {
        if ($saver === null) {
            return '';
        }
        if ($saver === self::SUPER_USER) {
            return Env::get('SUPER_USER_NAME', 'Super User');
        }
        $row = DB::fetchOne('SELECT name FROM users WHERE id = ? LIMIT 1', [$saver]);
        return (string)($row['name'] ?? ('#' . $saver));
    }

    private static function lf(string $s): string
    {
        return str_replace(["\r\n", "\r"], "\n", $s);
    }
}
