<?php
/**
 * HayabusaPress - plugins:rebuild command.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Plugin cache regeneration command
 *
 * CLI: php core/cli.php plugins:rebuild
 */
class PluginsRebuild
{
    public static function run(): void
    {
        require_once CORE_PATH . '/PluginManager.php';
        PluginManager::rebuildCache();
        echo "Plugin cache rebuilt.\n";
    }
}
