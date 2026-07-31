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
     * Convert Markdown to HTML, sanitize, and return it.
     *
     * @param string $markdown     Markdown text
     * @param bool   $allowRawHtml true = administrator/SU (pass raw HTML through), false = low-trust role (escape everything)
     */
    public static function render(string $markdown, bool $allowRawHtml = false): string
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $markdown));
        $html  = self::parseBlocks($lines, $allowRawHtml);

        // DOM allowlist sanitizer (always applied)
        if (class_exists('HtmlSanitizer')) {
            $html = HtmlSanitizer::clean($html, allowEmbed: $allowRawHtml);
        }
        return $html;
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

            // --- Heading ---
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                $level = strlen($m[1]);
                $text  = self::inline($m[2]);
                $id    = rawurlencode(mb_strtolower(preg_replace('/[^\w\s-]/u', '', $m[2])));
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
                $para .= ($para !== '' ? "\n" : '') . $l;
                $i++;
            }
            if ($para !== '') {
                $html .= '<p>' . self::inline(str_replace("\n", '<br>', $para)) . "</p>\n";
            }
        }

        return $html;
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

        // Images (processed before links)
        $text = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)]+)\)/',
            function ($m) {
                $alt = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                $src = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
                return "<img src=\"{$src}\" alt=\"{$alt}\" loading=\"lazy\">";
            },
            $text
        );

        // Links
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\(([^)]+)\)/',
            function ($m) {
                $label = htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8');
                $href  = htmlspecialchars($m[2], ENT_QUOTES, 'UTF-8');
                $ext   = str_starts_with($m[2], 'http') ? ' target="_blank" rel="noopener noreferrer"' : '';
                return "<a href=\"{$href}\"{$ext}>{$label}</a>";
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
