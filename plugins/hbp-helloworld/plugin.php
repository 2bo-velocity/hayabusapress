<?php
/**
 * HayabusaPress - Hello World demo plugin.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * HBP Hello World Plugin
 *
 * A minimal plugin demo. The minimal setup where a new route is born from just two files: manifest.json and plugin.php.
 * A 3-minute "hello world" tutorial for plugin development.
 */
class HbpHelloworldPlugin
{
    /** Render the demo page */
    public static function renderPage(array &$context): void
    {
        $defaultLang = class_exists('Env') ? Env::get('SITE_DEFAULT_LANG', 'en') : ($_ENV['SITE_DEFAULT_LANG'] ?? 'en');
        $lang        = defined('CURRENT_LANG') ? CURRENT_LANG : $defaultLang;
        $siteName    = class_exists('Env') ? Env::get('SITE_NAME', 'HayabusaPress') : ($_ENV['SITE_NAME'] ?? 'HayabusaPress');
        $siteUrl     = rtrim(class_exists('Env') ? Env::get('SITE_URL', '') : ($_ENV['SITE_URL'] ?? ''), '/');
        $menus       = MenuCompiler::load();
        $seoHead     = SeoCompiler::loadHead();
        $seoGlobal   = SeoCompiler::loadGlobal();
        $dict        = HbpLocale::loadFrontendDictionary($lang);
        $seoMeta     = '<title>' . htmlspecialchars(($dict['hbp-helloworld.title'] ?? 'Hello World') . ' — ' . $siteName, ENT_QUOTES, 'UTF-8') . '</title>';
        $sidebarRight = SidebarCompiler::loadSidebar('sidebar_right', $lang);
        $sidebarLeft  = SidebarCompiler::loadSidebar('sidebar_left', $lang);

        View::render('plugin/hbp-helloworld/view', compact(
            'lang', 'defaultLang', 'siteName', 'siteUrl',
            'menus', 'seoHead', 'seoGlobal', 'seoMeta',
            'sidebarRight', 'sidebarLeft'
        ));
        exit;
    }
}
