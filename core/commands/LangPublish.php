<?php
/**
 * HayabusaPress - lang:publish command (scaffold a new locale from the English master).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Scaffold the catalogs for a new locale.
 *
 * CLI: php core/cli.php lang:publish {code}
 *
 * The scaffold is a verbatim copy of the English master with only the doc header changed. Copying
 * rather than regenerating from the parsed array is deliberate: it preserves the section comments
 * and column alignment of the master, which is what makes a 300-key file translatable by hand.
 * Every value therefore starts out in English -- lang:check reports them as "untranslated" until
 * they are replaced, which is exactly the intended worklist.
 *
 * Existing files are never overwritten, so running this twice cannot destroy work in progress.
 */
class LangPublish
{
    private const MASTER  = 'en';
    private const DOMAINS = ['admin', 'frontend'];

    /** @return int process exit code */
    public static function run(string $code): int
    {
        $code = strtolower(trim($code));

        if (!preg_match('/^[a-z]{2,5}$/', $code)) {
            echo "lang:publish requires a locale code of 2-5 lowercase letters.\n";
            echo "  usage: php core/cli.php lang:publish es\n";
            return 1;
        }
        if ($code === self::MASTER) {
            echo "'" . self::MASTER . "' is the master catalog and cannot be republished.\n";
            return 1;
        }

        $langDir = CORE_PATH . '/lang';
        $created = 0;
        $skipped = 0;

        foreach (self::DOMAINS as $domain) {
            $master = "{$langDir}/{$domain}_" . self::MASTER . '.php';
            $dest   = "{$langDir}/{$domain}_{$code}.php";

            if (!is_file($master)) {
                echo "  [ERROR] master catalog is missing: {$domain}_" . self::MASTER . ".php\n";
                return 1;
            }
            if (file_exists($dest)) {
                echo "  skipped : {$domain}_{$code}.php already exists (left untouched)\n";
                $skipped++;
                continue;
            }

            $source = file_get_contents($master);
            if ($source === false) {
                echo "  [ERROR] cannot read {$master}\n";
                return 1;
            }
            if (file_put_contents($dest, self::rewriteHeader($source, $domain, $code), LOCK_EX) === false) {
                echo "  [ERROR] cannot write {$dest} -- check the directory permission.\n";
                return 1;
            }

            $keys = self::countKeys($dest);
            echo "  created : {$domain}_{$code}.php ({$keys} keys, all still in English)\n";
            $created++;
        }

        echo "\n";
        if ($created === 0) {
            echo "Nothing to do -- locale '{$code}' already exists.\n";
            return 0;
        }

        echo "Locale '{$code}' scaffolded. Next:\n";
        echo "  1. Translate the values in core/lang/*_{$code}.php (keys must stay as they are).\n";
        echo "  2. php core/cli.php lang:check {$code}    # lists what is still English\n";
        echo "  3. php core/cli.php lang:rebuild {$code}  # bake the translations into the view cache\n";
        if ($skipped > 0) {
            echo "Note: {$skipped} file(s) already existed and were left untouched.\n";
        }
        return 0;
    }

    /**
     * Retitle the copied docblock so the file does not claim to be the English master,
     * and leave a note that the values are placeholders.
     */
    private static function rewriteHeader(string $source, string $domain, string $code): string
    {
        $upper = strtoupper($code);
        $label = $domain === 'admin' ? 'Admin UI dictionary' : 'Front-end dictionary';

        $source = str_replace(
            " * HayabusaPress - {$label} (English).",
            " * HayabusaPress - {$label} ({$upper}).\n"
            . " *\n"
            . " * Scaffolded from the English master by: php core/cli.php lang:publish {$code}\n"
            . " * Every value below is still English -- translate the values, never the keys.\n"
            . " * Run 'php core/cli.php lang:check {$code}' to see what is left.",
            $source
        );
        return $source;
    }

    /** Count the keys actually produced, so the operator can sanity-check the copy. */
    private static function countKeys(string $file): int
    {
        $data = require $file;
        return is_array($data) ? count($data) : 0;
    }
}
