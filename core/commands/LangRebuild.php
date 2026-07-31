<?php
/**
 * HayabusaPress - lang:rebuild command (view cache regeneration).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * View cache regeneration command (all locales)
 *
 * CLI: php core/cli.php lang:rebuild
 */
class LangRebuild
{
    public static function run(): void
    {
        require_once CORE_PATH . '/ViewCompiler.php';
        require_once CORE_PATH . '/View.php';
        require_once CORE_PATH . '/Locale.php';

        ViewCompiler::clearAll();

        $locales = HbpLocale::getAvailableLocales();
        if (empty($locales)) {
            $locales = ['ja' => 'ja', 'en' => 'en'];
        }

        $adminViews    = glob(CORE_PATH . '/admin_views/*.tpl.php') ?: [];
        $themeName     = class_exists('ThemeManager') ? ThemeManager::active() : 'default';
        $frontendViews = glob(ROOT_PATH . '/themes/' . $themeName . '/*.tpl.php') ?: [];

        foreach (array_keys($locales) as $locale) {
            foreach ($adminViews as $file) {
                $name = 'admin/' . basename($file, '.tpl.php');
                try {
                    ViewCompiler::compile($name, $locale);
                    echo "  compiled: {$name} [{$locale}]\n";
                } catch (Throwable $e) {
                    echo "  SKIP {$name} [{$locale}]: " . $e->getMessage() . "\n";
                }
            }
            foreach ($frontendViews as $file) {
                $name = basename($file, '.tpl.php');
                try {
                    ViewCompiler::compile($name, $locale);
                    echo "  compiled: {$name} [{$locale}]\n";
                } catch (Throwable $e) {
                    echo "  SKIP {$name} [{$locale}]: " . $e->getMessage() . "\n";
                }
            }
        }

        echo "View cache rebuilt for all locales.\n";
    }
}
