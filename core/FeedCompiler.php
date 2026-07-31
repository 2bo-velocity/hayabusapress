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
 * Physically generate Atom 1.0 / RSS 2.0 feeds per language (Section 16.6)
 *
 * Fully follows the precompile philosophy:
 * - Generate the XML once on post publish/unpublish/delete and bake it into a physical file
 * - At view time it is effectively readfile (zero DB, zero runtime cost)
 * - Rides on the same generation trigger as the sitemap
 *
 * File placement:
 *   public/feed.atom.xml            Atom for the default language
 *   public/feed.rss.xml             RSS 2.0 for the default language
 *   public/{lang}/feed.atom.xml    Atom for non-default languages
 *   public/{lang}/feed.rss.xml     RSS 2.0 for non-default languages
 */
class FeedCompiler
{
    private const MAX_ITEM_COUNT = 20;
    private const MIN_ITEM_COUNT = 5;
    private const EXCERPT_LENGTH = 200;

    /**
     * Generate the feed for the given language.
     * Does nothing when FEED_ENABLED=false.
     *
     * @param string $lang language code (e.g. 'ja', 'en')
     */
    public static function compile(string $lang): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $format      = class_exists('Env') ? Env::get('FEED_FORMAT', 'both')   : ($_ENV['FEED_FORMAT'] ?? 'both');
        $itemCount   = self::clampItemCount(
            class_exists('Env') ? Env::int('FEED_ITEM_COUNT', self::MAX_ITEM_COUNT) : (int)($_ENV['FEED_ITEM_COUNT'] ?? self::MAX_ITEM_COUNT)
        );
        $fullContent = class_exists('Env') ? Env::bool('FEED_FULL_CONTENT', false) : filter_var($_ENV['FEED_FULL_CONTENT'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
        $siteUrl     = rtrim(class_exists('Env') ? Env::get('SITE_URL', '') : ($_ENV['SITE_URL'] ?? ''), '/');
        $siteName    = class_exists('Env') ? Env::get('SITE_NAME', 'HayabusaPress') : ($_ENV['SITE_NAME'] ?? 'HayabusaPress');
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en');

        // fetch and sort by published_at as the truth of posting time (hits idx_posts_lang_list directly)
        $posts = DB::fetchAll(
            "SELECT title, slug, meta_description, content_html, updated_at, published_at
               FROM posts
              WHERE type = 'post' AND lang = ? AND status = 'published'
           ORDER BY published_at DESC
              LIMIT ?",
            [$lang, $itemCount]
        );

        $feedBase = ($lang === $defaultLang) ? '' : "/{$lang}";
        $feedDir  = PUBLIC_PATH . $feedBase;

        if ($feedBase !== '' && !is_dir($feedDir)) {
            mkdir($feedDir, 0755, true);
        }

        $updated = !empty($posts)
            ? self::toRfc3339($posts[0]['updated_at'])
            : self::toRfc3339(date('Y-m-d H:i:s'));

        if (in_array($format, ['both', 'atom'], true)) {
            $atomXml = self::buildAtom($posts, $lang, $siteUrl, $siteName, $feedBase, $updated, $fullContent);
            file_put_contents("{$feedDir}/feed.atom.xml", $atomXml, LOCK_EX);
        }

        if (in_array($format, ['both', 'rss'], true)) {
            $rssXml = self::buildRss($posts, $lang, $siteUrl, $siteName, $feedBase, $updated, $fullContent);
            file_put_contents("{$feedDir}/feed.rss.xml", $rssXml, LOCK_EX);
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
        string $feedBase,
        string $updated,
        bool   $fullContent
    ): string {
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : 'ja';
        $selfHref    = htmlspecialchars("{$siteUrl}{$feedBase}/feed.atom.xml", ENT_XML1);
        $homeHref    = htmlspecialchars("{$siteUrl}{$feedBase}/", ENT_XML1);
        $siteTitle   = htmlspecialchars($siteName, ENT_XML1);
        $homeId      = htmlspecialchars("{$siteUrl}/", ENT_XML1);

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
        string $feedBase,
        string $updated,
        bool   $fullContent
    ): string {
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : 'ja';
        $selfHref    = htmlspecialchars("{$siteUrl}{$feedBase}/feed.rss.xml", ENT_XML1);
        $homeLink    = htmlspecialchars("{$siteUrl}{$feedBase}/", ENT_XML1);
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
