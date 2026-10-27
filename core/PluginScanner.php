<?php
/**
 * HayabusaPress - What a plugin's code does, as warnings for the person installing it.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Reads a plugin's files and lists what deserves a second look: running code or commands,
 * calling or including something chosen at run time, signs of obfuscation, changing global state,
 * talking to other servers, writing files, loading scripts from elsewhere.
 *
 * This is NOT a way to find malicious code, and it never blocks anything. Code can always be
 * written so that a static reading misses it (a function name built from strings, a callback held
 * in a variable). It is a guide to what a plugin does, shown on the review and on the changed-files
 * screen, with what is new since the previous version brought forward. What blocks an update is a
 * syntax error (PluginInstaller::syntaxErrors()) or a missing signature, not this.
 *
 * PHP is read as tokens (PhpToken), never run, so text inside strings and comments is not taken
 * for code. Levels: warning (worth asking about) and info (normal for many plugins, listed so the
 * reader knows).
 */
class PluginScanner
{
    private const CODE_EXEC    = ['assert', 'create_function'];
    private const COMMANDS     = ['exec', 'system', 'passthru', 'shell_exec', 'proc_open', 'popen', 'pcntl_exec'];
    private const CALLBACKERS  = ['call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array'];
    private const DECODERS     = ['base64_decode', 'gzinflate', 'gzuncompress', 'gzdecode', 'str_rot13', 'hex2bin', 'convert_uudecode'];
    private const STATE        = ['ini_set', 'set_error_handler', 'set_exception_handler', 'putenv'];
    private const NETWORK      = ['fsockopen', 'pfsockopen', 'stream_socket_client', 'curl_init', 'curl_exec', 'curl_multi_exec'];
    private const FILE_WRITE   = ['file_put_contents', 'unlink', 'rename', 'chmod', 'chown', 'rmdir', 'mkdir', 'copy', 'move_uploaded_file'];
    /** Constants a plugin may use to build an include path (the core's own folders). */
    private const PATH_CONSTS  = ['ROOT_PATH', 'CORE_PATH', 'STORAGE_PATH', 'PUBLIC_PATH'];
    private const LONG_LINE    = 2000;

    /**
     * @param array|null $manifest the plugin's manifest (to find its content_render handler)
     * @return array<int,array{file:string,line:int,rule:string,level:string,detail:string}>
     */
    public static function scan(string $dir, ?array $manifest = null): array
    {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            return [];
        }
        $filterFile   = null;
        $filterMethod = '';
        if (isset($manifest['hooks']['content_render']['file']) && is_string($manifest['hooks']['content_render']['file'])) {
            $filterFile   = basename($manifest['hooks']['content_render']['file']);
            $filterMethod = (string)($manifest['hooks']['content_render']['method'] ?? '');
        }
        $out = [];
        $it  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }
            $rel  = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            $ext  = strtolower($file->getExtension());
            $code = (string)file_get_contents($file->getPathname());
            if ($ext === 'php') {
                array_push($out, ...self::scanPhp($rel, $code, $filterFile !== null && basename($rel) === $filterFile ? $filterMethod : ''));
                // A template (.tpl.php) is mostly HTML: look at it as markup too
                array_push($out, ...self::scanMarkup($rel, $code));
            } elseif (in_array($ext, ['html', 'js'], true)) {
                array_push($out, ...self::scanMarkup($rel, $code));
            }
        }
        usort($out, static fn($a, $b) => [$a['level'] === 'info', $a['file'], $a['line']] <=> [$b['level'] === 'info', $b['file'], $b['line']]);
        return $out;
    }

    /**
     * Mark the findings that are new compared with $previous (another version's scan): for each
     * rule in each file, those beyond the number the previous version had.
     */
    public static function markNew(array $findings, array $previous): array
    {
        $before = [];
        foreach ($previous as $f) {
            $k = $f['rule'] . '|' . $f['file'];
            $before[$k] = ($before[$k] ?? 0) + 1;
        }
        $seen = [];
        foreach ($findings as &$f) {
            $k = $f['rule'] . '|' . $f['file'];
            $seen[$k] = ($seen[$k] ?? 0) + 1;
            $f['new'] = $seen[$k] > ($before[$k] ?? 0);
        }
        unset($f);
        return $findings;
    }

    /** @param string $filterMethod the content_render handler defined in this file, or '' */
    private static function scanPhp(string $rel, string $code, string $filterMethod): array
    {
        $out  = [];
        $add  = static function (int $line, string $rule, string $level, string $detail) use (&$out, $rel): void {
            $out[] = ['file' => $rel, 'line' => $line, 'rule' => $rule, 'level' => $level, 'detail' => $detail];
        };

        foreach (explode("\n", $code) as $i => $line) {
            if (strlen($line) > self::LONG_LINE) {
                $add($i + 1, 'obfuscation', 'warning', 'a line of ' . strlen($line) . ' characters');
            }
        }

        try {
            $all = PhpToken::tokenize($code);
        } catch (Throwable) {
            return $out;   // syntax errors are reported by PluginInstaller::syntaxErrors()
        }
        $t = array_values(array_filter($all, static fn(PhpToken $x) => !$x->isIgnorable()));
        $n = count($t);

        // Variables holding a closure written in this file ($f = static fn(...) => ...): calling
        // them is not calling something chosen at run time
        $closures = [];
        for ($i = 0; $i + 2 < $n; $i++) {
            if ($t[$i]->is(T_VARIABLE) && $t[$i + 1]->text === '='
                && ($t[$i + 2]->is([T_FN, T_FUNCTION]) || ($t[$i + 2]->is(T_STATIC) && isset($t[$i + 3]) && $t[$i + 3]->is([T_FN, T_FUNCTION])))) {
                $closures[$t[$i]->text] = true;
            }
        }
        // The token range of the content_render handler's body, where exit would stop the save
        [$filterFrom, $filterTo] = $filterMethod !== '' ? self::methodBody($t, $filterMethod) : [-1, -1];

        for ($i = 0; $i < $n; $i++) {
            $tok  = $t[$i];
            $prev = $t[$i - 1] ?? null;
            $next = $t[$i + 1] ?? null;

            if ($tok->is(T_EVAL)) {
                $add($tok->line, 'code_exec', 'warning', 'eval');
                continue;
            }
            if ($tok->text === '`') {
                $add($tok->line, 'command_exec', 'warning', 'backtick operator');
                // skip to the closing backtick
                for ($i++; $i < $n && $t[$i]->text !== '`'; $i++);
                continue;
            }
            if ($tok->is(T_EXIT) && $i > $filterFrom && $i < $filterTo) {
                $add($tok->line, 'filter_exit', 'warning', $tok->text . ' in the content_render handler (it would stop the save)');
                continue;
            }
            if ($tok->text === '$' && $next !== null && ($next->is(T_VARIABLE) || $next->text === '{')) {
                $add($tok->line, 'dynamic_call', 'warning', 'variable variable');
                continue;
            }
            if ($tok->is(T_VARIABLE) && $next !== null && $next->text === '(' && !isset($closures[$tok->text])
                && !($prev !== null && ($prev->is(T_NEW) || $prev->is(T_FUNCTION)))) {
                $add($tok->line, 'dynamic_call', 'warning', $tok->text . '(...): a function chosen at run time');
                continue;
            }
            if ($tok->is([T_VARIABLE]) && in_array($tok->text, ['$_SESSION', '$GLOBALS'], true) && self::isAssigned($t, $i)) {
                $add($tok->line, 'state', 'warning', 'writes ' . $tok->text);
                continue;
            }
            if ($tok->is([T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE]) && !self::staticPath($t, $i + 1)) {
                $add($tok->line, 'dynamic_include', 'warning', $tok->text . ' of a path chosen at run time');
                continue;
            }
            if ($tok->is(T_CONSTANT_ENCAPSED_STRING) && preg_match('/(\\\\x[0-9a-fA-F]{2}){20,}/', $tok->text)) {
                $add($tok->line, 'obfuscation', 'warning', 'a long run of \\x escapes');
                continue;
            }

            // Function calls: a name followed by "(", not a method, a definition or a "new"
            $name = self::calledFunction($tok, $prev, $next);
            if ($name === null) {
                continue;
            }
            $line = $tok->line;
            match (true) {
                in_array($name, self::CODE_EXEC, true)   => $add($line, 'code_exec', 'warning', $name),
                in_array($name, self::COMMANDS, true)    => $add($line, 'command_exec', 'warning', $name),
                in_array($name, self::CALLBACKERS, true) && isset($t[$i + 2]) && $t[$i + 2]->is(T_VARIABLE)
                                                         => $add($line, 'dynamic_call', 'warning', $name . ' with a callback held in a variable'),
                in_array($name, self::DECODERS, true) && self::nestedDecoder($t, $i + 1)
                                                         => $add($line, 'obfuscation', 'warning', $name . ' of decoded data'),
                in_array($name, self::STATE, true)       => $add($line, 'state', 'warning', $name),
                in_array($name, self::NETWORK, true)     => $add($line, 'network', 'info', $name),
                in_array($name, ['file_get_contents', 'fopen', 'file', 'readfile'], true)
                    && isset($t[$i + 2]) && $t[$i + 2]->is(T_CONSTANT_ENCAPSED_STRING) && preg_match('#^["\']https?://#i', $t[$i + 2]->text)
                                                         => $add($line, 'network', 'info', $name . ' ' . trim($t[$i + 2]->text, '"\'')),
                in_array($name, self::FILE_WRITE, true)  => $add($line, 'file_write', 'info', $name),
                $name === 'fopen' && self::writeMode($t, $i + 1)
                                                         => $add($line, 'file_write', 'info', 'fopen for writing'),
                default                                  => null,
            };
        }
        return $out;
    }

    /** Scripts loaded from another server by a snippet or a .js file. */
    private static function scanMarkup(string $rel, string $code): array
    {
        $out = [];
        $patterns = [
            '#<script\b[^>]*\bsrc\s*=\s*["\']?(https?:)?//([^/"\'\s>]+)#i',
            '#\bimport\b[^;]*?["\'](?:https?:)?//([^/"\']+)#i',
        ];
        foreach (explode("\n", $code) as $i => $line) {
            foreach ($patterns as $p) {
                if (preg_match($p, $line, $m)) {
                    $out[] = ['file' => $rel, 'line' => $i + 1, 'rule' => 'external_script', 'level' => 'info', 'detail' => end($m)];
                }
            }
        }
        return $out;
    }

    /** @return array{0:int,1:int} token indexes of the body braces of method $name, or [-1, -1] */
    private static function methodBody(array $t, string $name): array
    {
        for ($i = 0, $n = count($t); $i + 1 < $n; $i++) {
            if (!$t[$i]->is(T_FUNCTION) || !$t[$i + 1]->is(T_STRING) || strcasecmp($t[$i + 1]->text, $name) !== 0) {
                continue;
            }
            for ($j = $i + 2; $j < $n && $t[$j]->text !== '{'; $j++);
            $depth = 0;
            for ($k = $j; $k < $n; $k++) {
                $x = $t[$k]->text;
                if ($x === '{' || $t[$k]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif ($x === '}' && --$depth === 0) {
                    return [$j, $k];
                }
            }
        }
        return [-1, -1];
    }

    /** The lowercased name of the function this token calls, or null when it is not a call. */
    private static function calledFunction(PhpToken $tok, ?PhpToken $prev, ?PhpToken $next): ?string
    {
        if ($next === null || $next->text !== '(' || !$tok->is([T_STRING, T_NAME_FULLY_QUALIFIED])) {
            return null;
        }
        if ($prev !== null && $prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST])) {
            return null;
        }
        return strtolower(ltrim($tok->text, '\\'));
    }

    /** Whether an include's argument (tokens from $i to ";") is fixed: string literals, __DIR__, the core's path constants. */
    private static function staticPath(array $t, int $i): bool
    {
        for ($n = count($t); $i < $n && $t[$i]->text !== ';'; $i++) {
            $x = $t[$i];
            if ($x->is([T_CONSTANT_ENCAPSED_STRING, T_DIR, T_FILE]) || in_array($x->text, ['.', '(', ')'], true)
                || ($x->is(T_STRING) && in_array($x->text, self::PATH_CONSTS, true))) {
                continue;
            }
            return false;
        }
        return true;
    }

    /** Whether the variable at $i is assigned to ($_SESSION['x'] = …, $GLOBALS['x'] .= …). */
    private static function isAssigned(array $t, int $i): bool
    {
        $depth = 0;
        for ($i++, $n = count($t); $i < $n; $i++) {
            $x = $t[$i]->text;
            if ($x === '[') {
                $depth++;
            } elseif ($x === ']') {
                $depth--;
            } elseif ($depth === 0) {
                return $x === '=' || $t[$i]->is([T_CONCAT_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL, T_COALESCE_EQUAL]);
            }
        }
        return false;
    }

    /** Whether the arguments of the call whose "(" is at $i contain another decoder (base64_decode(gzinflate(…))). */
    private static function nestedDecoder(array $t, int $i): bool
    {
        $depth = 0;
        for ($n = count($t); $i < $n; $i++) {
            $x = $t[$i];
            $depth += $x->text === '(' ? 1 : ($x->text === ')' ? -1 : 0);
            if ($depth === 0) {
                return false;
            }
            if ($x->is(T_STRING) && in_array(strtolower($x->text), self::DECODERS, true)) {
                return true;
            }
        }
        return false;
    }

    /** Whether fopen()'s second argument is a literal mode that writes (w, a, x, c, +). */
    private static function writeMode(array $t, int $i): bool
    {
        $depth = 0;
        $comma = false;
        for ($n = count($t); $i < $n; $i++) {
            $x = $t[$i]->text;
            if ($x === '(') {
                $depth++;
            } elseif ($x === ')') {
                if (--$depth === 0) {
                    return false;
                }
            } elseif ($x === ',' && $depth === 1) {
                $comma = true;
            } elseif ($comma && $t[$i]->is(T_CONSTANT_ENCAPSED_STRING)) {
                return (bool)preg_match('/[waxc+]/', trim($x, '"\''));
            }
        }
        return false;
    }
}
