<?php
/**
 * HayabusaPress - SEO and tracking-tag compiler.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class SeoCompiler
{
    private const GLOBAL_CACHE = STORAGE_PATH . '/cache/seo_global.cache.php';
    private const HEAD_CACHE   = STORAGE_PATH . '/cache/seo_head.cache.php';

    /** Compile global SEO settings and tracking tags */
    public static function compile(array $config = []): void
    {
        // Save global settings
        if (!empty($config)) {
            $export = var_export($config, true);
            file_put_contents(
                self::GLOBAL_CACHE,
                "<?php\nreturn {$export};\n",
                LOCK_EX
            );
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate(self::GLOBAL_CACHE, true);   // see SidebarCompiler::forgetCompiled()
            }
        }

        $cfg = self::loadGlobal();

        // Generate tags for inside <head>
        $html = '';

        if (!empty($cfg['ga4_id'])) {
            $gaId = htmlspecialchars($cfg['ga4_id'], ENT_QUOTES, 'UTF-8');
            $html .= "<!-- Google Analytics 4 -->\n"
                . "<script async src=\"https://www.googletagmanager.com/gtag/js?id={$gaId}\"></script>\n"
                . "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
                . "gtag('js',new Date());gtag('config','{$gaId}');</script>\n";
        }

        if (!empty($cfg['search_console_token'])) {
            $token = htmlspecialchars($cfg['search_console_token'], ENT_QUOTES, 'UTF-8');
            $html .= "<meta name=\"google-site-verification\" content=\"{$token}\">\n";
        }

        file_put_contents(self::HEAD_CACHE, $html, LOCK_EX);
    }

    public static function loadGlobal(): array
    {
        if (!file_exists(self::GLOBAL_CACHE)) return [];
        return require self::GLOBAL_CACHE;
    }

    public static function loadHead(): string
    {
        if (!file_exists(self::HEAD_CACHE)) return '';
        return file_get_contents(self::HEAD_CACHE);
    }

    /** Default for the title_format setting. {title} is the page's own title, {site} the site name. */
    public const DEFAULT_TITLE_FORMAT = '{title} | {site}';

    /** Length of the description excerpt baked on save (posts.meta_description_auto). */
    public const EXCERPT_LENGTH = 120;

    /**
     * Plain-text excerpt of compiled body HTML, for the auto description and feed summaries.
     * The table of contents, the sponsored notice and block markers are left out: none of them
     * describe the post.
     */
    public static function excerpt(string $html, int $length = self::EXCERPT_LENGTH): string
    {
        $html  = preg_replace('#<nav class="hbp-toc".*?</nav>#s', ' ', $html) ?? $html;
        $html  = preg_replace('#<p class="hbp-sponsored-notice">.*?</p>#s', ' ', $html) ?? $html;
        $html  = preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $html) ?? $html;
        $html  = preg_replace('#<!--.*?-->#s', ' ', $html) ?? $html;
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? '');
        if (mb_strlen($plain) > $length) {
            $plain = rtrim(mb_substr($plain, 0, $length)) . '…';
        }
        return $plain;
    }

    /** The document title for a page named $name ('' = the site's top page). Not escaped. */
    public static function title(string $name, ?array $global = null): string
    {
        $site = Env::get('SITE_NAME', 'HayabusaPress');
        if ($name === '') {
            return $site;
        }
        $global ??= self::loadGlobal();
        $format = (string)($global['title_format'] ?? '');
        if (!str_contains($format, '{title}')) {
            $format = self::DEFAULT_TITLE_FORMAT;
        }
        return strtr($format, ['{title}' => $name, '{site}' => $site]);
    }

    /** Generate SEO tags for a post (per-post -> global fallback + automatic hreflang) */
    public static function buildMeta(array $post): string
    {
        $global = self::loadGlobal();

        // Typed description, then the excerpt baked on save, then the site default
        $descRaw = (string)($post['meta_description'] ?? '');
        if ($descRaw === '') $descRaw = (string)($post['meta_description_auto'] ?? '');
        if ($descRaw === '') $descRaw = (string)($global['default_description'] ?? '');

        $keywords = $post['meta_keywords'] ?? '';
        if ($keywords === '') $keywords = $global['default_keywords'] ?? '';

        $docTitle = htmlspecialchars(self::title((string)($post['title'] ?? ''), $global), ENT_QUOTES, 'UTF-8');
        $title    = htmlspecialchars($post['title']   ?? '',  ENT_QUOTES, 'UTF-8');
        $desc     = htmlspecialchars($descRaw,          ENT_QUOTES, 'UTF-8');
        $keywords = htmlspecialchars($keywords,         ENT_QUOTES, 'UTF-8');
        $siteUrl     = rtrim(class_exists('Env') ? Env::get('SITE_URL', '') : ($_ENV['SITE_URL'] ?? ''), '/');
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en');
        $postLang    = $post['lang'] ?? $defaultLang;
        $langPrefix  = $postLang === $defaultLang ? '' : "/{$postLang}";
        $typeSegment = $post['type'] === 'post' ? 'post' : 'page';
        $postSlug    = $post['slug'] ?? '';
        $urlRaw      = $siteUrl . $langPrefix . "/{$typeSegment}/{$postSlug}";
        $url         = htmlspecialchars($urlRaw, ENT_QUOTES, 'UTF-8');
        $siteNameRaw = (string)($global['site_name'] ?? '') !== '' ? (string)$global['site_name'] : Env::get('SITE_NAME', 'HayabusaPress');

        $html = "<title>{$docTitle}</title>\n";
        if ($desc)     $html .= "<meta name=\"description\" content=\"{$desc}\">\n";
        if ($keywords) $html .= "<meta name=\"keywords\" content=\"{$keywords}\">\n";
        $html .= "<meta property=\"og:title\" content=\"{$title}\">\n";
        $html .= "<meta property=\"og:url\" content=\"{$url}\">\n";
        $html .= "<meta property=\"og:type\" content=\"article\">\n";
        if ($desc) {
            $html .= "<meta property=\"og:description\" content=\"{$desc}\">\n";
        }
        $html .= '<meta property="og:site_name" content="' . htmlspecialchars($siteNameRaw, ENT_QUOTES, 'UTF-8') . "\">\n";

        // featured image (already resolved at save time; no body scan). Relative paths are made absolute
        $featured = $post['featured_image'] ?? '';
        $imgAbs   = '';
        if ($featured !== '') {
            $imgAbs = preg_match('#^https?://#', $featured) ? $featured : $siteUrl . '/' . ltrim($featured, '/');
            $imgUrl = htmlspecialchars($imgAbs, ENT_QUOTES, 'UTF-8');
            $imgAlt = htmlspecialchars($post['featured_image_alt'] ?? ($post['title'] ?? ''), ENT_QUOTES, 'UTF-8');
            $html .= "<meta property=\"og:image\" content=\"{$imgUrl}\">\n";
            if ($imgAlt !== '') {
                $html .= "<meta property=\"og:image:alt\" content=\"{$imgAlt}\">\n";
            }
            $html .= "<meta name=\"twitter:card\" content=\"summary_large_image\">\n";
            $html .= "<meta name=\"twitter:image\" content=\"{$imgUrl}\">\n";
        } else {
            $html .= "<meta name=\"twitter:card\" content=\"summary\">\n";
        }

        // hreflang is generated from posts.hreflang_cache (translation group)
        // Prefer hreflang_cache when present (already baked by TranslationCompiler at save time)
        if (!empty($post['hreflang_cache'])) {
            $hreflangMap = json_decode($post['hreflang_cache'], true) ?? [];
            foreach ($hreflangMap as $code => $relUrl) {
                $absUrl = htmlspecialchars($siteUrl . $relUrl, ENT_QUOTES, 'UTF-8');
                $html .= "<link rel=\"alternate\" hreflang=\"{$code}\" href=\"{$absUrl}\">\n";
            }
        }

        // Structured data: the post itself, then where it sits in the site
        $home = $siteUrl . $langPrefix . '/';
        if ($post['type'] === 'post') {
            $author  = class_exists('AuthorCompiler') ? AuthorCompiler::get(isset($post['user_id']) ? (int)$post['user_id'] : null) : [];
            $article = [
                '@context'         => 'https://schema.org',
                '@type'            => 'BlogPosting',
                'headline'         => mb_substr((string)($post['title'] ?? ''), 0, 110),
                'mainEntityOfPage' => $urlRaw,
                'url'              => $urlRaw,
                'inLanguage'       => $postLang,
                'datePublished'    => self::isoUtc($post['published_at'] ?? null),
                'dateModified'     => self::isoUtc($post['updated_at'] ?? ($post['published_at'] ?? null)),
                'author'           => ['@type' => 'Person', 'name' => (string)($author['name'] ?? $siteNameRaw)],
                'publisher'        => self::publisher($global, $siteNameRaw, $siteUrl),
            ];
            if ($descRaw !== '') $article['description'] = $descRaw;
            if ($imgAbs !== '')  $article['image'] = [$imgAbs];
            $html .= self::jsonLd(array_filter($article, static fn($v) => $v !== null));
        } else {
            $page = ['@context' => 'https://schema.org', '@type' => 'WebPage', 'name' => (string)($post['title'] ?? ''), 'url' => $urlRaw, 'inLanguage' => $postLang];
            if ($descRaw !== '') $page['description'] = $descRaw;
            $html .= self::jsonLd($page);
        }

        $crumbs = [[$siteNameRaw, $home]];
        if ($post['type'] === 'post' && !empty($post['category_id']) && class_exists('CategoryCompiler')) {
            foreach (CategoryCompiler::load() as $cat) {
                if ((int)$cat['id'] === (int)$post['category_id']) {
                    $crumbs[] = [(string)$cat['name'], $siteUrl . $langPrefix . '/post/category/' . $cat['slug']];
                    break;
                }
            }
        }
        $crumbs[] = [(string)($post['title'] ?? ''), $urlRaw];
        $html .= self::breadcrumbs($crumbs);

        return $html;
    }

    /**
     * Head tags for the top page and listings (category / tag / archive). $name '' = the top page.
     *
     * @param string $url this page's canonical URL
     */
    public static function buildListMeta(string $name, string $url, string $lang): string
    {
        $global   = self::loadGlobal();
        $siteName = (string)($global['site_name'] ?? '') !== '' ? (string)$global['site_name'] : Env::get('SITE_NAME', 'HayabusaPress');
        $siteUrl  = rtrim(Env::get('SITE_URL', ''), '/');
        $default  = Env::get('SITE_DEFAULT_LANG', 'en');
        $home     = $siteUrl . ($lang === $default ? '' : "/{$lang}") . '/';
        $e        = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $desc     = (string)($global['default_description'] ?? '');

        $html  = '<title>' . $e(self::title($name, $global)) . "</title>\n";
        if ($desc !== '') $html .= '<meta name="description" content="' . $e($desc) . "\">\n";
        $html .= '<meta property="og:title" content="' . $e($name === '' ? $siteName : $name) . "\">\n";
        if ($url !== '') $html .= '<meta property="og:url" content="' . $e($url) . "\">\n";
        $html .= "<meta property=\"og:type\" content=\"website\">\n";
        if ($desc !== '') $html .= '<meta property="og:description" content="' . $e($desc) . "\">\n";
        $html .= '<meta property="og:site_name" content="' . $e($siteName) . "\">\n";
        $html .= "<meta name=\"twitter:card\" content=\"summary\">\n";

        if ($name === '') {
            $site = ['@context' => 'https://schema.org', '@type' => 'WebSite', 'name' => $siteName, 'url' => $home, 'inLanguage' => $lang];
            if ($desc !== '') $site['description'] = $desc;
            $html .= self::jsonLd($site);
        } else {
            $html .= self::breadcrumbs([[$siteName, $home], [$name, $url]]);
        }
        return $html;
    }

    /** @param array<int,array{0:string,1:string}> $crumbs [name, absolute URL] from the top down */
    private static function breadcrumbs(array $crumbs): string
    {
        $items = [];
        foreach (array_values($crumbs) as $i => [$name, $url]) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $url];
        }
        return self::jsonLd(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items]);
    }

    private static function publisher(array $global, string $siteName, string $siteUrl): array
    {
        $pub  = ['@type' => 'Organization', 'name' => $siteName, 'url' => $siteUrl . '/'];
        $logo = (string)($global['publisher_logo'] ?? '');
        if ($logo === '') $logo = Env::get('SITE_LOGO', '');
        if ($logo !== '') {
            $pub['logo'] = ['@type' => 'ImageObject', 'url' => preg_match('#^https?://#', $logo) ? $logo : $siteUrl . '/' . ltrim($logo, '/')];
        }
        return $pub;
    }

    /**
     * A data block, not a script: browsers never execute application/ld+json, so CSP script-src does
     * not apply and no hash is needed. JSON_HEX_TAG keeps a "</script>" in a title from ending it.
     */
    private static function jsonLd(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        return $json === false ? '' : "<script type=\"application/ld+json\">{$json}</script>\n";
    }

    /** Stored timestamps are UTC ('Y-m-d H:i:s'); schema.org wants ISO 8601. */
    private static function isoUtc(?string $utc): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }
        $t = strtotime($utc . ' UTC');
        return $t === false ? null : gmdate('Y-m-d\TH:i:s\Z', $t);
    }
}
