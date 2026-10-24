<!-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE -->
<!DOCTYPE html>
<html lang="{{ $lang ?? 'en' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>404 Not Found — {{ $siteName }}</title>
<link rel="stylesheet" href="{{ ThemeManager::assetBase() }}/style.css">
<?php echo PluginManager::snippet('head'); ?>
</head>
<body>
<div class="hbp-404">
    <h1 class="hbp-404-code">404</h1>
    <p class="hbp-404-text">{@ not_found @}</p>
    <a href="/" class="hbp-404-link">{@ back_to_top @}</a>
</div>
<?php echo PluginManager::snippet('body_end'); ?>
</body>
</html>
