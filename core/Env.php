<?php
/**
 * HayabusaPress - Single .env parser (replacement for parse_ini_file()).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * C-series: single parser for the .env file. Replacement after fully removing parse_ini_file().
 * Round-trips values containing bcrypt ($), Japanese, base64 (+/=), URLs (#/;), and whitespace without loss.
 */
class Env
{
    private static array $map = [];

    /**
     * Parse the .env file and return an array.
     * Returns [] when the file does not exist or cannot be read (used to detect "not installed").
     */
    public static function parseFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        return self::parse($content);
    }

    /**
     * Parse .env text and return an array.
     */
    public static function parse(string $content): array
    {
        $result = [];
        // Strip UTF-8 BOM
        $content = ltrim($content, "\xEF\xBB\xBF");
        $lines = preg_split('/\r\n|\r|\n/', $content);

        foreach ($lines as $line) {
            // Strip leading whitespace and skip comment/blank lines
            $trimmed = ltrim($line);
            if ($trimmed === '' || $trimmed[0] === '#') {
                continue;
            }
            // Strip the export prefix
            if (str_starts_with($trimmed, 'export ')) {
                $trimmed = substr($trimmed, 7);
            }
            // Split KEY/VALUE at the first =
            $eqPos = strpos($trimmed, '=');
            if ($eqPos === false) {
                continue;
            }
            $key   = rtrim(substr($trimmed, 0, $eqPos));
            $value = substr($trimmed, $eqPos + 1);

            // KEY validation
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            $result[$key] = self::parseValue($value);
        }
        return $result;
    }

    /**
     * Handle the value's quoting.
     * Single quotes: fully literal (no escapes)
     * Double quotes: minimal escapes only (\n \t \" \\)
     * Unquoted: literal with surrounding whitespace trimmed
     */
    private static function parseValue(string $raw): string
    {
        $raw = rtrim($raw);
        if ($raw === '') {
            return '';
        }
        // Single quotes
        if (strlen($raw) >= 2 && $raw[0] === "'" && $raw[-1] === "'") {
            return substr($raw, 1, -1);
        }
        // Double quotes
        if (strlen($raw) >= 2 && $raw[0] === '"' && $raw[-1] === '"') {
            $inner = substr($raw, 1, -1);
            return strtr($inner, ['\\n' => "\n", '\\t' => "\t", '\\"' => '"', '\\\\' => '\\']);
        }
        // Unquoted: only trim surrounding whitespace (a # mid-line is preserved as a URL fragment, etc.)
        return trim($raw);
    }

    /**
     * Inject the parse result into $_ENV / putenv() and also store it in the static map.
     */
    public static function hydrate(array $vars): void
    {
        foreach ($vars as $k => $v) {
            self::$map[$k] = $v;
            if (!array_key_exists($k, $_ENV)) {
                $_ENV[$k] = $v;
                putenv("{$k}={$v}");
            }
        }
    }

    /** Get a value from the static map (with a fallback to $_ENV). */
    public static function get(string $key, string $default = ''): string
    {
        return self::$map[$key] ?? ($_ENV[$key] ?? $default);
    }

    /** bool conversion ('true'/'1'/'yes'/'on' -> true). */
    public static function bool(string $key, bool $default = false): bool
    {
        $v = strtolower(trim(self::get($key, $default ? 'true' : 'false')));
        return in_array($v, ['true', '1', 'yes', 'on'], true);
    }

    /** int conversion. */
    public static function int(string $key, int $default = 0): int
    {
        $v = self::get($key, (string)$default);
        return is_numeric($v) ? (int)$v : $default;
    }

    /**
     * Load from env.cache.php (an optional speed-up).
     * If the cache is older than .env, ignore it and re-parse.
     */
    public static function loadWithCache(string $envPath, string $cachePath): array
    {
        if (is_file($cachePath) && is_file($envPath)
            && filemtime($cachePath) >= filemtime($envPath)) {
            $vars = require $cachePath;
            if (is_array($vars)) {
                return $vars;
            }
        }
        $vars = self::parseFile($envPath);
        if ($vars !== []) {
            self::writeCache($vars, $cachePath);
        }
        return $vars;
    }

    private static function writeCache(array $vars, string $path): void
    {
        $export = var_export($vars, true);
        $php    = "<?php\n// HBP Env Cache - " . date('Y-m-d H:i:s') . "\nreturn {$export};\n";
        $tmp    = $path . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, $php, LOCK_EX);
        rename($tmp, $path);
    }
}
