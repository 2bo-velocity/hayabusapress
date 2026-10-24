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

    /** Generate SEO tags for a post (per-post -> global fallback + automatic hreflang) */
    public static function buildMeta(array $post): string
    {
        $global = self::loadGlobal();

        $desc = $post['meta_description'] ?? '';
        if ($desc === '') $desc = $global['default_description'] ?? '';

        $keywords = $post['meta_keywords'] ?? '';
        if ($keywords === '') $keywords = $global['default_keywords'] ?? '';

        $title    = htmlspecialchars($post['title']   ?? '',  ENT_QUOTES, 'UTF-8');
        $desc     = htmlspecialchars($desc,             ENT_QUOTES, 'UTF-8');
        $keywords = htmlspecialchars($keywords,         ENT_QUOTES, 'UTF-8');
        $siteUrl     = rtrim(class_exists('Env') ? Env::get('SITE_URL', '') : ($_ENV['SITE_URL'] ?? ''), '/');
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en');
        $postLang    = $post['lang'] ?? $defaultLang;
        $langPrefix  = $postLang === $defaultLang ? '' : "/{$postLang}";
        $typeSegment = $post['type'] === 'post' ? 'post' : 'page';
        $postSlug    = $post['slug'] ?? '';
        $url         = htmlspecialchars($siteUrl . $langPrefix . "/{$typeSegment}/{$postSlug}", ENT_QUOTES, 'UTF-8');

        $html = "<title>{$title}</title>\n";
        if ($desc)     $html .= "<meta name=\"description\" content=\"{$desc}\">\n";
        if ($keywords) $html .= "<meta name=\"keywords\" content=\"{$keywords}\">\n";
        $html .= "<meta property=\"og:title\" content=\"{$title}\">\n";
        $html .= "<meta property=\"og:url\" content=\"{$url}\">\n";
        $html .= "<meta property=\"og:type\" content=\"article\">\n";
        if ($desc) {
            $html .= "<meta property=\"og:description\" content=\"{$desc}\">\n";
        }
        if (!empty($global['site_name'])) {
            $siteName = htmlspecialchars($global['site_name'], ENT_QUOTES, 'UTF-8');
            $html .= "<meta property=\"og:site_name\" content=\"{$siteName}\">\n";
        }

        // featured image (already resolved at save time; no body scan). Relative paths are made absolute
        $featured = $post['featured_image'] ?? '';
        if ($featured !== '') {
            $imgUrl = preg_match('#^https?://#', $featured) ? $featured : $siteUrl . '/' . ltrim($featured, '/');
            $imgUrl = htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8');
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

        return $html;
    }
}
