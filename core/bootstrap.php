<?php
/**
 * HayabusaPress - Environment, security, and session bootstrap.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

// Already defined when called from public/index.php, so prevent double definition
// HBP_BASE_PATH support. When bootstrap.php is called directly (CLI, etc.),
// use HBP_BASE_PATH as the base if defined, otherwise dirname(__DIR__) (one above core/)
if (!defined('ROOT_PATH')) {
    $_hbpBase = defined('HBP_BASE_PATH') ? HBP_BASE_PATH : (getenv('HBP_BASE_PATH') ?: null);
    define('ROOT_PATH', $_hbpBase ?? dirname(__DIR__));
    unset($_hbpBase);
}
defined('STORAGE_PATH') || define('STORAGE_PATH', ROOT_PATH . '/storage');
defined('PUBLIC_PATH')  || define('PUBLIC_PATH',  ROOT_PATH . '/public');
defined('CORE_PATH')    || define('CORE_PATH',    ROOT_PATH . '/core');

// C-series: unified into the single parser Env (parse_ini_file() fully removed)
require_once CORE_PATH . '/Env.php';

// Skip parsing if public/index.php has already called Env::hydrate()
// (parse only when called directly from CLI or admin/index.php)
if (Env::get('APP_ENV', '') === '' && file_exists(ROOT_PATH . '/.env')) {
    $vars = Env::parseFile(ROOT_PATH . '/.env');
    Env::hydrate($vars);
}

// ============================================================
// / self-defense against dangerous placement
// ROOT_PATH being at or below DOCUMENT_ROOT = .env is publicly reachable -> stop or warn
// Skip in CLI/test environments. When using HBP_BASE_PATH, also decide by comparing with DOCUMENT_ROOT.
//
// In method A ( .htaccess rewrite), the app itself is placed at the public root,
// so ROOT_PATH == DOCUMENT_ROOT (nested) is the "normal" placement. In this case .htaccess
// denies direct access to .env / core / storage, so once the operator
// confirms "/.env returns 403/404" and sets HBP_ALLOW_NESTED_ROOT=true,
// the stop is downgraded to a "log warning" (avoiding false positives). See migration guide.
// ============================================================
if (PHP_SAPI !== 'cli' && isset($_SERVER['DOCUMENT_ROOT'])) {
    $hbpDocRoot  = rtrim((string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    $hbpRootReal = rtrim((string)realpath(ROOT_PATH), '/\\');
    // ROOT_PATH at or below DOCUMENT_ROOT -> secrets exist in the web-public area
    if (
        $hbpDocRoot !== '' && $hbpRootReal !== ''
        && str_starts_with($hbpRootReal . DIRECTORY_SEPARATOR, $hbpDocRoot . DIRECTORY_SEPARATOR)
    ) {
        // Approved if the operator chose method A (.htaccess protection) and confirmed /.env returns 403/404
        $hbpNestedAck = Env::bool('HBP_ALLOW_NESTED_ROOT', false);
        if (!$hbpNestedAck && Env::get('APP_ENV', 'production') !== 'development') {
            http_response_code(503);
            header('Content-Type: text/plain; charset=UTF-8');
            exit(
                "[HBP SECURITY] Dangerous placement detected.\n" .
                "The application root is inside the web document root.\n" .
                ".env, core/, storage/ may be publicly accessible.\n\n" .
                "Options:\n" .
                "  1. (Recommended) Move app root outside document root and set HBP_BASE_PATH.\n" .
                "  2. Place deploy/htaccess.root as .htaccess in document root (Apache/LiteSpeed),\n" .
                "     verify https://your-site/.env returns 403/404, then set HBP_ALLOW_NESTED_ROOT=true.\n" .
                "  3. Set document root to public/ in your web server config.\n\n" .
                "See deploy/README-shared-hosting.md for details."
            );
        }
        // When approved via HBP_ALLOW_NESTED_ROOT (method A operation), suppress the warning to avoid log pollution.
        // Only when not approved (e.g. reaching this branch in development) warn once to alert the operator.
        if (!$hbpNestedAck) {
            error_log('[HBP WARNING] Dangerous placement: ROOT_PATH is inside DOCUMENT_ROOT. .env may be publicly accessible.');
        }
    }
}

// ============================================================
// ADMIN_PATH reserved-word check: colliding with a public route used by the front controller
// makes the admin unreachable / breaks the public side. On collision, log a warning (does not stop).
// The reserved prefixes correspond to public/index.php routing:
//   post / page / feed / search / comment_post / assets / install
//   sitemap.xml / robots.txt / favicon.ico
// A match with a language code (SITE_DEFAULT_LANG, etc.) is also dangerous.
// ============================================================
$hbpAdminPath = trim((string)Env::get('ADMIN_PATH', 'admin'), '/');
if ($hbpAdminPath !== '') {
    $hbpAdminHead = explode('/', $hbpAdminPath)[0];
    $hbpReserved  = ['post', 'page', 'feed', 'search', 'comment_post', 'assets',
                     'install', 'sitemap.xml', 'robots.txt', 'favicon.ico', 'public'];
    $hbpDefLang   = trim((string)Env::get('SITE_DEFAULT_LANG', 'en'));
    if (in_array($hbpAdminHead, $hbpReserved, true)
        || (preg_match('/^[a-z]{2,5}$/', $hbpAdminHead) && $hbpAdminHead === $hbpDefLang)) {
        error_log("[HBP WARNING] ADMIN_PATH '{$hbpAdminPath}' collides with a reserved public route. "
                . 'The admin panel may become unreachable -- change ADMIN_PATH to another value.');
    }
    unset($hbpAdminHead, $hbpReserved, $hbpDefLang);
}
unset($hbpAdminPath);

// Timezone setting (APP_TIMEZONE preferred; the DB stores UTC)
$timezone = Env::get('APP_TIMEZONE', 'UTC');
date_default_timezone_set($timezone !== '' ? $timezone : 'UTC');
mb_internal_encoding('UTF-8');

$appEnv = Env::get('APP_ENV', 'production');
if ($appEnv === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

// ============================================================
// Global exception / fatal error handler (robustness)
// In production, do not return a "blank 500" on an uncaught error; log the details only and show a generic message on screen.
// In development (APP_ENV=development), leave it to PHP's default detailed display, so do not register.
// Because the FPC uses atomic temp->rename writes, an abnormal exit here does not corrupt the cache.
// Discard any open output buffer (e.g. a View::render exception during FPC generation) to prevent partial-HTML contamination.
// ============================================================
if ($appEnv !== 'development') {
    $hbpEmit500 = static function (): void {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
        }
        echo "<!doctype html><meta charset=\"utf-8\"><title>500 Internal Server Error</title>\n";
        echo "<h1>500 Internal Server Error</h1>\n";
    };

    set_exception_handler(static function (\Throwable $e) use ($hbpEmit500): void {
        error_log('[HBP FATAL] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $hbpEmit500();
    });

    register_shutdown_function(static function () use ($hbpEmit500): void {
        $err = error_get_last();
        if ($err === null || (($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) === 0)) {
            return;
        }
        error_log('[HBP FATAL] ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
        $hbpEmit500();
    });
}

require_once CORE_PATH . '/DB.php';
require_once CORE_PATH . '/Hash.php';
require_once CORE_PATH . '/Session.php';
require_once CORE_PATH . '/Gate.php';
require_once CORE_PATH . '/Crypto.php';
require_once CORE_PATH . '/Auth.php';
require_once CORE_PATH . '/Throttle.php';   // login / 2FA / password-confirmation rate limits (callers check class_exists)
require_once CORE_PATH . '/Audit.php';
require_once CORE_PATH . '/PluginErrors.php';

// A fatal error (memory, a type error at compile time, a timeout) is not a Throwable and never
// reaches the catch blocks around plugin code. When it happened in a plugin's file, put it in the
// plugin error log too, so the plugins screen can say which plugin brought the request down.
// Registered in every APP_ENV: it only records, the global handler above decides the output.
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null || (($err['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) === 0)) {
        return;
    }
    $plugin = PluginErrors::pluginOfFile((string)$err['file']);
    if ($plugin !== null) {
        PluginErrors::record($plugin, 'fatal', $err['message'] . ' @ ' . basename((string)$err['file']) . ':' . $err['line']);
    }
});
require_once CORE_PATH . '/TwoFactorAuth.php';
require_once CORE_PATH . '/Locale.php';
require_once CORE_PATH . '/Security.php';
require_once CORE_PATH . '/AccessControl.php';
require_once CORE_PATH . '/Media.php';
require_once CORE_PATH . '/Format.php';
require_once CORE_PATH . '/ThemeManager.php';

// Cookie security policy
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (((int)($_SERVER['SERVER_PORT'] ?? 80)) === 443);

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);

// Session handler registration & start
// Even if the external session backend (DB/Redis/Memcached) is temporarily unreachable,
// fall back to file sessions so a fatal error does not take down every page (including FPC-cached public pages).
// Thanks to this, even during a DB outage,
// public pages can continue to be served via readfile FPC (since they do not depend on the session).
$sessionDriver = Env::get('SESSION_DRIVER', 'file');
try {
    if ($sessionDriver === 'database') {
        // Establish the connection first; on failure, demote to file sessions in catch
        DB::getInstance();
        session_set_save_handler(new DbSessionHandler(), true);
    } elseif ($sessionDriver === 'redis' && class_exists('Redis')) {
        $redis = new Redis();
        if (!$redis->connect(Env::get('REDIS_HOST', '127.0.0.1'), Env::int('REDIS_PORT', 6379))) {
            throw new RuntimeException('Redis connect failed');
        }
        session_save_path('tcp://' . Env::get('REDIS_HOST', '127.0.0.1') . ':' . Env::int('REDIS_PORT', 6379));
    } elseif ($sessionDriver === 'memcached' && class_exists('Memcached')) {
        $mc = new Memcached();
        $mc->addServer(Env::get('MEMCACHED_HOST', '127.0.0.1'), Env::int('MEMCACHED_PORT', 11211));
        ini_set('session.save_handler', 'memcached');
        ini_set('session.save_path', Env::get('MEMCACHED_HOST', '127.0.0.1') . ':' . Env::int('MEMCACHED_PORT', 11211));
    }
} catch (Throwable $e) {
    // Session backend unreachable: demote to the default (file) session and continue.
    error_log('[HBP] Session backend (' . $sessionDriver . ') unavailable, falling back to file sessions: ' . $e->getMessage());
}

session_start();
