<?php
/**
 * HayabusaPress - lang:check command (message catalog completeness).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Message catalog completeness check.
 *
 * English is the master: admin_en.php / frontend_en.php define the full key set, and every other
 * locale is measured against them. Run this before a release, and after any change that adds or
 * renames keys.
 *
 * CLI: php core/cli.php lang:check [locale] [--strict]
 *
 * Reported per locale and per domain (admin / frontend):
 *   missing      keys present in the master but absent here      -> ERROR (English leaks into the UI)
 *   orphan       keys present here but absent from the master    -> ERROR (dead weight, or a typo'd key)
 *   untranslated value identical to the master's English wording -> WARNING (see below)
 *
 * Exit code: 1 when any missing/orphan exists, otherwise 0. "untranslated" is only a warning
 * because a translation legitimately matching English is common (proper nouns, "URL", "SEO").
 * Pass --strict to fail on those too, e.g. in CI for a locale that must be fully translated.
 */
class LangCheck
{
    private const MASTER = 'en';

    /** @return int process exit code */
    public static function run(string $arg = ''): int
    {
        require_once CORE_PATH . '/Locale.php';

        $strict = str_contains($arg, '--strict');
        $only   = trim(preg_replace('/--\S+/', '', $arg) ?? '');

        $langDir = CORE_PATH . '/lang';
        $errors  = 0;
        $warns   = 0;

        foreach (['admin', 'frontend'] as $domain) {
            $masterFile = "{$langDir}/{$domain}_" . self::MASTER . '.php';
            if (!is_file($masterFile)) {
                echo "  [ERROR] master catalog is missing: {$domain}_" . self::MASTER . ".php\n";
                $errors++;
                continue;
            }
            $master  = self::load($masterFile);
            $locales = self::locales($langDir, $domain);

            echo "\n== {$domain} (master: " . self::MASTER . " = " . count($master) . " keys) ==\n";

            foreach ($locales as $locale) {
                if ($locale === self::MASTER || ($only !== '' && $locale !== $only)) {
                    continue;
                }
                $dict = self::load("{$langDir}/{$domain}_{$locale}.php");

                $missing = array_keys(array_diff_key($master, $dict));
                $orphan  = array_keys(array_diff_key($dict, $master));
                $same    = [];
                foreach ($dict as $k => $v) {
                    if (isset($master[$k]) && $v !== '' && $v === $master[$k]) {
                        $same[] = $k;
                    }
                }

                $status = ($missing || $orphan) ? 'ERROR' : (($same && $strict) ? 'ERROR' : (($same) ? 'WARN' : 'OK'));
                printf("  %-6s %-5s  keys=%-4d missing=%-3d orphan=%-3d untranslated=%d\n",
                    $status, $locale, count($dict), count($missing), count($orphan), count($same));

                self::listKeys('missing (English will leak into the UI)', $missing);
                self::listKeys('orphan (not in master -- dead or misspelled)', $orphan);
                self::listKeys('untranslated (same as English)', $same);

                $errors += count($missing) + count($orphan);
                if ($strict) {
                    $errors += count($same);
                } else {
                    $warns += count($same);
                }
            }

            if ($only !== '' && !in_array($only, $locales, true)) {
                echo "  [ERROR] locale '{$only}' has no {$domain}_{$only}.php\n";
                $errors++;
            }
        }

        echo "\n";
        if ($errors > 0) {
            echo "lang:check FAILED -- {$errors} problem(s)"
               . ($warns > 0 ? ", {$warns} warning(s)" : '') . ".\n";
            echo "Add the missing keys (or run lang:publish for a new locale), then lang:rebuild.\n";
            return 1;
        }
        echo 'lang:check passed' . ($warns > 0 ? " with {$warns} warning(s)" : '') . ".\n";
        return 0;
    }

    /** Print at most a handful of keys so a large drift stays readable. */
    private static function listKeys(string $label, array $keys): void
    {
        if ($keys === []) {
            return;
        }
        $show = array_slice($keys, 0, 10);
        echo "         {$label}: " . implode(', ', $show)
           . (count($keys) > count($show) ? ' ... (+' . (count($keys) - count($show)) . ' more)' : '')
           . "\n";
    }

    /** @return array<string,string> */
    private static function load(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $data = require $file;
        return is_array($data) ? $data : [];
    }

    /** Locale codes that actually have a catalog file for this domain. @return string[] */
    private static function locales(string $langDir, string $domain): array
    {
        $out = [];
        foreach (glob("{$langDir}/{$domain}_*.php") ?: [] as $file) {
            if (preg_match('/' . $domain . '_([a-z]{2,5})\.php$/', basename($file), $m)) {
                $out[] = $m[1];
            }
        }
        sort($out);
        return $out;
    }
}
