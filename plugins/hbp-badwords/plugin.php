<?php
/**
 * HayabusaPress - NG-keyword censorship plugin.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

class HbpBadwordsPlugin
{
    private static string $cacheFile = STORAGE_PATH . '/cache/badwords.cache.php';

    /**
     * Censorship entry point called via a hook (zero DB queries at all)
     */
    public static function interceptRequest(array &$context): bool
    {
        if (!file_exists(self::$cacheFile)) {
            self::rebuildCache();
        }

        $badwords = include self::$cacheFile;

        if (empty($badwords)) {
            return true;
        }

        $postData = $context['post_data'] ?? [];

        $combinedText = '';
        foreach ($postData as $value) {
            if (is_string($value)) {
                $combinedText .= ' ' . $value;
            }
        }

        $redirectBack = $postData['redirect_back']
            ?? $_SERVER['HTTP_REFERER']
            ?? '/';

        foreach ($badwords as $word) {
            if (mb_stripos($combinedText, $word) !== false) {
                self::renderBlockedError($redirectBack);
            }
        }

        return true;
    }

    /**
     * Bake the DB contents into a static PHP array cache on disk
     */
    public static function rebuildCache(): void
    {
        $pdo   = DB::getInstance();
        $words = $pdo->query("SELECT word FROM badwords ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);

        $cacheDir = dirname(self::$cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $code = "<?php\nreturn " . var_export($words, true) . ";\n";
        file_put_contents(self::$cacheFile, $code, LOCK_EX);
    }

    private static function renderBlockedError(string $redirectBack): void   // ends with exit (no "never": PHP 8.0)
    {
        http_response_code(400);
        $back = htmlspecialchars($redirectBack, ENT_QUOTES, 'UTF-8');
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Forbidden Content | HayabusaPress</title>
        <script src="https://cdn.tailwindcss.com"></script>
        </head>
        <body class="bg-slate-50 text-slate-900 flex items-center justify-center min-h-screen font-sans p-4 antialiased">
            <div class="w-full max-w-md bg-white border border-slate-200 p-6 sm:p-8 rounded-2xl shadow-sm space-y-4">
                <div class="flex items-center gap-2 text-rose-500 font-mono text-xs font-bold uppercase tracking-wider">
                    <span>●</span><span>Inappropriate Content Detected</span>
                </div>
                <h1 class="text-xl font-bold tracking-tight">Your submission could not be processed</h1>
                <p class="text-xs text-slate-500 leading-relaxed font-medium">
                    The submission was rejected because it contains forbidden keywords.
                </p>
                <a href="{$back}" class="inline-block w-full text-center bg-slate-950 hover:bg-slate-800 text-white font-semibold text-xs py-3 rounded-xl transition shadow-sm">
                    Go back and edit the form
                </a>
            </div>
        </body>
        </html>
        HTML;
        exit;
    }
}
