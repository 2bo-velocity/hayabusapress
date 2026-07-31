<?php
/**
 * HayabusaPress - Server-side public search over the DB's own index.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Public search
 *
 * Replaces the static-JSON + browser-JS search. That design regenerated a JSON file
 * containing *every* published post on *every* save and shipped the whole file to the browser,
 * so both the save path and the page weight grew linearly with the post count. It stopped being
 * usable somewhere around ten thousand posts.
 *
 * Search is the one feature that cannot be precompiled: the set of possible queries is unbounded,
 * so there is no finite artifact to bake at save time. It is therefore the single, deliberate
 * exception to "no dynamic computation at request time" — and the exception is confined to this
 * one route. Everything else on the public side keeps the zero-DB FPC fast path.
 *
 * What keeps the exception cheap:
 *   - The DB's own index does the matching (MySQL FULLTEXT + ngram / PostgreSQL pg_trgm GIN),
 *     so a query is an index probe rather than a table scan.
 *   - SEARCH_MAX_PAGES caps OFFSET exactly like LIST_FPC_DEPTH does for listings, so deep paging
 *     cannot be used to force an expensive sort.
 *   - Query length and term count are capped, and results carry X-Robots-Tag: noindex so crawlers
 *     do not enumerate the query space.
 *
 * Known cost: ORDER BY published_at DESC over the matched set is a filesort. It is bounded by the
 * number of matches, not by the table size, which is the property that matters. Set SEARCH_ORDER
 * to something else only if you have measured a reason to.
 *
 * .env:
 *   SEARCH_ENABLED    true|false  (default true; false makes /search a 404)
 *   SEARCH_MODE       auto|fulltext|like (default auto -> fulltext on mysql, like on pgsql)
 *   SEARCH_MIN_CHARS  default 2   (MySQL ngram_token_size is 2; 1-char terms can never match)
 *   SEARCH_MAX_CHARS  default 64
 *   SEARCH_MAX_TERMS  default 8
 *   SEARCH_MAX_PAGES  default 10
 */
final class PostSearch
{
    /** Columns the listing templates need. Mirrors the SELECT list of the other list handlers. */
    private const COLUMNS = 'id, user_id, title, slug, type, lang, content_html, tags_cache,
                             category_id, comment_count, published_at, featured_image, featured_image_alt';

    /** LIKE escape character. Chosen over backslash because MySQL and PostgreSQL disagree about it. */
    private const LIKE_ESCAPE = '!';

    public static function enabled(): bool
    {
        return class_exists('Env') ? Env::bool('SEARCH_ENABLED', true) : true;
    }

    public static function minChars(): int
    {
        return max(1, class_exists('Env') ? Env::int('SEARCH_MIN_CHARS', 2) : 2);
    }

    public static function maxChars(): int
    {
        return max(self::minChars(), class_exists('Env') ? Env::int('SEARCH_MAX_CHARS', 64) : 64);
    }

    public static function maxTerms(): int
    {
        return max(1, class_exists('Env') ? Env::int('SEARCH_MAX_TERMS', 8) : 8);
    }

    public static function maxPages(): int
    {
        return max(1, class_exists('Env') ? Env::int('SEARCH_MAX_PAGES', 10) : 10);
    }

    /**
     * Normalize a raw query string: strip control characters, collapse whitespace, cap the length.
     * Returns '' when there is nothing left to search for.
     */
    public static function normalize(string $raw): string
    {
        $q = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $raw) ?? '';
        // Treat full-width space as a separator too (a Japanese IME emits it constantly)
        $q = str_replace("\u{3000}", ' ', $q);
        $q = trim(preg_replace('/\s+/u', ' ', $q) ?? '');
        return mb_substr($q, 0, self::maxChars());
    }

    /**
     * Split a normalized query into searchable terms.
     * Terms shorter than minChars() are dropped: with the ngram parser they cannot match anything,
     * and in LIKE mode they would match nearly every row.
     *
     * @return string[]
     */
    public static function terms(string $normalized): array
    {
        if ($normalized === '') {
            return [];
        }
        $min   = self::minChars();
        $terms = [];
        foreach (explode(' ', $normalized) as $t) {
            if ($t !== '' && mb_strlen($t) >= $min) {
                $terms[] = $t;
            }
            if (count($terms) >= self::maxTerms()) {
                break;
            }
        }
        return $terms;
    }

    /** Which matching strategy this install uses. */
    public static function mode(): string
    {
        $mode = class_exists('Env') ? (string)Env::get('SEARCH_MODE', 'auto') : 'auto';
        if ($mode === 'fulltext' || $mode === 'like') {
            return $mode;
        }
        // auto: MySQL has an ngram FULLTEXT index on posts.title.
        // PostgreSQL uses LIKE, accelerated by the pg_trgm GIN index when that extension is available.
        return DB::driver() === 'pgsql' ? 'like' : 'fulltext';
    }

    /**
     * Run a search.
     *
     * @param string $q    raw (not yet normalized) query
     * @param string $lang content language to search within
     * @param int    $page 1-based page number
     * @param int    $size results per page
     * @return array{query:string,terms:string[],posts:array,page:int,totalPages:int,hasNext:bool,tooShort:bool}
     */
    public static function search(string $q, string $lang, int $page, int $size): array
    {
        $query = self::normalize($q);
        $terms = self::terms($query);
        $page  = max(1, min($page, self::maxPages()));

        $empty = [
            'query'      => $query,
            'terms'      => $terms,
            'posts'      => [],
            'page'       => $page,
            'totalPages' => $page,
            'hasNext'    => false,
            'tooShort'   => $query !== '' && $terms === [],
        ];

        if ($terms === []) {
            return $empty;
        }

        [$condition, $params] = self::mode() === 'fulltext'
            ? self::fulltextCondition($terms)
            : self::likeCondition($terms);

        if ($condition === '') {
            return $empty;
        }

        $offset = ($page - 1) * $size;
        $sql    = 'SELECT ' . self::COLUMNS . '
                     FROM posts
                    WHERE status = \'published\' AND deleted_at IS NULL AND lang = ?
                      AND ' . $condition . '
                 ORDER BY published_at DESC
                    LIMIT ? OFFSET ?';

        try {
            // One extra row tells us whether a next page exists without a COUNT(*) over the match set.
            $rows = DB::fetchAll($sql, array_merge([$lang], $params, [$size + 1, $offset]));
        } catch (Throwable $e) {
            // A missing FULLTEXT index (migration not yet applied) surfaces here. Degrade to LIKE
            // rather than 500 on a public page; the operator sees it in the error log.
            error_log('[HBP SEARCH] ' . $e->getMessage());
            [$condition, $params] = self::likeCondition($terms);
            try {
                $rows = DB::fetchAll(
                    'SELECT ' . self::COLUMNS . '
                       FROM posts
                      WHERE status = \'published\' AND deleted_at IS NULL AND lang = ?
                        AND ' . $condition . '
                   ORDER BY published_at DESC
                      LIMIT ? OFFSET ?',
                    array_merge([$lang], $params, [$size + 1, $offset])
                );
            } catch (Throwable) {
                return $empty;
            }
        }

        $hasNext = count($rows) > $size;
        $rows    = array_slice($rows, 0, $size);

        return [
            'query'      => $query,
            'terms'      => $terms,
            'posts'      => $rows,
            'page'       => $page,
            'totalPages' => $page + ($hasNext && $page < self::maxPages() ? 1 : 0),
            'hasNext'    => $hasNext && $page < self::maxPages(),
            'tooShort'   => false,
        ];
    }

    // ------------------------------------------------------------
    // Matching strategies
    // ------------------------------------------------------------

    /**
     * MySQL FULLTEXT (ngram parser) boolean-mode condition.
     *
     * Every term is quoted and prefixed with '+', which means "all terms must appear". With the
     * ngram parser a quoted term is a phrase match over its bigrams, i.e. an effective substring
     * match — the same semantics the old client-side indexOf() filter had.
     *
     * @param string[] $terms
     * @return array{0:string,1:array}
     */
    private static function fulltextCondition(array $terms): array
    {
        $parts = [];
        foreach ($terms as $t) {
            // Strip every boolean-mode operator so a query can never inject search syntax.
            $clean = str_replace(['+', '-', '>', '<', '(', ')', '~', '*', '"', '@'], ' ', $t);
            $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
            if ($clean !== '' && mb_strlen($clean) >= self::minChars()) {
                $parts[] = '+"' . $clean . '"';
            }
        }
        if ($parts === []) {
            return ['', []];
        }
        return ['MATCH (title) AGAINST (? IN BOOLEAN MODE)', [implode(' ', $parts)]];
    }

    /**
     * Portable LIKE condition (PostgreSQL default; also the fallback when FULLTEXT is unavailable).
     * On PostgreSQL the pg_trgm GIN index on lower(title) accelerates this; without the extension
     * it still returns correct results, just with a sequential scan.
     *
     * @param string[] $terms
     * @return array{0:string,1:array}
     */
    private static function likeCondition(array $terms): array
    {
        $clauses = [];
        $params  = [];
        foreach ($terms as $t) {
            $clauses[] = "LOWER(title) LIKE ? ESCAPE '" . self::LIKE_ESCAPE . "'";
            $params[]  = '%' . self::escapeLike(mb_strtolower($t)) . '%';
        }
        if ($clauses === []) {
            return ['', []];
        }
        return ['(' . implode(' AND ', $clauses) . ')', $params];
    }

    /** Neutralize LIKE wildcards so a query cannot widen its own match. */
    private static function escapeLike(string $s): string
    {
        $e = self::LIKE_ESCAPE;
        return str_replace([$e, '%', '_'], [$e . $e, $e . '%', $e . '_'], $s);
    }
}
