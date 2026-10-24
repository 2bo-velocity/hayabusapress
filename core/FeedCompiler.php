<?php
/**
 * HayabusaPress - Atom 1.0 / RSS 2.0 feed generator (per language).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Physically generate Atom 1.0 / RSS 2.0 / JSON Feed 1.1 per language (Section 16.6)
 *
 * Fully follows the precompile philosophy:
 * - Generate the files once on post publish/unpublish/delete and bake them to disk
 * - At view time it is effectively readfile (zero DB, zero runtime cost)
 * - Rides on the same generation trigger as the sitemap
 *
 * File placement:
 *   public/feed.{atom.xml,rss.xml,json}            the default language
 *   public/{lang}/feed.{atom.xml,rss.xml,json}     non-default languages
 *   public/feeds/{lang}/category/{slug}.{atom.xml,rss.xml,json}
 *                                                  categories with feed_enabled (every language,
 *                                                  under feeds/ so no directory shadows the
 *                                                  /post/category/{slug} route)
 *
 * Which formats exist is FEED_FORMAT: atom | rss | json | both (= atom,rss) | all, or a comma list.
 * JSON Feed items carry an "_hbp" extension with the post's social-post text and hashtags, for
 * automations that post new entries elsewhere.
 */
class FeedCompiler
{
    private const MAX_ITEM_COUNT = 20;
    private const MIN_ITEM_COUNT = 5;
    private const EXCERPT_LENGTH = 200;

    /** Where category feeds live, below PUBLIC_PATH. */
    public const CATEGORY_DIR = '/feeds';

    /** Files per format: [extension, content type]. */
    public const FORMATS = [
        'atom' => ['atom.xml', 'application/atom+xml'],
        'rss'  => ['rss.xml',  'application/rss+xml'],
        'json' => ['json',     'application/feed+json'],
    ];

    /** The formats FEED_FORMAT asks for. */
    public static function formats(): array
    {
        $raw = strtolower(trim(class_exists('Env') ? Env::get('FEED_FORMAT', 'both') : ($_ENV['FEED_FORMAT'] ?? 'both')));
        $map = ['both' => ['atom', 'rss'], 'all' => ['atom', 'rss', 'json']];
        $out = [];
        foreach (preg_split('/\s*,\s*/', $raw) ?: [] as $f) {
            foreach ($map[$f] ?? [$f] as $one) {
                if (isset(self::FORMATS[$one]) && !in_array($one, $out, true)) {
                    $out[] = $one;
                }
            }
        }
        return $out ?: ['atom', 'rss'];
    }

    /** Path of a category feed file. */
    public static function categoryFile(string $lang, string $slug, string $format): string
    {
        return PUBLIC_PATH . self::CATEGORY_DIR . "/{$lang}/category/{$slug}." . self::FORMATS[$format][0];
    }

    /**
     * Generate the feeds for the given language, and for each of its categories that has
     * feed_enabled. Category feeds of categories no longer enabled are removed.
     * Does nothing when FEED_ENABLED=false.
     *
     * @param string $lang language code (e.g. 'ja', 'en')
     */
    public static function compile(string $lang): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $itemCount   = self::clampItemCount(
            class_exists('Env') ? Env::int('FEED_ITEM_COUNT', self::MAX_ITEM_COUNT) : (int)($_ENV['FEED_ITEM_COUNT'] ?? self::MAX_ITEM_COUNT)
        );
        $siteUrl     = rtrim(class_exists('Env') ? Env::get('SITE_URL', '') : ($_ENV['SITE_URL'] ?? ''), '/');
        $siteName    = class_exists('Env') ? Env::get('SITE_NAME', 'HayabusaPress') : ($_ENV['SITE_NAME'] ?? 'HayabusaPress');
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en');

        $feedBase = ($lang === $defaultLang) ? '' : "/{$lang}";
        $feedDir  = PUBLIC_PATH . $feedBase;
        if ($feedBase !== '' && !is_dir($feedDir)) {
            mkdir($feedDir, 0755, true);
        }

        // Categories of this language: slug/hashtags for the JSON extension, and which get a feed
        $cats = [];
        foreach (DB::fetchAll("SELECT id, slug, name, hashtags, feed_enabled FROM categories WHERE type = 'post' AND lang = ?", [$lang]) as $c) {
            $cats[(int)$c['id']] = $c;
        }

        $cols = 'id, title, slug, category_id, meta_description, meta_description_auto, share_text, share_text_auto,
                 featured_image, content_html, updated_at, published_at';

        // fetch and sort by published_at as the truth of posting time (hits idx_posts_lang_list directly)
        $posts = DB::fetchAll(
            "SELECT {$cols}
               FROM posts
              WHERE type = 'post' AND lang = ? AND status = 'published'
           ORDER BY published_at DESC
              LIMIT ?",
            [$lang, $itemCount]
        );
        self::writeAll(
            $posts, $cats, $lang, $siteUrl, $siteName,
            "{$siteUrl}{$feedBase}/", "{$siteUrl}{$feedBase}/feed", "{$feedDir}/feed",
            "{$siteUrl}/"   // the Atom id these feeds have always had; changing it looks like a new feed
        );

        $keep = [];
        foreach ($cats as $id => $c) {
            if (empty($c['feed_enabled'])) {
                continue;
            }
            $catPosts = DB::fetchAll(
                "SELECT {$cols}
                   FROM posts
                  WHERE type = 'post' AND lang = ? AND status = 'published' AND category_id = ?
               ORDER BY published_at DESC
                  LIMIT ?",
                [$lang, $id, $itemCount]
            );
            $base = PUBLIC_PATH . self::CATEGORY_DIR . "/{$lang}/category/{$c['slug']}";
            if (!is_dir(dirname($base))) {
                mkdir(dirname($base), 0755, true);
            }
            self::writeAll(
                $catPosts, $cats, $lang, $siteUrl, $siteName . ' - ' . $c['name'],
                "{$siteUrl}{$feedBase}/post/category/{$c['slug']}",
                $siteUrl . self::CATEGORY_DIR . "/{$lang}/category/{$c['slug']}",
                $base,
                "{$siteUrl}{$feedBase}/post/category/{$c['slug']}"
            );
            $keep[(string)$c['slug']] = true;
        }

        // Remove feeds of categories that were disabled, renamed or deleted
        foreach (glob(PUBLIC_PATH . self::CATEGORY_DIR . "/{$lang}/category/*") ?: [] as $f) {
            $slug = preg_replace('/\.(atom\.xml|rss\.xml|json)$/', '', basename($f));
            if (!isset($keep[$slug])) {
                @unlink($f);
            }
        }
    }

    /**
     * Write every configured format of one feed.
     *
     * @param string $selfBase public URL of the feed without the extension
     * @param string $fileBase path of the feed file without the extension
     */
    private static function writeAll(
        array $posts, array $cats, string $lang, string $siteUrl, string $title,
        string $homeUrl, string $selfBase, string $fileBase, string $feedId
    ): void {
        $fullContent = class_exists('Env') ? Env::bool('FEED_FULL_CONTENT', false) : filter_var($_ENV['FEED_FULL_CONTENT'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $updated = !empty($posts)
            ? self::toRfc3339($posts[0]['updated_at'])
            : self::toRfc3339(date('Y-m-d H:i:s'));

        foreach (self::formats() as $format) {
            $ext  = self::FORMATS[$format][0];
            $self = "{$selfBase}.{$ext}";
            $body = match ($format) {
                'atom' => self::buildAtom($posts, $lang, $siteUrl, $title, $homeUrl, $self, $feedId, $updated, $fullContent),
                'rss'  => self::buildRss($posts, $lang, $siteUrl, $title, $homeUrl, $self, $updated, $fullContent),
                'json' => self::buildJson($posts, $cats, $lang, $siteUrl, $title, $homeUrl, $self, $fullContent),
            };
            file_put_contents("{$fileBase}.{$ext}", $body, LOCK_EX);
        }
    }

    /**
     * Regenerate the feeds for all available languages (called from feed:rebuild / system:init).
     */
    public static function rebuildAll(): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $locales = class_exists('HbpLocale')
            ? HbpLocale::getAvailableContentLocales()
            : [(class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : 'ja') => 'default'];

        foreach (array_keys($locales) as $lang) {
            self::compile($lang);
        }
    }

    // ----------------------------------------------------------------
    //  Private: Atom 1.0 generation
    // ----------------------------------------------------------------

    private static function buildAtom(
        array  $posts,
        string $lang,
        string $siteUrl,
        string $siteName,
        string $homeUrl,
        string $selfUrl,
        string $feedId,
        string $updated,
        bool   $fullContent
    ): string {
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : 'ja';
        $selfHref    = htmlspecialchars($selfUrl, ENT_XML1);
        $homeHref    = htmlspecialchars($homeUrl, ENT_XML1);
        $siteTitle   = htmlspecialchars($siteName, ENT_XML1);
        $homeId      = htmlspecialchars($feedId, ENT_XML1);

        $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<feed xmlns=\"http://www.w3.org/2005/Atom\" xml:lang=\"{$lang}\">\n";
        $xml .= "  <title>{$siteTitle}</title>\n";
        $xml .= "  <link href=\"{$homeHref}\" />\n";
        $xml .= "  <link rel=\"self\" type=\"application/atom+xml\" href=\"{$selfHref}\" />\n";
        $xml .= "  <updated>{$updated}</updated>\n";
        $xml .= "  <id>{$homeId}</id>\n";

        foreach ($posts as $post) {
            $postUrl = self::buildPostUrl($siteUrl, $post['slug'], $lang, $defaultLang);
            $title   = htmlspecialchars($post['title'], ENT_XML1);
            $link    = htmlspecialchars($postUrl, ENT_XML1);
            $id      = htmlspecialchars($postUrl, ENT_XML1);
            $upd     = self::toRfc3339($post['published_at'] ?? $post['updated_at'] ?? '');

            $xml .= "  <entry>\n";
            $xml .= "    <title>{$title}</title>\n";
            $xml .= "    <link href=\"{$link}\" />\n";
            $xml .= "    <id>{$id}</id>\n";
            $xml .= "    <updated>{$upd}</updated>\n";

            if ($fullContent) {
                // Wrap sanitized full HTML in CDATA
                $content = '<![CDATA[' . ($post['content_html'] ?? '') . ']]>';
                $xml .= "    <content type=\"html\">{$content}</content>\n";
            } else {
                $summary = htmlspecialchars(self::buildExcerpt($post), ENT_XML1);
                $xml .= "    <summary>{$summary}</summary>\n";
            }

            $xml .= "  </entry>\n";
        }

        $xml .= "</feed>\n";

        return $xml;
    }

    // ----------------------------------------------------------------
    //  Private: RSS 2.0 generation
    // ----------------------------------------------------------------

    private static function buildRss(
        array  $posts,
        string $lang,
        string $siteUrl,
        string $siteName,
        string $homeUrl,
        string $selfUrl,
        string $updated,
        bool   $fullContent
    ): string {
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : 'ja';
        $selfHref    = htmlspecialchars($selfUrl, ENT_XML1);
        $homeLink    = htmlspecialchars($homeUrl, ENT_XML1);
        $siteTitle   = htmlspecialchars($siteName, ENT_XML1);
        $pubDate     = self::toRfc822($updated);

        $xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\">\n";
        $xml .= "  <channel>\n";
        $xml .= "    <title>{$siteTitle}</title>\n";
        $xml .= "    <link>{$homeLink}</link>\n";
        $xml .= "    <description>{$siteTitle}</description>\n";
        $xml .= "    <language>{$lang}</language>\n";
        $xml .= "    <lastBuildDate>{$pubDate}</lastBuildDate>\n";
        $xml .= "    <atom:link href=\"{$selfHref}\" rel=\"self\" type=\"application/rss+xml\" />\n";

        foreach ($posts as $post) {
            $postUrl = self::buildPostUrl($siteUrl, $post['slug'], $lang, $defaultLang);
            $title   = htmlspecialchars($post['title'], ENT_XML1);
            $link    = htmlspecialchars($postUrl, ENT_XML1);
            $guid    = htmlspecialchars($postUrl, ENT_XML1);
            $pubDate = self::toRfc822(self::toRfc3339($post['published_at'] ?? $post['updated_at'] ?? ''));

            $xml .= "    <item>\n";
            $xml .= "      <title>{$title}</title>\n";
            $xml .= "      <link>{$link}</link>\n";
            $xml .= "      <guid isPermaLink=\"true\">{$guid}</guid>\n";
            $xml .= "      <pubDate>{$pubDate}</pubDate>\n";

            if ($fullContent) {
                $content = '<![CDATA[' . ($post['content_html'] ?? '') . ']]>';
                $xml .= "      <description>{$content}</description>\n";
            } else {
                $desc = htmlspecialchars(self::buildExcerpt($post), ENT_XML1);
                $xml .= "      <description>{$desc}</description>\n";
            }

            $xml .= "    </item>\n";
        }

        $xml .= "  </channel>\n";
        $xml .= "</rss>\n";

        return $xml;
    }

    // ----------------------------------------------------------------
    //  Private: JSON Feed 1.1 generation (https://www.jsonfeed.org/version/1.1/)
    // ----------------------------------------------------------------

    private static function buildJson(
        array  $posts,
        array  $cats,
        string $lang,
        string $siteUrl,
        string $title,
        string $homeUrl,
        string $selfUrl,
        bool   $fullContent
    ): string {
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : 'ja';
        $items = [];
        foreach ($posts as $post) {
            $url  = self::buildPostUrl($siteUrl, $post['slug'], $lang, $defaultLang);
            $item = [
                'id'             => $url,
                'url'            => $url,
                'title'          => (string)$post['title'],
                'summary'        => self::buildExcerpt($post),
                'date_published' => self::toRfc3339((string)($post['published_at'] ?? $post['updated_at'] ?? '')),
                'date_modified'  => self::toRfc3339((string)($post['updated_at'] ?? '')),
            ];
            if ($fullContent) {
                $item['content_html'] = (string)($post['content_html'] ?? '');
            } else {
                $item['content_text'] = $item['summary'];
            }
            $img = (string)($post['featured_image'] ?? '');
            if ($img !== '') {
                $item['image'] = preg_match('#^https?://#', $img) ? $img : $siteUrl . '/' . ltrim($img, '/');
            }
            $cat   = $cats[(int)($post['category_id'] ?? 0)] ?? null;
            $share = (string)($post['share_text'] ?? '') !== '' ? (string)$post['share_text'] : (string)($post['share_text_auto'] ?? '');
            $item['_hbp'] = [
                'about'      => 'https://hayabusapress.org/',
                'share_text' => $share,
                'category'   => $cat !== null ? (string)$cat['slug'] : null,
                'hashtags'   => $cat !== null && !empty($cat['hashtags'])
                    ? array_map(static fn($t) => ltrim($t, '#'), preg_split('/[\s,]+/u', trim((string)$cat['hashtags']), -1, PREG_SPLIT_NO_EMPTY) ?: [])
                    : [],
            ];
            $items[] = $item;
        }

        $feed = [
            'version'       => 'https://jsonfeed.org/version/1.1',
            'title'         => $title,
            'home_page_url' => $homeUrl,
            'feed_url'      => $selfUrl,
            'language'      => $lang,
            'items'         => $items,
        ];
        return json_encode($feed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    // ----------------------------------------------------------------
    //  Private: helpers
    // ----------------------------------------------------------------

    private static function isEnabled(): bool
    {
        return class_exists('Env')
            ? Env::bool('FEED_ENABLED', true)
            : filter_var($_ENV['FEED_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
    }

    private static function clampItemCount(int $n): int
    {
        return max(self::MIN_ITEM_COUNT, min(self::MAX_ITEM_COUNT, $n));
    }

    /** Get the post excerpt (meta_description -> plain text of the first N chars of the body) */
    private static function buildExcerpt(array $post): string
    {
        if (!empty($post['meta_description'])) {
            return $post['meta_description'];
        }
        if (!empty($post['meta_description_auto'])) {
            return $post['meta_description_auto'];
        }

        $plain = strip_tags($post['content_html'] ?? '');
        $plain = html_entity_decode($plain, ENT_QUOTES, 'UTF-8');
        $plain = preg_replace('/\s+/', ' ', trim($plain));

        if (mb_strlen($plain) > self::EXCERPT_LENGTH) {
            $plain = mb_substr($plain, 0, self::EXCERPT_LENGTH) . '…';
        }

        return $plain;
    }

    /** Build the public URL of a post */
    private static function buildPostUrl(string $siteUrl, string $slug, string $lang, string $defaultLang): string
    {
        $prefix = ($lang === $defaultLang) ? '' : "/{$lang}";
        return "{$siteUrl}{$prefix}/post/{$slug}";
    }

    /**
     * Convert a DB datetime string (YYYY-MM-DD HH:MM:SS) to RFC3339 format.
     * Uses APP_TIMEZONE for the timezone (assumes the DB stores UTC).
     */
    private static function toRfc3339(string $datetime): string
    {
        $tz = class_exists('Env') ? Env::get('APP_TIMEZONE', 'UTC') : ($_ENV['APP_TIMEZONE'] ?? 'UTC');

        try {
            $dt = new DateTimeImmutable($datetime, new DateTimeZone('UTC'));
            $dt = $dt->setTimezone(new DateTimeZone($tz));
        } catch (Throwable) {
            $dt = new DateTimeImmutable('now', new DateTimeZone($tz));
        }

        return $dt->format(DateTimeInterface::RFC3339);
    }

    /**
     * Convert an RFC3339 string to RFC 822 format (for RSS 2.0).
     */
    private static function toRfc822(string $rfc3339): string
    {
        try {
            $dt = new DateTimeImmutable($rfc3339);
        } catch (Throwable) {
            $dt = new DateTimeImmutable('now');
        }

        return $dt->format(DateTimeInterface::RFC2822);
    }
}
