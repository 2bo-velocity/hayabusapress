<?php
/**
 * HayabusaPress - Concealed installer (zero-I/O under normal operation).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Concealed installer (zero-I/O design under normal operation)
 *
 * Does not physically place public/install.php. It is started only from
 * public/index.php's @parse_ini_file() decision.
 *
 * After installation completes, .env exists, so this class is
 * never loaded into memory again (preventing zombification).
 */
class Installer
{
    /** Display the installer UI (a console-style Tailwind screen) */
    public static function renderForm(): void
    {
        http_response_code(200);
        header('Content-Type: text/html; charset=UTF-8');
        ?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HayabusaPress — Setup</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>
tailwind.config = { darkMode: 'class' };
(function(){
    const t = localStorage.getItem('hbp-theme') || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    document.documentElement.classList.toggle('dark', t === 'dark');
})();
</script>
</head>
<body class="bg-white dark:bg-black text-slate-900 dark:text-slate-100 font-mono min-h-screen flex items-center justify-center p-4">
<div class="w-full max-w-2xl">
    <div class="mb-8 text-center">
        <h1 class="text-3xl font-bold tracking-tight">HayabusaPress</h1>
        <p class="text-slate-500 dark:text-slate-400 mt-1 text-sm">Core Matrix Initialization</p>
    </div>

    <?php
    // environment requirement check (clearly show missing required extensions, especially gd = required for EXIF removal)
    $reqExt = [
        'dom'      => 'HTML sanitizer (stored-XSS defense)',
        'gd'       => 'Image EXIF/metadata stripping ( — uploads rejected without it)',
        'fileinfo' => 'MIME validation (anti spoofing)',
        'mbstring' => 'Multibyte handling',
        'zip'      => 'Plugin ZIP installs',
    ];
    $missing = array_keys(array_filter($reqExt, static fn($d, $e) => !extension_loaded($e), ARRAY_FILTER_USE_BOTH));
    $hasPdo  = extension_loaded('pdo_mysql') || extension_loaded('pdo_pgsql');
    if ($missing !== [] || !$hasPdo):
    ?>
    <div class="mb-6 border border-amber-400 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 rounded-xl p-5 text-sm">
        <h2 class="font-semibold text-amber-700 dark:text-amber-300 mb-2"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block align-text-bottom mr-1"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>Missing PHP extensions</h2>
        <ul class="space-y-1 text-amber-800 dark:text-amber-200">
            <?php if (!$hasPdo): ?>
            <li><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block align-text-bottom mr-1"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg><code>pdo_mysql</code> / <code>pdo_pgsql</code> — at least one is required for the database.</li>
            <?php endif; ?>
            <?php foreach ($missing as $e): ?>
            <li><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block align-text-bottom mr-1"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg><code><?= htmlspecialchars($e) ?></code> — <?= htmlspecialchars($reqExt[$e]) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">Ask your host to enable these. Without <code>gd</code>, image uploads are rejected (EXIF removal cannot be guaranteed).</p>
    </div>
    <?php else: ?>
    <div class="mb-6 border border-emerald-300 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/30 rounded-xl p-3 text-xs text-emerald-700 dark:text-emerald-400">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block align-text-bottom mr-1"><path d="M20 6 9 17l-5-5"/></svg>All required PHP extensions are available.
    </div>
    <?php endif; ?>

    <?php
    // pre-validate whether the three media parent directories can be created/written.
    //   If missing, try to create them (default 0755); if not possible, show it clearly. Failure causes attachments inconsistency at upload time.
    //   Keep storage/upload placed outside the public root + fully denied by the web server.
    $mediaDirMode = (int)intval(Env::get('MEDIA_DIR_MODE', '0755') ?: '0755', 8);
    $mediaDirs = [
        'storage/upload'      => STORAGE_PATH . '/upload',
        'assets/articles'     => PUBLIC_PATH  . '/assets/articles',
        'assets/site/avatars' => PUBLIC_PATH  . '/assets/site/avatars',
    ];
    $mediaProblems = [];
    foreach ($mediaDirs as $label => $path) {
        if (!is_dir($path)) {
            @mkdir($path, $mediaDirMode, true);
            @chmod($path, $mediaDirMode);
        }
        if (!is_dir($path) || !is_writable($path)) {
            $mediaProblems[$label] = $path;
        }
    }
    if ($mediaProblems !== []):
    ?>
    <div class="mb-6 border border-amber-400 dark:border-amber-700 bg-amber-50 dark:bg-amber-950/40 rounded-xl p-5 text-sm">
        <h2 class="font-semibold text-amber-700 dark:text-amber-300 mb-2"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block align-text-bottom mr-1"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>Media directories not writable</h2>
        <ul class="space-y-1 text-amber-800 dark:text-amber-200">
            <?php foreach ($mediaProblems as $label => $path): ?>
            <li><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block align-text-bottom mr-1"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg><code><?= htmlspecialchars($label) ?></code> — <?= htmlspecialchars($path) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="mt-2 text-xs text-amber-700 dark:text-amber-400">Create these directories and make them writable (mode <?= sprintf('0%o', $mediaDirMode) ?>). Without write access, image/file uploads will fail.</p>
    </div>
    <?php else: ?>
    <div class="mb-6 border border-emerald-300 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/30 rounded-xl p-3 text-xs text-emerald-700 dark:text-emerald-400">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block align-text-bottom mr-1"><path d="M20 6 9 17l-5-5"/></svg>Media directories are present and writable.
    </div>
    <?php endif; ?>

    <form method="post" action="/install/initialize" class="space-y-6">

        <!-- Global Site Configuration -->
        <div class="border border-slate-200 dark:border-slate-800 rounded-xl p-5 space-y-4">
            <h2 class="text-sm font-semibold text-emerald-500 uppercase tracking-widest">Global Site Configuration</h2>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Site Name</label>
                <input type="text" name="site_name" value="HayabusaPress" required
                       class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Canonical URL</label>
                <input type="url" name="site_url" placeholder="https://example.com" required
                       class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Default Language</label>
                <select name="site_default_lang"
                        class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
                    <option value="en">en — English</option>
                    <option value="ja">ja — 日本語</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Date format (date-only)</label>
                <select name="date_format"
                        class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
                    <option value="Y-m-d">2026-06-02 (Y-m-d)</option>
                    <option value="Y/m/d">2026/06/02 (Y/m/d)</option>
                    <option value="Y年n月j日">2026年6月2日 (Y年n月j日)</option>
                    <option value="Y年m月d日">2026年06月02日 (Y年m月d日)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Date-time format (with time)</label>
                <select name="datetime_format"
                        class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
                    <option value="Y-m-d H:i">2026-06-02 01:05 (Y-m-d H:i)</option>
                    <option value="Y/m/d H:i">2026/06/02 01:05 (Y/m/d H:i)</option>
                    <option value="Y年n月j日 G時i分">2026年6月2日 1時05分 (Y年n月j日 G時i分)</option>
                    <option value="Y年m月d日 H時i分">2026年06月02日 01時05分 (Y年m月d日 H時i分)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Archive month format</label>
                <select name="archive_month_format"
                        class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
                    <option value="Y-m">2026-06 (Y-m)</option>
                    <option value="Y/m">2026/06 (Y/m)</option>
                    <option value="Y年m月">2026年06月 (Y年m月)</option>
                    <option value="Y年n月">2026年6月 (Y年n月)</option>
                </select>
            </div>
        </div>

        <!-- Database Infrastructure -->
        <div class="border border-slate-200 dark:border-slate-800 rounded-xl p-5 space-y-4">
            <h2 class="text-sm font-semibold text-emerald-500 uppercase tracking-widest">Database Infrastructure</h2>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Driver</label>
                    <select name="db_driver"
                            class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
                        <option value="mysql">MySQL</option>
                        <option value="pgsql">PostgreSQL</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Port</label>
                    <input type="number" name="db_port" value="3306"
                           class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
                </div>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Host</label>
                <input type="text" name="db_host" value="127.0.0.1" required
                       class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Database Name</label>
                <input type="text" name="db_database" required
                       class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Username</label>
                    <input type="text" name="db_username" required
                           class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
                </div>
                <div>
                    <label class="block text-xs text-slate-500 mb-1">Password</label>
                    <input type="password" name="db_password"
                           class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
                </div>
            </div>
        </div>

        <!-- Super User -->
        <div class="border border-slate-200 dark:border-slate-800 rounded-xl p-5 space-y-4">
            <h2 class="text-sm font-semibold text-emerald-500 uppercase tracking-widest">File-Based Super User</h2>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Email</label>
                <input type="email" name="super_user_email" required
                       class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Master Password</label>
                <input type="password" name="password" required minlength="8"
                       class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">UI Locale</label>
                <select name="super_user_locale"
                        class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
                    <option value="en">en — English</option>
                    <option value="ja">ja — 日本語</option>
                </select>
            </div>
        </div>

        <!-- Hardened Security -->
        <div class="border border-slate-200 dark:border-slate-800 rounded-xl p-5 space-y-4">
            <h2 class="text-sm font-semibold text-emerald-500 uppercase tracking-widest">Hardened Security Shield</h2>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Admin Path (hidden URL)</label>
                <input type="text" name="admin_path" value="admin" required pattern="[a-zA-Z0-9_-]+"
                       class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Anti-Spam Level (0=OFF, 1=Honeypot, 2=Full)</label>
                <select name="antispam_level"
                        class="w-full bg-transparent border border-slate-300 dark:border-slate-700 rounded-xl px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
                    <option value="2" selected>2 — Hybrid Maximum Defense</option>
                    <option value="1">1 — Honeypot Only</option>
                    <option value="0">0 — Off</option>
                </select>
            </div>
        </div>

        <button type="submit"
                class="w-full py-3 bg-emerald-500 hover:bg-emerald-600 text-white font-semibold rounded-xl transition-colors">
            Initialize Core Matrix
        </button>
    </form>
</div>
</body>
</html>
        <?php
    }

    /** Run the installation (called from POST /install/initialize) */
    public static function initialize(): void
    {
        // Required-field check
        $required = ['super_user_email', 'password', 'db_database', 'admin_path'];
        foreach ($required as $key) {
            if (empty($_POST[$key])) {
                http_response_code(400);
                exit("Missing required field: {$key}");
            }
        }

        $siteName        = trim($_POST['site_name']        ?? 'HayabusaPress');
        $siteUrl         = rtrim(trim($_POST['site_url']   ?? ''), '/');
        $defaultLang     = preg_match('/^[a-z]{2,5}$/', $_POST['site_default_lang'] ?? '') ? $_POST['site_default_lang'] : 'en';

        // ============================================================
        // public-URL self-diagnosis (proactive leak detection)
        // Place a random token file at the same level as .env and check whether it can be
        // fetched over HTTP via the public URL. Accessible = dangerous placement -> stop installation
        // ============================================================
        if ($siteUrl !== '' && function_exists('curl_init')) {
            $testToken    = bin2hex(random_bytes(16));
            $testFilename = '.hbp_check_' . $testToken . '.txt';
            $testFilePath = ROOT_PATH . '/' . $testFilename;
            file_put_contents($testFilePath, $testToken, LOCK_EX);

            $testUrl = $siteUrl . '/' . $testFilename;
            $ch      = curl_init($testUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body    = curl_exec($ch);
            $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            @unlink($testFilePath);

            if ($status === 200 && trim((string)$body) === $testToken) {
                http_response_code(400);
                header('Content-Type: text/html; charset=UTF-8');
                ?>
<!DOCTYPE html><html lang="{$lang}"><head><meta charset="UTF-8"><title>HBP Setup — Security Warning</title>
<script src="https://cdn.tailwindcss.com"></script></head>
<body class="bg-black text-white font-mono p-8 max-w-2xl mx-auto">
<h1 class="text-2xl font-bold text-rose-500 mb-4">&#x26a0; Security Warning: Dangerous Placement Detected</h1>
<p class="mb-4">The application root directory is publicly accessible via the web.<br>
<strong class="text-rose-400"><?= htmlspecialchars($siteUrl . '/.env', ENT_QUOTES) ?></strong> would expose your database credentials.</p>
<h2 class="text-lg font-semibold text-emerald-400 mt-6 mb-2">How to fix:</h2>
<ul class="list-disc pl-6 space-y-2 text-slate-300">
  <li><strong>Recommended (Method B)</strong>: Move <code>core/</code>, <code>.env</code>, <code>storage/</code>, <code>plugins/</code>, <code>themes/</code> outside the document root. Set <code>HBP_BASE_PATH</code> in <code>index.php</code>. See <code>deploy/README-shared-hosting.md</code>.</li>
  <li><strong>Method A (Apache/LiteSpeed only)</strong>: Place <code>deploy/htaccess.root</code> as <code>.htaccess</code> in the document root. Verify <code>AllowOverride</code> is enabled.</li>
</ul>
<p class="mt-6 text-slate-500 text-sm">After fixing the placement, retry setup.</p>
</body></html>
                <?php
                exit;
            }
        }
        $adminPath       = preg_replace('/[^a-zA-Z0-9_-]/', '', $_POST['admin_path'] ?? 'admin');
        // Reserved-word check: values colliding with the front controller's public routes, or empty, are forced to 'admin'
        if ($adminPath === '' || in_array($adminPath, ['post', 'page', 'feed', 'search', 'comment_post', 'assets', 'install', 'sitemap', 'robots', 'favicon', 'public'], true)) {
            $adminPath = 'admin';
        }
        $dbDriver        = in_array($_POST['db_driver'] ?? 'mysql', ['mysql', 'pgsql'], true) ? $_POST['db_driver'] : 'mysql';
        $dbHost          = trim($_POST['db_host']        ?? '127.0.0.1');
        $dbPort          = (int)($_POST['db_port']       ?? ($dbDriver === 'pgsql' ? 5432 : 3306));
        $dbDatabase      = trim($_POST['db_database']    ?? '');
        $dbUsername      = trim($_POST['db_username']    ?? '');
        $dbPassword      = $_POST['db_password']         ?? '';
        $superEmail      = trim($_POST['super_user_email'] ?? '');
        $superLocale     = preg_match('/^[a-z]{2,5}$/', $_POST['super_user_locale'] ?? '') ? $_POST['super_user_locale'] : 'en';
        $antispamLevel   = in_array((int)($_POST['antispam_level'] ?? 2), [0, 1, 2], true) ? (int)$_POST['antispam_level'] : 2;

        // Display date/time formats (PHP date() syntax). Whitelisted to the presets offered in the form.
        $dateFormats     = ['Y-m-d', 'Y/m/d', 'Y年n月j日', 'Y年m月d日'];
        $datetimeFormats = ['Y-m-d H:i', 'Y/m/d H:i', 'Y年n月j日 G時i分', 'Y年m月d日 H時i分'];
        $dateFormat      = in_array($_POST['date_format'] ?? '', $dateFormats, true) ? $_POST['date_format'] : 'Y-m-d';
        $datetimeFormat  = in_array($_POST['datetime_format'] ?? '', $datetimeFormats, true) ? $_POST['datetime_format'] : 'Y-m-d H:i';
        $monthFormats    = ['Y-m', 'Y/m', 'Y年m月', 'Y年n月'];
        $monthFormat     = in_array($_POST['archive_month_format'] ?? '', $monthFormats, true) ? $_POST['archive_month_format'] : 'Y-m';

        // Generate the password hash
        require_once __DIR__ . '/Hash.php';
        $passwordHash = Hash::make($_POST['password']);

        // Generate APP_KEY
        require_once __DIR__ . '/Crypto.php';
        $appKey = Crypto::generateAppKey();

        // Generate .env (USE_DNS_BL -> DNSBL_ENABLED / APP_KEY added)
        $envContent = sprintf(
            <<<'ENV'
SITE_NAME="%s"
SITE_URL="%s"
SITE_DEFAULT_LANG="%s"
SITE_CREDIT_LINK=true
ADMIN_PATH="%s"
DB_DRIVER=%s
DB_HOST=%s
DB_PORT=%d
DB_DATABASE=%s
DB_USERNAME=%s
DB_PASSWORD=%s
DB_CHARSET=
PASSWORD_ALGO=bcrypt
APP_KEY="%s"
SUPER_USER_EMAIL="%s"
SUPER_USER_PASSWORD_HASH="%s"
SUPER_USER_NAME="Super User"
SUPER_USER_LOCALE="%s"
SUPER_USER_AVATAR=""
SUPER_USER_BIO=""
SUPER_USER_X=""
SUPER_USER_GITHUB=""
SUPER_USER_2FA_SECRET=""
ANTISPAM_LEVEL=%d
DNSBL_ENABLED=false
DNSBL_ZONE="zen.spamhaus.org"
DNSBL_TIMEOUT_MS=300
DNSBL_ON_HIT="moderate"
EXTERNAL_SPAM_API_KEY=""
CSP_MODE=report-only
CSP_IMG_HOSTS=""
CSP_SCRIPT_HOSTS=""
SPONSORED_NOTICE_TEXT=""
AUTO_LANG_REDIRECT=false
EMBED_HOSTS="www.youtube-nocookie.com,player.vimeo.com"
LIST_PAGE_SIZE=10
LIST_FPC_DEPTH=10
CSS_DELIVERY=external
THEME_SHOWS_AUTHOR_IN_LIST=false
ALLOW_PLUGIN_INSTALL=false
ALLOW_THEME_INSTALL=false
REQUIRE_SIGNED_PLUGINS=false
PLUGIN_TRUSTED_KEYS=""
MAIL_FROM="noreply@example.com"
MAIL_FROM_NAME="HayabusaPress"
APP_TIMEZONE=UTC
DATE_FORMAT="%s"
DATETIME_FORMAT="%s"
ARCHIVE_MONTH_FORMAT="%s"
ADMIN_IP_ALLOWLIST=""
ADMIN_IP_MODE=enforce
REQUIRE_ADMIN_2FA=false
TRUSTED_PROXIES=""
BLOCKLIST_ENABLED=true
BLOCKLIST_ON_HIT=reject
BLOCKLIST_AUTO_THRESHOLD=5
BLOCKLIST_AUTO_TTL=86400
SITE_IP_DENYLIST=""
SESSION_DRIVER=database
APP_ENV=production
ENV,
            $siteName, $siteUrl, $defaultLang, $adminPath,
            $dbDriver, $dbHost, $dbPort, $dbDatabase, $dbUsername, $dbPassword,
            $appKey, $superEmail, $passwordHash, $superLocale,
            $antispamLevel, $dateFormat, $datetimeFormat, $monthFormat
        );

        $envFile = ROOT_PATH . '/.env';
        if (file_put_contents($envFile, $envContent, LOCK_EX) === false) {
            http_response_code(500);
            exit(self::t('install_env_write_failed',
                'Failed to write .env. Check the write permission of the application directory.'));
        }

        // Load the generated environment variables into the current process (using the Env class)
        require_once __DIR__ . '/Env.php';
        $vars = Env::parse($envContent);
        Env::hydrate($vars);

        // DB connect -> run the DDL
        require_once __DIR__ . '/DB.php';
        // Share the CLI's statement splitter: unlike explode(';'), it does not split on a ';'
        // that appears inside a string literal or a comment. The schema header comment contains
        // one, so a naive split would corrupt the statement that follows it.
        require_once __DIR__ . '/Migrator.php';
        try {
            $pdo    = DB::getInstance();
            // Single source of truth: the same completed schema as the CLI (db:install) (including published_at)
            $file   = __DIR__ . '/database/schema.' . $dbDriver . '.sql';

            // Both schemas are fully idempotent (CREATE TABLE/INDEX IF NOT EXISTS, INSERT IGNORE /
            // INSERT ... WHERE NOT EXISTS), so a successful install produces zero errors. Any error
            // therefore means the schema was only partially applied: surface it instead of
            // reporting a successful installation on a half-built database.
            $failures = [];

            if (file_exists($file)) {
                $sql = file_get_contents($file);
                foreach (Migrator::splitStatements($sql) as $stmt) {
                    try {
                        $pdo->exec($stmt);
                    } catch (PDOException $e) {
                        $failures[] = 'schema.' . $dbDriver . '.sql: ' . $e->getMessage()
                            . ' [' . self::stmtExcerpt($stmt) . ']';
                    }
                }
            }

            // Auto-detect bundled plugins' install.{driver}.sql and migrate them all at once
            $pluginsDir = ROOT_PATH . '/plugins';
            if (is_dir($pluginsDir)) {
                foreach (scandir($pluginsDir) as $pluginId) {
                    if ($pluginId === '.' || $pluginId === '..') continue;
                    $pluginSql = $pluginsDir . '/' . $pluginId . '/database/install.' . $dbDriver . '.sql';
                    if (!file_exists($pluginSql)) continue;
                    $pluginSqlContent = file_get_contents($pluginSql);
                    foreach (Migrator::splitStatements($pluginSqlContent) as $stmt) {
                        try {
                            $pdo->exec($stmt);
                        } catch (PDOException $e) {
                            $failures[] = $pluginId . '/install.' . $dbDriver . '.sql: ' . $e->getMessage()
                                . ' [' . self::stmtExcerpt($stmt) . ']';
                        }
                    }
                }
            }

            if ($failures !== []) {
                foreach ($failures as $msg) {
                    error_log('[HBP INSTALL] schema statement failed: ' . $msg);
                }
                throw new RuntimeException(
                    self::t('install_schema_failed', 'Failed to apply the schema (:count statement(s) failed).',
                            ['count' => count($failures)]) . "\n"
                    . implode("\n", array_slice($failures, 0, 5))
                );
            }
        } catch (Throwable $e) {
            unlink($envFile); // Rollback
            http_response_code(500);
            exit(self::t('install_db_setup_failed', 'Database setup failed') . ': ' . $e->getMessage());
        }

        // DB charset check: detect non-UTF8 (especially a non-UTF8 pgsql DB).
        // PostgreSQL's server_encoding is fixed at CREATE DATABASE time and cannot be changed afterward,
        // so it is made clear to the operator at installation completion (server log + warning on the completion screen).
        $encWarning = DB::encodingWarning();
        if ($encWarning !== null) {
            error_log('[HBP INSTALL] ' . $encWarning);
        }

        // Generate the plugin registry + caches
        require_once __DIR__ . '/Locale.php';
        require_once __DIR__ . '/PluginManager.php';
        require_once __DIR__ . '/RouterCompiler.php';
        require_once __DIR__ . '/MenuCompiler.php';
        require_once __DIR__ . '/CategoryCompiler.php';
        require_once __DIR__ . '/TagCompiler.php';
        require_once __DIR__ . '/SidebarCompiler.php';
        require_once __DIR__ . '/MarkdownCompiler.php';

        PluginManager::rebuildCache();
        MenuCompiler::compile();
        CategoryCompiler::compile();
        TagCompiler::compile();

        $contentLocales = class_exists('HbpLocale') ? HbpLocale::getAvailableContentLocales() : [$defaultLang => strtoupper($defaultLang)];
        foreach ($contentLocales as $lCode => $lLabel) {
            SidebarCompiler::compileRecentCommentsWidget($lCode);
            SidebarCompiler::compileArchiveWidget($lCode);
            SidebarCompiler::compileAllLayouts($lCode);
        }

        // Create the sample "Hello World" post (only on first install)
        // Follows the chosen default language. Undefined languages fall back to English (default-English policy).
        try {
            $existingPost = DB::fetchOne("SELECT id FROM posts WHERE slug='hello-world' AND lang=? LIMIT 1", [$defaultLang]);
            if (!$existingPost) {
                $samples = [
                    'en' => [
                        'title'    => 'Hello World',
                        'markdown' => "# Hello World\n\nWelcome to HayabusaPress! This blog engine is AGPL software built for speed, light weight, and security.\n\nWrite your posts in Markdown; they are compiled to HTML on save, so the front-end Markdown conversion cost is exactly zero.\n\n## Features\n\n- **Ultra lightweight**: a backend with zero external dependencies\n- **Fast**: a precompiled architecture\n- **Secure**: physical media isolation and a static permission matrix\n- **Multilingual**: an independent two-axis design for content language and UI language\n",
                        'fallback' => '<h1>Hello World</h1><p>Welcome to HayabusaPress!</p>',
                    ],
                    'ja' => [
                        'title'    => 'Hello World',
                        'markdown' => "# Hello World\n\nHayabusaPress へようこそ！このブログエンジンは高速・軽量・安全を追求したAGPLソフトウェアです。\n\nMarkdownで記事を書き、保存時にHTMLへコンパイルされます。フロントエンドでのMarkdown変換コストは完全ゼロです。\n\n## 特徴\n\n- **超軽量**: 外部依存ゼロのバックエンド\n- **高速**: 事前コンパイルアーキテクチャ\n- **安全**: 物理メディア隔離・静的権限マトリクス\n- **多言語対応**: コンテンツ言語とUI言語の独立した2軸設計\n",
                        'fallback' => '<h1>Hello World</h1><p>HayabusaPress へようこそ！</p>',
                    ],
                ];
                $sample        = $samples[$defaultLang] ?? $samples['en'];
                $helloMarkdown = $sample['markdown'];
                // HtmlSanitizer integration + raw_html allowed (the installer is SU-equivalent)
                require_once __DIR__ . '/HtmlSanitizer.php';
                $helloHtml = class_exists('MarkdownCompiler') ? MarkdownCompiler::render($helloMarkdown, true) : $sample['fallback'];
                $now = date('Y-m-d H:i:s');
                // Fetch the "uncategorized" category matching the default language (a fixed id would mismatch a ja category onto an en post).
                // NULL if not found (category_id is nullable).
                $catRow = DB::fetchOne(
                    "SELECT id FROM categories WHERE type='post' AND lang=? AND slug='uncategorized' LIMIT 1",
                    [$defaultLang]
                );
                $catId = $catRow['id'] ?? null;
                // removed author_name_cache / author_profile_cache (resolved via the AuthorCompiler singleton)
                DB::execute(
                    "INSERT INTO posts
                     (type, lang, slug, title, content_markdown, content_html, status, category_id,
                      allow_comment, show_related_posts, show_share, show_author,
                      user_id, comment_count, created_at, updated_at)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NULL,0,?,?)",
                    [
                        'post', $defaultLang, 'hello-world', $sample['title'],
                        $helloMarkdown, $helloHtml, 'published', $catId,
                        1, 1, 1, 1,
                        $now, $now,
                    ]
                );
            }
        } catch (Throwable $e) {
            // Failure to create the Hello World post is not treated as a fatal error
        }

        RouterCompiler::compile();

        // generate the author singleton
        require_once __DIR__ . '/AuthorCompiler.php';
        AuthorCompiler::rebuild();

        // Spec step 7: precompile admin views (speeds up the first login)
        require_once __DIR__ . '/ViewCompiler.php';
        require_once __DIR__ . '/View.php';
        // $superLocale is already defined inside initialize()
        $adminViews   = ['admin/login', 'admin/index', 'admin/edit', 'admin/profile'];
        foreach ($adminViews as $view) {
            try {
                ViewCompiler::compile($view, $superLocale);
            } catch (Throwable) {
                // Failure to compile an individual view is not treated as a fatal error
            }
        }

        // generate the security header cache
        require_once __DIR__ . '/SecurityHeaderCompiler.php';
        SecurityHeaderCompiler::compile();

        // compile the site view deny list (default empty)
        require_once __DIR__ . '/SiteDenylistCompiler.php';
        SiteDenylistCompiler::compile();

        // generate Atom/RSS feeds (when FEED_ENABLED=true)
        $feedCompilerPath = __DIR__ . '/FeedCompiler.php';
        if (file_exists($feedCompilerPath)) {
            require_once $feedCompilerPath;
            FeedCompiler::rebuildAll();
        }

        // generate next_schedule.php for pseudo-cron (no schedule = PHP_INT_MAX)
        $schedulerPath = __DIR__ . '/Scheduler.php';
        if (file_exists($schedulerPath)) {
            require_once $schedulerPath;
            Scheduler::recompileNext();
        }

        // record the migration baseline
        require_once __DIR__ . '/Migrator.php';
        Migrator::baseline();

        // Redirect to the admin screen
        $loginUrl = $siteUrl . '/' . $adminPath . '/login';

        // If a non-UTF8 DB is detected, do not auto-redirect; interpose a warning screen
        // (mojibake cannot be fixed at runtime, so make sure to inform the operator before proceeding to login).
        if ($encWarning !== null) {
            header('Content-Type: text/html; charset=UTF-8');
            $msg  = htmlspecialchars($encWarning, ENT_QUOTES, 'UTF-8');
            $href = htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8');
            $lang = htmlspecialchars(self::locale(), ENT_QUOTES, 'UTF-8');
            $hTtl = htmlspecialchars(self::t('install_charset_warn_title', 'Database character set warning'), ENT_QUOTES, 'UTF-8');
            $hLead = htmlspecialchars(self::t('install_charset_warn_lead', 'The installation itself finished, but the following problem was detected.'), ENT_QUOTES, 'UTF-8');
            $hBody = htmlspecialchars(self::t('install_charset_warn_body', 'You can continue, but non-ASCII text and emoji may be corrupted. Recreating the database as UTF-8 and reinstalling is strongly recommended.'), ENT_QUOTES, 'UTF-8');
            $hBtn  = htmlspecialchars(self::t('install_charset_warn_continue', 'Continue to the admin login'), ENT_QUOTES, 'UTF-8');
            echo <<<HTML
<!DOCTYPE html><html lang="{$lang}"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>HBP Setup — {$hTtl}</title>
<style>body{font-family:system-ui,sans-serif;max-width:640px;margin:4rem auto;padding:0 1rem;line-height:1.7;color:#1f2937}
.box{border:1px solid #fecdd3;background:#fff1f2;border-radius:12px;padding:1rem 1.25rem;color:#9f1239}
a.btn{display:inline-block;margin-top:1.5rem;padding:.6rem 1.2rem;border-radius:10px;background:#10b981;color:#fff;text-decoration:none}</style>
</head><body>
<h1><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:.25rem"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>{$hTtl}</h1>
<p>{$hLead}</p>
<div class="box">{$msg}</div>
<p>{$hBody}</p>
<a class="btn" href="{$href}">{$hBtn}</a>
</body></html>
HTML;
            exit;
        }

        header('Location: ' . $loginUrl);
        exit;
    }

    /** One-line excerpt of a SQL statement, for error messages (never echo the whole DDL). */
    private static function stmtExcerpt(string $stmt): string
    {
        $one = trim(preg_replace('/\s+/', ' ', $stmt) ?? $stmt);
        return mb_strimwidth($one, 0, 120, '...');
    }

    /** Resolved once per request. @var string|null */
    private static ?string $locale = null;
    /** @var array<string,string>|null */
    private static ?array $dict = null;

    /**
     * Decide which language the installer speaks.
     *
     * The installer is the one place that runs *before* .env exists, so neither SITE_DEFAULT_LANG
     * nor users.locale is available. Resolution order:
     *   1. the UI locale the operator just picked on the form (POST)   -- most explicit
     *   2. the default content language picked on the form (POST)
     *   3. Accept-Language                                             -- best guess before any input
     *   4. English                                                     -- the documented default
     */
    public static function locale(): string
    {
        if (self::$locale !== null) {
            return self::$locale;
        }
        $available = ['en'];
        foreach (glob(CORE_PATH . '/lang/admin_*.php') ?: [] as $file) {
            if (preg_match('/admin_([a-z]{2,5})\.php$/', basename($file), $m)) {
                $available[] = $m[1];
            }
        }
        foreach ([$_POST['super_user_locale'] ?? '', $_POST['site_default_lang'] ?? ''] as $candidate) {
            $candidate = is_string($candidate) ? trim($candidate) : '';
            if ($candidate !== '' && in_array($candidate, $available, true)) {
                return self::$locale = $candidate;
            }
        }
        $header = (string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
        foreach (explode(',', $header) as $part) {
            $code = strtolower(trim(explode(';', $part)[0]));
            if (preg_match('/^([a-z]{2,5})/', $code, $m) && in_array($m[1], $available, true)) {
                return self::$locale = $m[1];
            }
        }
        return self::$locale = 'en';
    }

    /**
     * Translate an installer message.
     *
     * Deliberately self-contained: HbpLocale::t() reads $_SESSION and Env, and the earliest failure
     * here (.env not writable) happens before Env::hydrate(), so this loads the catalog directly.
     * Falls back to the English wording passed in, exactly like t() does.
     *
     * @param array<string,scalar> $replace ':name' placeholders
     */
    private static function t(string $key, string $english, array $replace = []): string
    {
        if (self::$dict === null) {
            self::$dict = [];
            foreach ([self::locale(), 'en'] as $code) {
                $file = CORE_PATH . '/lang/admin_' . $code . '.php';
                if (is_file($file)) {
                    $data = require $file;
                    if (is_array($data)) {
                        self::$dict = $data;
                        break;
                    }
                }
            }
        }
        $message = self::$dict[$key] ?? $english;
        if ($replace === []) {
            return $message;
        }
        $pairs = [];
        foreach ($replace as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $pairs[':' . $name] = (string)$value;
            }
        }
        return $pairs === [] ? $message : strtr($message, $pairs);
    }
}
