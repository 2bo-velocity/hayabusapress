<?php
/**
 * HayabusaPress - Social-post text sized for X's weighted character count.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * X counts a post against 280 "weighted" characters: code points in the ranges below weigh 1, every
 * other code point (CJK, most emoji) weighs 2, and any URL counts as 23 however long it is. The text
 * built here leaves room for the post URL, which the automation appends.
 *
 * The weights follow twitter-text v3 (config/v3.json). Emoji sequences are counted per code point,
 * which overestimates them slightly — the safe direction for a limit.
 */
final class ShareText
{
    public const MAX_WEIGHT = 280;
    public const URL_WEIGHT = 23;

    /** Room kept for "\n" + the post URL. */
    public const URL_RESERVE = 1 + self::URL_WEIGHT;

    /** Code-point ranges that weigh 1 (twitter-text v3). */
    private const LIGHT = [[0x0000, 0x10FF], [0x2000, 0x200D], [0x2010, 0x201F], [0x2032, 0x2037]];

    public static function weight(string $text): int
    {
        $w = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $cp = mb_ord($ch, 'UTF-8');
            $w += self::isLight((int)$cp) ? 1 : 2;
        }
        return $w;
    }

    /** Weight of what the editor typed: URLs inside count as 23 each. */
    public static function weightWithUrls(string $text): int
    {
        $urls = preg_match_all('#https?://\S+#u', $text, $m);
        $rest = preg_replace('#https?://\S+#u', '', $text) ?? $text;
        return self::weight($rest) + $urls * self::URL_WEIGHT;
    }

    /**
     * "{title}\n{excerpt}\n{#tag #tag}" within MAX_WEIGHT - URL_RESERVE. The excerpt is shortened
     * first, then trailing hashtags are dropped, and only then is the title cut.
     *
     * @param string[] $hashtags without or with the leading '#'
     */
    public static function build(string $title, string $excerpt, array $hashtags): string
    {
        $budget = self::MAX_WEIGHT - self::URL_RESERVE;
        $title  = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        $excerpt = trim(preg_replace('/\s+/u', ' ', $excerpt) ?? $excerpt);
        $tags   = array_values(array_filter(array_map(
            static fn($t) => '#' . ltrim(trim((string)$t), '#'),
            $hashtags
        ), static fn($t) => $t !== '#'));

        $compose = static function (string $t, string $e, array $h): string {
            return implode("\n", array_filter([$t, $e, implode(' ', $h)], static fn($p) => $p !== ''));
        };

        $text = $compose($title, $excerpt, $tags);
        if (self::weight($text) <= $budget) {
            return $text;
        }

        // 1. shorten the excerpt
        if ($excerpt !== '') {
            $fixed = self::weight($compose($title, '', $tags)) + 1;   // + the newline the excerpt brings
            $room  = $budget - $fixed - self::weight('…');              // '…' (U+2026) weighs 2
            $cut   = $room > 0 ? self::cut($excerpt, $room) : '';
            $text  = $compose($title, $cut === '' ? '' : $cut . '…', $tags);
            if (self::weight($text) <= $budget) {
                return $text;
            }
            $excerpt = '';
        }
        // 2. drop hashtags from the end
        while ($tags !== [] && self::weight($compose($title, '', $tags)) > $budget) {
            array_pop($tags);
        }
        $text = $compose($title, '', $tags);
        if (self::weight($text) <= $budget) {
            return $text;
        }
        // 3. cut the title
        return self::cut($title, $budget - self::weight('…')) . '…';
    }

    /** Longest prefix of $text whose weight is at most $max. */
    private static function cut(string $text, int $max): string
    {
        $out = '';
        $w   = 0;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $cw = self::isLight((int)mb_ord($ch, 'UTF-8')) ? 1 : 2;
            if ($w + $cw > $max) {
                break;
            }
            $out .= $ch;
            $w   += $cw;
        }
        return rtrim($out);
    }

    private static function isLight(int $cp): bool
    {
        foreach (self::LIGHT as [$lo, $hi]) {
            if ($cp >= $lo && $cp <= $hi) {
                return true;
            }
        }
        return false;
    }
}
