<!-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE -->
<!DOCTYPE html>
<html lang="{{ $lang ?? 'en' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>404 Not Found — {{ $siteName }}</title>
<link rel="stylesheet" href="{{ ThemeManager::assetBase() }}/style.css">
</head>
<body>
<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:100vh;text-align:center;padding:2rem;">
    <h1 style="font-size:4rem;font-weight:bold;color:#e2e8f0;margin:0;">404</h1>
    <p style="color:#64748b;margin:.5rem 0 1.5rem;">{@ not_found @}</p>
    <a href="/" style="color:#10b981;text-decoration:underline;">{@ back_to_top @}</a>
</div>
</body>
</html>
