<?php
/**
 * HayabusaPress - Markdown to HTML compiler (save-time, zero dependencies).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Ultra-lightweight Markdown -> HTML compiler (zero external dependencies).
 * Called only once at save time. The front end outputs content_html as-is.
 *
 * HtmlSanitizer integration (Section 24.1)
 * - Low-trust roles (without post:raw_html): fully escape raw HTML
 * - Always: apply the DOM allowlist sanitizer
 */
class MarkdownCompiler
{
    /**
     * Placeholder delimiter for constructs the sanitizer must not see (block markers, buttons).
     * A private-use code point: it survives the DOM round trip and is never typed by hand.
     */
    private const TOKEN = "\u{E000}";

    /** {{ block:slug }} on its own line. */
    private const BLOCK_LINE  = '/^\{\{\s*block:([a-z0-9][a-z0-9-]{0,63})\s*\}\}$/';
    /** [[button:Label|URL|note]] on its own line (note optional). */
    private const BUTTON_LINE = '/^\[\[button:([^|\]]+)\|([^|\]]+)(?:\|([^\]]*))?\]\]$/u';
    /** {{ card:slug }} on its own line: a card for another post of this site. */
    private const CARD_LINE = '/^\{\{\s*card:([a-z0-9][a-z0-9-]{0,199})\s*\}\}$/';
    /** ":::quote Source|URL", ":::box type Title", ":::say speaker" open a container closed by ":::". */
    private const CONTAINER_OPEN = '/^:::(quote|box|say)(?:\s+(.*))?$/u';
    /** Box types (anything else is "note"). */
    public const BOX_TYPES = ['note', 'summary', 'warning', 'conclusion'];

    /** Content language of the current render (cards and speakers are per language). */
    private static string $lang = '';
    /** Card slugs that did not resolve in the last render (for the editor's warning). */
    private static array $missingCards = [];

    /** Heading IDs already used in the document being rendered (kept unique: -2, -3, ...). */
    private static array $usedIds = [];

    /**
     * Convert Markdown to HTML, sanitize, and return it.
     *
     * Save-time extensions (all resolved here, never at request time):
     *   {{ block:slug }}               on its own line -> <!--hbp:block:slug-->, replaced with the
     *                                  reusable block when the full-page cache is generated
     *   [[button:Label|URL|note]]      on its own line -> a link button with an optional note above it
     *
     * @param string $markdown     Markdown text
     * @param bool   $allowRawHtml true = administrator/SU (pass raw HTML through), false = low-trust role (escape everything)
     * @param array  $options      'toc' => bool (bake a table of contents from H2/H3),
     *                             'sponsored' => bool (prepend the sponsored-content notice, rel="sponsored" on outbound links),
     *                             'lang' => content language of the notice
     */
    public static function render(string $markdown, bool $allowRawHtml = false, array $options = []): string
    {
        self::$usedIds      = [];
        self::$missingCards = [];
        self::$lang         = (string)($options['lang'] ?? '');
        // A stray delimiter in the source must not be able to forge a placeholder.
        $markdown = str_replace([self::TOKEN, "\x05"], '', $markdown);
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        $html  = self::parseBlocks($lines, $allowRawHtml);

        // DOM allowlist sanitizer (always applied)
        if (class_exists('HtmlSanitizer')) {
            $html = HtmlSanitizer::clean($html, allowEmbed: $allowRawHtml);
        }

        $html = self::resolveTokens($html);
        $html = self::linkRels($html, !empty($options['sponsored']));
        $html = self::images($html);
        if (!empty($options['toc'])) {
            $html = self::insertToc($html, (string)($options['lang'] ?? ''));
        }
        if (!empty($options['sponsored'])) {
            $html = self::sponsoredNotice((string)($options['lang'] ?? '')) . $html;
        }
        return $html;
    }

    /** Card slugs the last render() could not find among published posts. */
    public static function missingCards(): array
    {
        return array_values(array_unique(self::$missingCards));
    }

    /** Backward compat: alias of render() (no permission check; for compatibility with existing code) */
    public static function compile(string $markdown): string
    {
        // Determine the role if Gate is available
        $allowRaw = class_exists('Gate') && Gate::can('post:raw_html');
        return self::render($markdown, $allowRaw);
    }

    private static function parseBlocks(array $lines, bool $allowRawHtml = false): string
    {
        $html = '';
        $i    = 0;
        $max  = count($lines);

        while ($i < $max) {
            $line = $lines[$i];

            // --- Code block ---
            if (str_starts_with($line, '```')) {
                $lang = trim(substr($line, 3));
                $code = '';
                $i++;
                while ($i < $max && $lines[$i] !== '```') {
                    $code .= $lines[$i] . "\n";
                    $i++;
                }
                $i++;
                $langAttr = $lang
                    ? ' class="language-' . htmlspecialchars($lang, ENT_QUOTES, 'UTF-8') . '"'
                    : '';
                $html .= '<pre><code' . $langAttr . '>'
                    . htmlspecialchars(rtrim($code), ENT_QUOTES, 'UTF-8')
                    . "</code></pre>\n";
                continue;
            }

            // --- Container (:::quote / :::box / :::say ... :::). One level: an inner ":::" closes it. ---
            // The body is ordinary Markdown and is sanitized like everything else; only the wrapper,
            // built from validated arguments, is added after sanitizing.
            if (preg_match(self::CONTAINER_OPEN, trim($line), $m)) {
                $inner = [];
                $i++;
                while ($i < $max && trim($lines[$i]) !== ':::') {
                    $inner[] = $lines[$i];
                    $i++;
                }
                $i++;   // the closing ":::"
                $arg   = base64_encode(trim($m[2] ?? ''));
                $html .= '<p>' . self::TOKEN . 'open:' . $m[1] . ':' . $arg . self::TOKEN . "</p>\n"
                    . self::parseBlocks($inner, $allowRawHtml)
                    . '<p>' . self::TOKEN . 'close:' . $m[1] . self::TOKEN . "</p>\n";
                continue;
            }
            if (preg_match(self::CARD_LINE, trim($line), $m)) {
                $html .= '<p>' . self::TOKEN . 'card:' . $m[1] . self::TOKEN . "</p>\n";
                $i++;
                continue;
            }

            // --- Reusable block / button (own line; resolved after sanitizing) ---
            if (preg_match(self::BLOCK_LINE, trim($line), $m)) {
                $html .= '<p>' . self::TOKEN . 'block:' . $m[1] . self::TOKEN . "</p>\n";
                $i++;
                continue;
            }
            if (preg_match(self::BUTTON_LINE, trim($line), $m)) {
                $payload = base64_encode((string)json_encode([trim($m[1]), trim($m[2]), trim($m[3] ?? '')], JSON_UNESCAPED_UNICODE));
                $html   .= '<p>' . self::TOKEN . 'button:' . $payload . self::TOKEN . "</p>\n";
                $i++;
                continue;
            }

            // --- Heading ---
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                $level = strlen($m[1]);
                $text  = self::inline($m[2]);
                $id    = self::headingId($m[2]);
                $html .= "<h{$level} id=\"{$id}\">{$text}</h{$level}>\n";
                $i++;
                continue;
            }

            // --- Horizontal rule ---
            if (preg_match('/^[-*_]{3,}\s*$/', $line)) {
                $html .= "<hr>\n";
                $i++;
                continue;
            }

            // --- Blockquote ---
            if (str_starts_with($line, '> ')) {
                $content = '';
                while ($i < $max && (str_starts_with($lines[$i], '> ') || $lines[$i] === '>')) {
                    $content .= substr($lines[$i], 2) . "\n";
                    $i++;
                }
                $html .= '<blockquote>' . self::parseBlocks(explode("\n", rtrim($content))) . "</blockquote>\n";
                continue;
            }

            // --- Unordered list ---
            if (preg_match('/^[-*+]\s+(.+)$/', $line, $m)) {
                $html .= "<ul>\n";
                while ($i < $max && preg_match('/^[-*+]\s+(.+)$/', $lines[$i], $m)) {
                    $html .= '<li>' . self::inline($m[1]) . "</li>\n";
                    $i++;
                }
                $html .= "</ul>\n";
                continue;
            }

            // --- Ordered list ---
            if (preg_match('/^\d+\.\s+(.+)$/', $line, $m)) {
                $html .= "<ol>\n";
                while ($i < $max && preg_match('/^\d+\.\s+(.+)$/', $lines[$i], $m)) {
                    $html .= '<li>' . self::inline($m[1]) . "</li>\n";
                    $i++;
                }
                $html .= "</ol>\n";
                continue;
            }

            // --- Table ---
            if (str_contains($line, '|') && isset($lines[$i + 1]) && preg_match('/^\|?[-:| ]+\|/', $lines[$i + 1])) {
                $rows   = [];
                $isHead = true;
                while ($i < $max && str_contains($lines[$i], '|')) {
                    if (preg_match('/^\|?[-:| ]+\|/', $lines[$i])) {
                        $i++;
                        continue;
                    }
                    $cells = array_map('trim', explode('|', trim($lines[$i], '|')));
                    $rows[] = ['cells' => $cells, 'head' => $isHead];
                    $isHead = false;
                    $i++;
                }
                $html .= "<table>\n";
                foreach ($rows as $row) {
                    $tag = $row['head'] ? 'th' : 'td';
                    $html .= '<tr>';
                    foreach ($row['cells'] as $cell) {
                        $html .= "<{$tag}>" . self::inline($cell) . "</{$tag}>";
                    }
                    $html .= "</tr>\n";
                }
                $html .= "</table>\n";
                continue;
            }

            // --- Blank line ---
            if (trim($line) === '') {
                $i++;
                continue;
            }

            // --- Paragraph ---
            $para = '';
            while ($i < $max) {
                $l = $lines[$i];
                if (trim($l) === '') break;
                if (preg_match('/^#{1,6}\s/', $l)) break;
                if (str_starts_with($l, '```')) break;
                if (str_starts_with($l, '> ')) break;
                if (preg_match('/^[-*+]\s/', $l)) break;
                if (preg_match('/^\d+\.\s/', $l)) break;
                if (preg_match('/^[-*_]{3,}\s*$/', $l)) break;
                // Exactly the patterns handled above: a looser test would stop on a line nothing consumes.
                if (preg_match(self::BLOCK_LINE, trim($l)) || preg_match(self::BUTTON_LINE, trim($l))) break;
                if (preg_match(self::CARD_LINE, trim($l)) || preg_match(self::CONTAINER_OPEN, trim($l))) break;
                // A stray ":::" (no container open) ends a paragraph but must still be consumed when it
                // starts one, or the outer loop would see the same line forever.
                if ($para !== '' && trim($l) === ':::') break;
                $para .= ($para !== '' ? "\n" : '') . $l;
                $i++;
            }
            if ($para !== '') {
                $html .= '<p>' . self::inline(str_replace("\n", '<br>', $para)) . "</p>\n";
            }
        }

        return $html;
    }

    /**
     * Heading ID from its text. The same text twice used to yield the same ID, so in-page links (and
     * the table of contents) could only ever reach the first one; later ones get -2, -3, ...
     */
    private static function headingId(string $text): string
    {
        $base = rawurlencode(mb_strtolower(trim((string)preg_replace('/[^\w\s-]/u', '', $text))));
        if ($base === '') {
            $base = 'section';
        }
        $id = $base;
        for ($n = 2; isset(self::$usedIds[$id]); $n++) {
            $id = $base . '-' . $n;
        }
        self::$usedIds[$id] = true;
        return $id;
    }

    /** Turn the placeholders left by parseBlocks() into their final markup. */
    private static function resolveTokens(string $html): string
    {
        if (!str_contains($html, self::TOKEN)) {
            return $html;
        }
        $t    = preg_quote(self::TOKEN, '/');
        $html = (string)preg_replace('/<p>' . $t . 'block:([a-z0-9-]+)' . $t . '<\/p>/u', '<!--hbp:block:$1-->', $html);
        $html = (string)preg_replace_callback(
            '/<p>' . $t . 'button:([A-Za-z0-9+\/=]+)' . $t . '<\/p>/u',
            static function (array $m): string {
                [$label, $url, $note] = array_pad((array)json_decode((string)base64_decode($m[1]), true), 3, '');
                require_once CORE_PATH . '/SidebarCompiler.php';   // shared URL allowlist
                $href = SidebarCompiler::safeUrl((string)$url, true);
                if ($href === '' || trim((string)$label) === '') {
                    return '';
                }
                $e   = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
                $out = '<p class="hbp-button-wrap">';
                if ((string)$note !== '') {
                    $out .= '<span class="hbp-button-note">' . $e((string)$note) . '</span>';
                }
                return $out . '<a class="hbp-button" href="' . $e($href) . '">' . $e((string)$label) . '</a></p>';
            },
            $html
        );
        $html = (string)preg_replace_callback(
            '/<p>' . $t . 'card:([a-z0-9-]+)' . $t . '<\/p>/u',
            static fn(array $m): string => self::card($m[1]),
            $html
        );
        // Containers: open and close are resolved as a pair, so a lost half never leaves an unclosed tag
        $html = (string)preg_replace_callback(
            '/<p>' . $t . 'open:(quote|box|say):([A-Za-z0-9+\/=]*)' . $t . '<\/p>\n?(.*?)<p>' . $t . 'close:\1' . $t . '<\/p>/su',
            static function (array $m): string {
                [$open, $close] = self::container($m[1], (string)base64_decode($m[2]));
                return $open . $m[3] . $close;
            },
            $html
        );
        // Anything left over (a placeholder the sanitizer moved, half a container) is dropped rather than shown.
        $html = (string)preg_replace('/<p>' . $t . '[^' . $t . ']*' . $t . '<\/p>\n?/u', '', $html);
        return str_replace(self::TOKEN, '', $html);
    }

    /**
     * Wrapper markup of a container. Every value placed in it is escaped or URL-checked here, because
     * this runs after the sanitizer.
     *
     * @return array{0:string,1:string} [opening markup, closing markup]
     */
    private static function container(string $kind, string $arg): array
    {
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        if ($kind === 'quote') {
            [$source, $url] = array_pad(array_map('trim', explode('|', $arg, 2)), 2, '');
            require_once CORE_PATH . '/SidebarCompiler.php';
            $href    = $url !== '' ? SidebarCompiler::safeUrl($url, true) : '';
            $citeAtt = $href !== '' ? ' cite="' . $e($href) . '"' : '';
            $caption = '';
            if ($source !== '') {
                $name    = $href !== '' ? '<a href="' . $e($href) . '">' . $e($source) . '</a>' : $e($source);
                $caption = '<figcaption>— <cite>' . $name . '</cite></figcaption>';
            }
            return ['<figure class="hbp-quote"><blockquote' . $citeAtt . '>', '</blockquote>' . $caption . '</figure>'];
        }
        if ($kind === 'box') {
            $parts = preg_split('/\s+/u', $arg, 2) ?: [''];
            $type  = in_array($parts[0], self::BOX_TYPES, true) ? $parts[0] : 'note';
            $title = in_array($parts[0], self::BOX_TYPES, true) ? (string)($parts[1] ?? '') : $arg;
            $head  = $title !== '' ? '<p class="hbp-box-title">' . $e($title) . '</p>' : '';
            return ['<div class="hbp-box hbp-box-' . $type . '" role="note">' . $head, '</div>'];
        }
        // say: the speaker is looked up by slug (unknown slugs show the slug as the name)
        $parts   = preg_split('/\s+/u', $arg) ?: [''];
        $slug    = (string)$parts[0];
        $speaker = self::speaker($slug);
        $side    = in_array($parts[1] ?? '', ['left', 'right'], true) ? $parts[1] : ($speaker['side'] ?? 'left');
        $name    = (string)($speaker['name'] ?? $slug);
        $avatar  = (string)($speaker['avatar'] ?? '');
        $img     = $avatar !== '' ? '<img src="' . $e($avatar) . '" alt="" width="56" height="56" loading="lazy">' : '';
        return [
            '<div class="hbp-say hbp-say-' . $side . '"><div class="hbp-say-who">' . $img . '<span>' . $e($name) . '</span></div><div class="hbp-say-body">',
            '</div></div>',
        ];
    }

    /** A registered speaker (SpeakerCompiler), or null. */
    private static function speaker(string $slug): ?array
    {
        $file = STORAGE_PATH . '/cache/speakers.cache.php';
        if (!is_file($file) && class_exists('DB') && is_file(CORE_PATH . '/SpeakerCompiler.php')) {
            require_once CORE_PATH . '/SpeakerCompiler.php';
            try { SpeakerCompiler::compile(); } catch (Throwable) { return null; }
        }
        $all = is_file($file) ? (@include $file) : [];
        return is_array($all) ? ($all[$slug] ?? null) : null;
    }

    /**
     * Card for a published post of this site, in the current render language. Baked into the post:
     * the other post's later edits reach it on the next save or posts:recompile.
     */
    private static function card(string $slug): string
    {
        if (!class_exists('DB')) {
            return '';
        }
        $lang = self::$lang !== '' ? self::$lang : (class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : 'en');
        try {
            $p = DB::fetchOne(
                "SELECT type, lang, slug, title, meta_description, meta_description_auto, featured_image
                   FROM posts WHERE slug = ? AND lang = ? AND status = 'published' AND deleted_at IS NULL
                  ORDER BY CASE WHEN type = 'post' THEN 0 ELSE 1 END LIMIT 1",
                [$slug, $lang]
            );
        } catch (Throwable) {
            $p = null;
        }
        if (!$p) {
            self::$missingCards[] = $slug;
            return '';
        }
        $e      = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $prefix = (class_exists('Env') && $p['lang'] === Env::get('SITE_DEFAULT_LANG', 'en')) ? '' : '/' . $p['lang'];
        $href   = $prefix . '/' . $p['type'] . '/' . $p['slug'];
        $desc   = (string)($p['meta_description'] ?: ($p['meta_description_auto'] ?? ''));
        $img    = (string)($p['featured_image'] ?? '');
        return '<p class="hbp-card-wrap"><a class="hbp-card" href="' . $e($href) . '">'
            . ($img !== '' ? '<img class="hbp-card-img" src="' . $e($img) . '" alt="" loading="lazy">' : '')
            . '<span class="hbp-card-body"><span class="hbp-card-title">' . $e((string)$p['title']) . '</span>'
            . ($desc !== '' ? '<span class="hbp-card-desc">' . $e(mb_strimwidth($desc, 0, 120, '…')) . '</span>' : '')
            . '</span></a></p>';
    }

    /**
     * Local article images get their real width/height (no layout shift while they load), and a WebP
     * twin made at upload time ({file}.webp) is offered through <picture>. Runs after sanitizing on
     * attribute values it reads from the files itself.
     */
    private static function images(string $html): string
    {
        if (!str_contains($html, '/assets/articles/') || !class_exists('Media')) {
            return $html;
        }
        return (string)preg_replace_callback(
            '#<img src="(/assets/articles/(?:[a-z0-9_]{2}/[a-z0-9_]{2}/)?([A-Za-z0-9._-]+))"([^>]*)>#',
            static function (array $m): string {
                $fn   = $m[2];
                $path = is_file(Media::articlePath($fn)) ? Media::articlePath($fn) : Media::uploadPath($fn);
                $tag  = $m[0];
                if (!str_contains($m[3], ' width=') && is_file($path) && ($size = @getimagesize($path))) {
                    $tag = '<img src="' . $m[1] . '"' . $m[3] . ' width="' . (int)$size[0] . '" height="' . (int)$size[1] . '">';
                }
                $webp = $fn . '.webp';
                if (preg_match('/\.(jpe?g|png)$/i', $fn)
                    && (is_file(Media::articlePath($webp)) || is_file(Media::uploadPath($webp)))) {
                    $tag = '<picture><source srcset="' . htmlspecialchars(Media::articleUrl($webp), ENT_QUOTES, 'UTF-8') . '" type="image/webp">' . $tag . '</picture>';
                }
                return $tag;
            },
            $html
        );
    }

    /**
     * rel on links: managed links (/go/...) are always paid placements ("sponsored nofollow");
     * on a sponsored post every outbound link is marked "sponsored" as well.
     */
    private static function linkRels(string $html, bool $sponsored): string
    {
        if (!str_contains($html, '<a ')) {
            return $html;
        }
        $siteHost = (string)(parse_url(class_exists('Env') ? Env::get('SITE_URL', '') : '', PHP_URL_HOST) ?? '');
        return (string)preg_replace_callback('/<a\b([^>]*)>/i', static function (array $m) use ($sponsored, $siteHost): string {
            $attrs = $m[1];
            if (!preg_match('/\bhref="([^"]*)"/i', $attrs, $h)) {
                return $m[0];
            }
            $href = html_entity_decode($h[1], ENT_QUOTES, 'UTF-8');
            $add  = [];
            if (str_starts_with($href, '/go/')) {
                $add = ['sponsored', 'nofollow'];
            } elseif ($sponsored && preg_match('#^https?://#i', $href)) {
                $host = (string)(parse_url($href, PHP_URL_HOST) ?? '');
                if ($host !== '' && strcasecmp($host, $siteHost) !== 0) {
                    $add = ['sponsored'];
                }
            }
            if (!$add) {
                return $m[0];
            }
            if (preg_match('/\brel="([^"]*)"/i', $attrs, $r)) {
                $rel   = array_values(array_unique(array_merge(preg_split('/\s+/', trim($r[1])) ?: [], $add)));
                $attrs = str_replace($r[0], 'rel="' . htmlspecialchars(trim(implode(' ', $rel)), ENT_QUOTES, 'UTF-8') . '"', $attrs);
            } else {
                $attrs .= ' rel="' . implode(' ', $add) . '"';
            }
            return '<a' . $attrs . '>';
        }, $html);
    }

    /** Table of contents from H2/H3, inserted before the first H2. Needs at least two H2s. */
    private static function insertToc(string $html, string $lang): string
    {
        if (!preg_match_all('/<h([23]) id="([^"]+)">(.*?)<\/h\1>/s', $html, $m, PREG_SET_ORDER)) {
            return $html;
        }
        if (count(array_filter($m, static fn(array $h): bool => $h[1] === '2')) < 2) {
            return $html;
        }
        $dict  = self::frontendDict($lang);
        $title = htmlspecialchars($dict['toc_title'] ?? 'Contents', ENT_QUOTES, 'UTF-8');
        $nav   = '<nav class="hbp-toc" aria-label="' . $title . '"><p class="hbp-toc-title">' . $title . '</p><ol>';
        $open  = false;   // an H3 list is open under the current H2
        $first = true;
        foreach ($m as $h) {
            $item = '<a href="#' . $h[2] . '">' . htmlspecialchars(trim(strip_tags(html_entity_decode($h[3], ENT_QUOTES, 'UTF-8'))), ENT_QUOTES, 'UTF-8') . '</a>';
            if ($h[1] === '2') {
                $nav  .= ($open ? '</ol></li>' : ($first ? '' : '</li>')) . '<li>' . $item;
                $open  = false;
                $first = false;
            } else {
                if ($first) {
                    continue;   // an H3 before any H2 has no parent entry
                }
                $nav  .= ($open ? '' : '<ol>') . '<li>' . $item . '</li>';
                $open  = true;
            }
        }
        $nav .= ($open ? '</ol></li>' : '</li>') . '</ol></nav>' . "\n";

        $pos = strpos($html, '<h2 ');
        return $pos === false ? $html : substr($html, 0, $pos) . $nav . substr($html, $pos);
    }

    /** The sponsored-content notice (SPONSORED_NOTICE_TEXT overrides the localized default). */
    private static function sponsoredNotice(string $lang): string
    {
        $text = class_exists('Env') ? trim(Env::get('SPONSORED_NOTICE_TEXT', '')) : '';
        if ($text === '') {
            $text = self::frontendDict($lang)['sponsored_notice'] ?? 'This article contains promotional content.';
        }
        return '<p class="hbp-sponsored-notice">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . "</p>\n";
    }

    private static function frontendDict(string $lang): array
    {
        return class_exists('HbpLocale') ? HbpLocale::loadFrontendDictionary($lang) : [];
    }

    private static function inline(string $text): string
    {
        // Stash inline code (protect it from ruby/emphasis-dots and other transforms. code is left untouched)
        $codes = [];
        $text  = preg_replace_callback(
            '/`([^`]+)`/',
            function ($m) use (&$codes) {
                $codes[] = $m[1];
                return "\x02" . (count($codes) - 1) . "\x02";
            },
            $text
        );

        // Generated tags are stashed too, so emphasis never reaches inside their attributes: with two
        // links in a paragraph, '_' in target="_blank" ... "_blank" used to be read as <em>.
        $tags  = [];
        $stash = function (string $tag) use (&$tags): string {
            $tags[] = $tag;
            return "\x05" . (count($tags) - 1) . "\x05";
        };

        // Images (processed before links)
        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)]+)\)/',
            function ($m) use ($stash) {
                $alt = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                $src = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
                return $stash("<img src=\"{$src}\" alt=\"{$alt}\" loading=\"lazy\">");
            },
            $text
        );

        // Links (the label stays outside the stash so it still gets emphasis)
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function ($m) use ($stash) {
                $label = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                $href  = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
                $ext   = str_starts_with($m[2], 'http') ? ' target="_blank" rel="noopener noreferrer"' : '';
                return $stash("<a href=\"{$href}\"{$ext}>") . $label . $stash('</a>');
            },
            $text
        );

        // Bold
        $text = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/s',       '<strong>$1</strong>', $text);

        // Italic
        $text = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $text);
        $text = preg_replace('/_(.+?)_/s',    '<em>$1</em>',  $text);

        // Strikethrough
        $text = preg_replace('/~~(.+?)~~/s', '<del>$1</del>', $text);

        // ruby (furigana) and emphasis dots. Safe because code is already stashed.
        $text = self::rubyBouten($text);

        // Restore the generated tags
        $text = preg_replace_callback('/\x05(\d+)\x05/', fn($m) => $tags[(int)$m[1]] ?? '', $text);

        // Restore inline code (escape it into <code>)
        $text = preg_replace_callback(
            '/\x02(\d+)\x02/',
            fn($m) => '<code>' . htmlspecialchars($codes[(int)$m[1]] ?? '', ENT_QUOTES, 'UTF-8') . '</code>',
            $text
        );

        return $text;
    }

    /**
     * convert novel-style ruby (furigana) and emphasis dots (bouten) to HTML (once, at save time).
     * - Emphasis dots 《《text》》             -> <em class="hbp-bouten">text</em>
     * - Explicit-base ruby ｜kanji《kana》     -> <ruby>kanji<rp>(</rp><rt>kana</rt><rp>)</rp></ruby>
     * - Auto-base ruby kanji《kana》           -> uses the preceding run of kanji as the base (Narou-compatible)
     * - \《 is output as a literal 《 (escape)
     * Parse order is "emphasis dots -> ruby" (so double brackets are not misread as ruby).
     */
    private static function rubyBouten(string $text): string
    {
        // Temporarily stash \《 (a literal 《)
        $text = str_replace('\\《', "\x03", $text);

        // Emphasis dots (before ruby): 《《...》》
        $text = preg_replace_callback(
            '/《《([^》]+)》》/u',
            fn($m) => '<em class="hbp-bouten">' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '</em>',
            $text
        );

        // Explicit-base ruby: starts with ｜ (full-width) or | (half-width)
        $text = preg_replace_callback(
            '/[｜|]([^《｜|]+)《([^》]+)》/u',
            fn($m) => self::rubyTag($m[1], $m[2]),
            $text
        );

        // Auto-base ruby: uses the preceding run of kanji as the base
        $kanji = '\x{3005}\x{3007}\x{303B}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}';
        $text  = preg_replace_callback(
            '/([' . $kanji . ']+)《([^》]+)》/u',
            fn($m) => self::rubyTag($m[1], $m[2]),
            $text
        );

        // Restore the literal 《
        return str_replace("\x03", '《', $text);
    }

    private static function rubyTag(string $base, string $reading): string
    {
        $base    = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');
        $reading = htmlspecialchars($reading, ENT_QUOTES, 'UTF-8');
        return "<ruby>{$base}<rp>(</rp><rt>{$reading}</rt><rp>)</rp></ruby>";
    }
}
