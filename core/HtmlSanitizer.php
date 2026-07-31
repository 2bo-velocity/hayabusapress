<?php
/**
 * HayabusaPress - DOM allowlist sanitizer (stored-XSS defense).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Section 24.1: DOM allowlist sanitizer (uses ext-dom).
 * The second layer of two-layer defense against stored XSS.
 * Removes <script>, on*, style, dangerous schemes, and non-allowed iframes.
 */
class HtmlSanitizer
{
    /** Allowed tag names */
    private const ALLOWED_TAGS = [
        'p', 'br', 'hr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd',
        'blockquote', 'pre', 'code',
        'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ins', 'mark', 'small', 'sub', 'sup',
        'a', 'img',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        'figure', 'figcaption', 'picture', 'source',
        'details', 'summary',
        'span', 'div', 'section', 'article', 'aside', 'header', 'footer', 'main', 'nav',
        'abbr', 'acronym', 'cite', 'q', 'kbd', 'samp', 'var', 'time',
        'ruby', 'rt', 'rp',  // furigana (ruby). Baked by MarkdownCompiler at save time
        'iframe',  // EMBED_HOSTS only
    ];

    /** Allowed attributes per tag */
    private const ALLOWED_ATTRS = [
        '*'        => ['id', 'class', 'lang', 'title'],
        'a'        => ['href', 'target', 'rel'],
        'img'      => ['src', 'alt', 'width', 'height', 'loading', 'decoding'],
        'td'       => ['colspan', 'rowspan'],
        'th'       => ['colspan', 'rowspan', 'scope'],
        'col'      => ['span'],
        'colgroup' => ['span'],
        'time'     => ['datetime'],
        'source'   => ['src', 'srcset', 'media', 'type'],
        'iframe'   => ['src', 'width', 'height', 'allowfullscreen', 'loading', 'title'],
        'abbr'     => ['title'],
    ];

    /** Dangerous URL schemes */
    private const DANGEROUS_SCHEMES = ['javascript', 'data', 'vbscript', 'file'];

    /**
     * Sanitize HTML and return it.
     *
     * @param string $html       the HTML to sanitize
     * @param bool   $allowEmbed whether to allow iframe embedding (requires the post:raw_html permission)
     */
    public static function clean(string $html, bool $allowEmbed = false): string
    {
        if ($html === '') {
            return '';
        }

        // In environments without ext-dom, fall back to simple sanitization
        if (!extension_loaded('dom')) {
            return self::simpleSanitize($html);
        }

        $embedHosts = array_filter(array_map(
            'trim',
            explode(',', Env::get('EMBED_HOSTS', 'www.youtube-nocookie.com,player.vimeo.com'))
        ));

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // Prevent mojibake via mb_convert_encoding
        @$doc->loadHTML(
            '<?xml encoding="UTF-8"><html><body>' . $html . '</body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body) {
            return '';
        }

        self::sanitizeNode($body, $doc, $allowEmbed, $embedHosts);

        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $doc->saveHTML($child);
        }
        return $result;
    }

    private static function sanitizeNode(
        DOMNode $node,
        DOMDocument $doc,
        bool $allowEmbed,
        array $embedHosts
    ): void {
        $toRemove = [];

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                $toRemove[] = $child;
                continue;
            }
            /** @var DOMElement $child */
            $tag = strtolower($child->tagName);

            // Tags not in the allowlist -> promote child nodes to the parent and remove
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                self::unwrap($child, $doc);
                continue;
            }

            // iframe: embedding not allowed, or host validation
            if ($tag === 'iframe') {
                if (!$allowEmbed || !self::isAllowedEmbed($child->getAttribute('src'), $embedHosts)) {
                    $toRemove[] = $child;
                    continue;
                }
            }

            // Attribute sanitization
            self::sanitizeAttributes($child, $tag);

            // Recurse
            self::sanitizeNode($child, $doc, $allowEmbed, $embedHosts);
        }

        foreach ($toRemove as $n) {
            if ($n->parentNode) {
                $n->parentNode->removeChild($n);
            }
        }
    }

    private static function sanitizeAttributes(DOMElement $el, string $tag): void
    {
        $allowed    = array_merge(self::ALLOWED_ATTRS['*'] ?? [], self::ALLOWED_ATTRS[$tag] ?? []);
        $toRemove   = [];

        foreach ($el->attributes as $attr) {
            $name  = strtolower($attr->name);
            $value = $attr->value;

            // Remove on* event handlers
            if (str_starts_with($name, 'on')) {
                $toRemove[] = $name;
                continue;
            }
            // style attribute (prevent CSS injection)
            if ($name === 'style') {
                $toRemove[] = $name;
                continue;
            }
            // Remove attributes not in the allowlist
            if (!in_array($name, $allowed, true)) {
                $toRemove[] = $name;
                continue;
            }
            // the class attribute also allowlists its values, closing the class-based injection vector.
            //   Only the known classes baked by MarkdownCompiler at save time are allowed:
            //     - hbp-bouten (emphasis dots <em class="hbp-bouten">)
            //     - language-xxx (language hint for code blocks)
            //   If no allowed token remains, remove the class attribute entirely.
            if ($name === 'class') {
                $kept = array_filter(
                    preg_split('/\s+/', trim($value)) ?: [],
                    static fn(string $c): bool =>
                        $c === 'hbp-bouten' || (bool)preg_match('/^language-[\w-]+$/', $c)
                );
                if (empty($kept)) {
                    $toRemove[] = $name;
                } else {
                    $attr->value = implode(' ', $kept);
                }
                continue;
            }
            // Check URL attributes for dangerous schemes
            if (in_array($name, ['href', 'src', 'action'], true)) {
                $scheme = strtolower(explode(':', $value, 2)[0] ?? '');
                if (in_array($scheme, self::DANGEROUS_SCHEMES, true)) {
                    $toRemove[] = $name;
                    continue;
                }
            }
        }

        foreach ($toRemove as $name) {
            $el->removeAttribute($name);
        }
    }

    private static function unwrap(DOMElement $el, DOMDocument $doc): void
    {
        $parent = $el->parentNode;
        if (!$parent) {
            return;
        }
        $children = [];
        foreach ($el->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            $parent->insertBefore($doc->importNode($child, true), $el);
        }
        $parent->removeChild($el);
    }

    private static function isAllowedEmbed(string $src, array $embedHosts): bool
    {
        if ($src === '') {
            return false;
        }
        $host = parse_url($src, PHP_URL_HOST) ?? '';
        foreach ($embedHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }
        return false;
    }

    /** Simple sanitization for environments without ext-dom (strip all tags). */
    private static function simpleSanitize(string $html): string
    {
        // Remove dangerous tags and escape
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/\bon\w+\s*=\s*["\'][^"\']*["\']/i', '', $html);
        return $html;
    }
}
