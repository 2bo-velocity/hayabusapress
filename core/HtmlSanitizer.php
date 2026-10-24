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

    /** Elements removed together with their content (unwrapping them would leak code as text). */
    private const DROP_WITH_CONTENT = ['script', 'style', 'template', 'noscript'];

    private static function sanitizeNode(
        DOMNode $node,
        DOMDocument $doc,
        bool $allowEmbed,
        array $embedHosts
    ): void {
        // Walk the children by sibling pointers rather than foreach over the live childNodes list:
        // unwrapping or removing a node while iterating that list skips the node after it, which let
        // e.g. <script></script><img onerror=...> through unsanitized.
        $child = $node->firstChild;
        while ($child !== null) {
            $next = $child->nextSibling;

            if ($child->nodeType === XML_TEXT_NODE) {
                $child = $next;
                continue;
            }
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                $node->removeChild($child);
                $child = $next;
                continue;
            }
            /** @var DOMElement $child */
            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $node->removeChild($child);
                $child = $next;
                continue;
            }

            // Tags not in the allowlist -> promote child nodes to the parent and remove. The promoted
            // nodes are visited next (they have not been sanitized yet).
            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                $first = $child->firstChild;
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                $child = $first ?? $next;
                continue;
            }

            // iframe: embedding not allowed, or host validation
            if ($tag === 'iframe') {
                if (!$allowEmbed || !self::isAllowedEmbed($child->getAttribute('src'), $embedHosts)) {
                    $node->removeChild($child);
                    $child = $next;
                    continue;
                }
            }

            // Attribute sanitization
            self::sanitizeAttributes($child, $tag);

            // Recurse
            self::sanitizeNode($child, $doc, $allowEmbed, $embedHosts);
            $child = $next;
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
            // Check URL attributes for dangerous schemes. Browsers ignore leading/trailing spaces and
            // control characters and drop tabs/newlines anywhere in a URL, so " javascript:" and
            // "java\tscript:" both run: strip those before reading the scheme.
            if (in_array($name, ['href', 'src', 'action'], true)) {
                $normalized = (string)preg_replace('/[\x00-\x20\x7f]+/', '', $value);
                $scheme     = strtolower(explode(':', $normalized, 2)[0] ?? '');
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

    /**
     * Fallback for environments without ext-dom: strip all tags. Attribute-level filtering cannot be
     * done safely with regular expressions (unquoted handlers, javascript: URLs, ...), so formatting
     * is given up rather than risking script injection. ext-dom is enabled in standard PHP builds.
     */
    private static function simpleSanitize(string $html): string
    {
        $html = (string)preg_replace('#<(script|style|template|noscript)\b[^>]*>.*?</\1\s*>#is', '', $html);
        return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
    }
}
