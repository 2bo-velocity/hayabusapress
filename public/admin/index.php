<?php
/**
 * HayabusaPress - Admin front controller and router.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

// method B (placement outside the public root) support
// If HBP_BASE_PATH is defined or in an environment variable, use it as the app base
// Already defined when required from the front controller (public/index.php), so prevent double definition
$_hbpBase = defined('HBP_BASE_PATH') ? HBP_BASE_PATH : (getenv('HBP_BASE_PATH') ?: null);
defined('ROOT_PATH')    || define('ROOT_PATH',    $_hbpBase ?? dirname(__DIR__, 2));
defined('STORAGE_PATH') || define('STORAGE_PATH', ROOT_PATH . '/storage');
defined('PUBLIC_PATH')  || define('PUBLIC_PATH',  dirname(__DIR__));   // public/ = one above admin/
defined('CORE_PATH')    || define('CORE_PATH',    ROOT_PATH . '/core');
unset($_hbpBase);

// C-series: Env single parser
require_once CORE_PATH . '/Env.php';
$vars = Env::parseFile(ROOT_PATH . '/.env');

if ($vars === []) {
    require_once CORE_PATH . '/Installer.php';
    $installUri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && str_contains($installUri, '/install/initialize')) {
        Installer::initialize();
    } else {
        Installer::renderForm();
    }
    exit;
}
Env::hydrate($vars);
unset($vars);

require_once CORE_PATH . '/bootstrap.php';
require_once CORE_PATH . '/SecurityHeaderCompiler.php';

// admin IP access protection
AccessControl::checkAdminAccess();

// Security headers (admin profile)
SecurityHeaderCompiler::emit('admin');
require_once CORE_PATH . '/View.php';
require_once CORE_PATH . '/ViewCompiler.php';
require_once CORE_PATH . '/RouterCompiler.php';
require_once CORE_PATH . '/MarkdownCompiler.php';
require_once CORE_PATH . '/MenuCompiler.php';
require_once CORE_PATH . '/SidebarCompiler.php';
require_once CORE_PATH . '/CommentCompiler.php';
require_once CORE_PATH . '/CategoryCompiler.php';
require_once CORE_PATH . '/TagCompiler.php';
require_once CORE_PATH . '/SeoCompiler.php';
require_once CORE_PATH . '/SiteFileCompiler.php';
require_once CORE_PATH . '/PluginManager.php';
require_once CORE_PATH . '/AdminPostController.php';
require_once CORE_PATH . '/AdminUserController.php';
require_once CORE_PATH . '/Locale.php';
require_once CORE_PATH . '/CacheManager.php';

// Load additional required core classes
require_once CORE_PATH . '/HtmlSanitizer.php';
require_once CORE_PATH . '/AuthorCompiler.php';
require_once CORE_PATH . '/TranslationCompiler.php';
require_once CORE_PATH . '/Schema.php';      // M-series: SCHEMA_VERSION
require_once CORE_PATH . '/Migrator.php';     // M-series: schema version drift detection

$adminPath = Env::get('ADMIN_PATH', 'admin');
$siteName  = Env::get('SITE_NAME', 'HayabusaPress');

// Get the part after admin/ from the request path
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$urlPath    = parse_url($requestUri, PHP_URL_PATH);
$urlPath    = '/' . trim($urlPath, '/');

// ADMIN_PATH enforcement: 404 if the request path is not under ADMIN_PATH.
// This closes the "back door" of hitting the physical directory name (public/admin/) via a raw URL,
// making it unreachable from anything but the concealed path (ADMIN_PATH). Whether via front-controller delegation
// or Apache serving the physical directory directly, the only truth is ADMIN_PATH.
$adminBase = '/' . trim($adminPath, '/');
if ($urlPath !== $adminBase && !str_starts_with($urlPath, $adminBase . '/')) {
    http_response_code(404);
    exit;
}

// Get the sub-path excluding admin/
$subPath = substr($urlPath, strlen($adminBase));
$subPath = '/' . ltrim($subPath, '/');
if ($subPath === '') $subPath = '/';

// ============================================================
// Login route (no auth required)
// ============================================================

if ($subPath === '/login' || str_starts_with($subPath, '/login')) {
    handleAdminLogin($adminPath, $siteName, $subPath);
    exit;
}

// ============================================================
// Public build asset delivery (no auth required, /assets/admin.css etc.)
// Via ADMIN_PATH, /{ADMIN_PATH}/assets/admin.css does not match a real file and
// gets swallowed by the front controller, so serve it directly here before the auth check.
// The source is only public/admin/assets/ (separate from draft media storage/upload/.
// That is handled later by the auth-required handleSecureAsset). CSS is needed on the login screen too, so no auth.
// ============================================================
if (str_starts_with($subPath, '/assets/')) {
    $assetName = basename(substr($subPath, strlen('/assets/')));
    $assetFile = PUBLIC_PATH . '/admin/assets/' . $assetName;
    $assetExt  = strtolower(pathinfo($assetName, PATHINFO_EXTENSION));
    if ($assetName !== '' && in_array($assetExt, ['css', 'js', 'map', 'woff', 'woff2'], true) && is_file($assetFile)) {
        $mimes = [
            'css' => 'text/css', 'js' => 'application/javascript', 'map' => 'application/json',
            'woff' => 'font/woff', 'woff2' => 'font/woff2',
        ];
        header('Content-Type: ' . ($mimes[$assetExt] ?? 'application/octet-stream') . '; charset=UTF-8');
        header('Content-Length: ' . (string)filesize($assetFile));
        header('Cache-Control: public, max-age=86400');
        readfile($assetFile);
        exit;
    }
    // If there is no match under public/admin/assets/, pass through -> to the post-auth handleSecureAsset (storage/upload)
}

// ============================================================
// Authentication required for all admin routes
// ============================================================
Auth::requireLogin($adminPath);

// ============================================================
// CSRF: bulk-validate all POST requests (login excluded)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Security::verifyPostRequest();
}

// ============================================================
// Admin UI language (determined from the user's locale setting)
// Fix it early so API handlers (/upload etc.) can also translate exception messages.
// ============================================================
$userLocale = 'ja';
if (Auth::isSuperUser()) {
    $userLocale = Env::get('SUPER_USER_LOCALE', 'en');
} else {
    $localeRow  = DB::fetchOne('SELECT locale FROM users WHERE id = ? LIMIT 1', [Auth::id()]);
    $userLocale = $localeRow['locale'] ?? 'en';
}
$_SESSION['user_locale'] = $userLocale;
$__ = HbpLocale::loadAdminDictionary($userLocale);

// ============================================================
// Private media delivery (/admin/assets/{filename})
// ============================================================
if (str_starts_with($subPath, '/assets/')) {
    handleSecureAsset($subPath);
    exit;
}

// ============================================================
// AJAX: file upload
// ============================================================
if ($subPath === '/upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postId = (int)($_POST['post_id'] ?? 0);
    try {
        // media:manage gate. Author has the ownership scope (own content only).
        if (!Auth::isSuperUser() && Gate::cannot('media:manage')) {
            throw new RuntimeException(HbpLocale::t('err_no_media_permission', 'You do not have permission to manage media.'));
        }
        if (!Auth::isSuperUser() && Gate::cannot('post:edit_other') && $postId > 0) {
            $owner = DB::fetchOne('SELECT user_id FROM posts WHERE id = ? LIMIT 1', [$postId]);
            if ($owner && (int)$owner['user_id'] !== Auth::id()) {
                throw new RuntimeException(HbpLocale::t('err_no_media_permission_content', 'You do not have permission to manage media for this content.'));
            }
        }
        // Explicitly reject no-file/upload-failure (prevents a 500 via TypeError)
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(HbpLocale::t('err_no_file_or_upload', 'No file was selected, or the upload failed.'));
        }
        $result = AdminPostController::uploadMedia($postId, $_FILES['file']);
        header('Content-Type: application/json');
        // return the auto-generated draft's post_id; the front end adopts it thereafter
        echo json_encode(['success' => true, 'url' => $result['url'], 'post_id' => $result['post_id']]);
    } catch (Throwable $e) {
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// AJAX: live body preview
//   Shares the server-side MarkdownCompiler, so it exactly matches the post-save output (including ruby/emphasis-dots).
//   unpublished draft images (/assets/articles/X) are in storage/upload, so
//          rewrite them to the management delivery URL (/{adminPath}/assets/X) only in the management preview.
// ============================================================
if ($subPath === '/preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $markdown     = (string)($_POST['content_markdown'] ?? '');
    $allowRawHtml = Auth::isSuperUser() || (class_exists('Gate') && Gate::can('post:raw_html'));
    $html         = MarkdownCompiler::render($markdown, $allowRawHtml, [
        'toc'       => !empty($_POST['show_toc']),
        'sponsored' => !empty($_POST['is_sponsored']),
        'lang'      => (string)($_POST['lang'] ?? ''),
    ]);
    // Show reusable blocks in place, as the public page will (the marker itself renders as nothing)
    if (str_contains($html, '<!--hbp:block:')) {
        require_once CORE_PATH . '/BlockCompiler.php';
        $html = BlockCompiler::expand($html, (string)($_POST['lang'] ?? Env::get('SITE_DEFAULT_LANG', 'en')));
    }

    // Rewrite draft images (exist in storage/upload = unpublished) to the management delivery URL.
    // public URLs are sharded (/assets/articles/{ab}/{cd}/{file}). By trailing filename,
    //        determine existence in storage/upload (handles both shard/flat) and swap to the management delivery URL.
    $html = preg_replace_callback(
        '#/assets/articles/(?:[\w.-]+/)*([\w.-]+)#',
        function ($m) use ($adminPath) {
            return Media::uploadExists($m[1])
                ? '/' . $adminPath . '/assets/' . $m[1]
                : $m[0];
        },
        $html
    );

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'html' => $html]);
    exit;
}

// ============================================================
// AJAX: physical deletion of an attached image
//   attachments is the source of truth; shardPath() locates the real file to delete. CSRF is validated on all POSTs.
//   media:manage required. Without post:edit_other, only the user's own content images.
// ============================================================
if ($subPath === '/api/media/delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        if (!Auth::isSuperUser() && Gate::cannot('media:manage')) {
            throw new RuntimeException(HbpLocale::t('err_no_media_permission', 'You do not have permission to manage media.'));
        }
        $postId   = (int)($_POST['post_id'] ?? 0);
        $filename = (string)($_POST['filename'] ?? '');
        // Ownership scope: without post:edit_other, only own content
        if (!Auth::isSuperUser() && Gate::cannot('post:edit_other') && $postId > 0) {
            $owner = DB::fetchOne('SELECT user_id FROM posts WHERE id = ? LIMIT 1', [$postId]);
            if ($owner && (int)$owner['user_id'] !== Auth::id()) {
                throw new RuntimeException(HbpLocale::t('err_no_media_permission_content', 'You do not have permission to manage media for this content.'));
            }
        }
        AdminPostController::deleteAttachment($postId, $filename);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// AJAX: avatar upload (unified)
//   Common to regular users and the Super User. Same preprocessing as body images (finfo + GD re-encode for EXIF removal
//   + shardPath() + ensureDir()), saving to public/assets/site/avatars/{ab}/{cd}/. CSRF is validated on all POSTs.
//   Permission: own avatar is by the person (Super User is id 0 and always allowed), others require user:manage.
//   The Super User (id 0) is DB-independent, so .env is not auto-rewritten; the generated filename is returned for manual entry.
// ============================================================
if ($subPath === '/api/avatar' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    try {
        $targetId = (isset($_POST['user_id']) && $_POST['user_id'] !== '')
            ? (int)$_POST['user_id'] : Auth::id();

        if ($targetId === 0) {
            // The Super User's avatar can be operated only by the Super User themselves (.env-managed, DB-independent)
            if (!Auth::isSuperUser()) {
                throw new RuntimeException(HbpLocale::t('err_no_permission', 'You do not have permission.'));
            }
        } else {
            // Others' avatars require user:manage. The person themselves is always allowed.
            if ($targetId !== Auth::id() && !Auth::isSuperUser() && Gate::cannot('user:manage')) {
                throw new RuntimeException(HbpLocale::t('err_no_avatar_permission', 'You do not have permission to change avatars of other users.'));
            }
        }

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(HbpLocale::t('err_no_file_selected', 'No file was selected.'));
        }

        $filename = AdminUserController::processAvatarUpload($_FILES['avatar']);
        $url      = Media::avatarUrl($filename);

        if ($targetId === 0) {
            // Super User: rewrite neither the DB nor .env. Return the filename and have the user enter it into .env manually.
            echo json_encode(['success' => true, 'filename' => $filename, 'url' => $url, 'super' => true]);
        } else {
            AdminUserController::setUserAvatar($targetId, $filename);
            echo json_encode(['success' => true, 'filename' => $filename, 'url' => $url, 'super' => false]);
        }
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// Common view data ($userLocale / $__ are already fixed right after authentication)
// ============================================================
$commonData = [
    'siteName'    => $siteName,
    'adminPath'   => $adminPath,
    'authUser'    => Auth::user(),
    '__'          => $__,
    'userLocale'  => $userLocale,
    // M-series (.1): warn at admin startup about schema-version drift between code and DB (a string only on mismatch)
    'schemaDrift' => Migrator::schemaDriftWarning(),
    // DB charset check: warn at startup about non-UTF8 (especially a non-UTF8 pgsql DB) (null on match)
    'dbEncoding'  => DB::encodingWarning(),
];

// ============================================================
// Admin routing
// ============================================================

match (true) {
    // --- Content management (posts: unified posts/static pages) ---
    $subPath === '/' || $subPath === '/posts' => handleAdminPosts($commonData),

    $subPath === '/post/new'
    || (preg_match('#^/post/edit/(\d+)$#', $subPath, $m) && ($editId = (int)$m[1]))
    => handleAdminPostEdit($commonData, isset($editId) ? $editId : 0),

    $subPath === '/post/redirect/delete' && isPost()
    => handleAdminRedirectDelete($adminPath),

    $subPath === '/post/save' && isPost()
    => handleAdminPostSave($commonData, $adminPath),

    // deletion is two-stage (logical trash -> physical complete deletion)
    $subPath === '/post/delete' && isPost()
    => handleAdminPostTrash($adminPath),           // Move to trash (logical delete)

    $subPath === '/post/restore' && isPost()
    => handleAdminPostRestore($adminPath),         // Restore from trash

    $subPath === '/post/purge' && isPost()
    => handleAdminPostPurge($adminPath),           // Delete permanently (physical)

    // --- Comment management ---
    $subPath === '/comments' => handleAdminComments($commonData),
    $subPath === '/comment/action' && isPost() => handleAdminCommentAction($adminPath),

    // --- Menu management ---
    $subPath === '/menu' => handleAdminMenu($commonData),
    $subPath === '/menu/save' && isPost() => handleAdminMenuSave($adminPath),

    // --- Sidebar management ---
    $subPath === '/sidebar' => handleAdminSidebar($commonData),
    $subPath === '/sidebar/save' && isPost() => handleAdminSidebarSave($adminPath),
    $subPath === '/sidebar/add' && isPost() => handleAdminSidebarAdd($adminPath),

    // --- Reusable blocks / managed links ---
    $subPath === '/blocks' => handleAdminBlocks($commonData),
    $subPath === '/schedule' => handleAdminSchedule($commonData),
    $subPath === '/speakers' => handleAdminSpeakers($commonData),
    $subPath === '/speakers/save' && isPost() => handleAdminSpeakerSave($adminPath),
    $subPath === '/speakers/delete' && isPost() => handleAdminSpeakerDelete($adminPath),
    $subPath === '/templates' => handleAdminTemplates($commonData),
    $subPath === '/templates/save' && isPost() => handleAdminTemplateSave($adminPath),
    $subPath === '/templates/delete' && isPost() => handleAdminTemplateDelete($adminPath),
    $subPath === '/blocks/save' && isPost() => handleAdminBlockSave($adminPath),
    $subPath === '/blocks/delete' && isPost() => handleAdminBlockDelete($adminPath),
    $subPath === '/links' => handleAdminLinks($commonData),
    $subPath === '/links/save' && isPost() => handleAdminLinksSave($adminPath),

    // --- Series ---
    $subPath === '/series' => handleAdminSeries($commonData),
    $subPath === '/series/save' && isPost() => handleAdminSeriesSave($adminPath),
    $subPath === '/series/delete' && isPost() => handleAdminSeriesDelete($adminPath),
    $subPath === '/series/reorder' && isPost() => handleAdminSeriesReorder($adminPath),

    // --- Category/tag management ---
    $subPath === '/categories' => handleAdminCategories($commonData),
    $subPath === '/category/save' && isPost() => handleAdminCategorySave($adminPath),
    $subPath === '/category/delete' && isPost() => handleAdminCategoryDelete($adminPath),

    $subPath === '/tags' => handleAdminTags($commonData),
    $subPath === '/tag/save' && isPost() => handleAdminTagSave($adminPath),
    $subPath === '/tag/delete' && isPost() => handleAdminTagDelete($adminPath),

    // --- User management ---
    $subPath === '/users' => handleAdminUsers($commonData),
    $subPath === '/user/save' && isPost() => handleAdminUserSave($adminPath),
    $subPath === '/user/delete' && isPost() => handleAdminUserDelete($adminPath),
    $subPath === '/user/role' && isPost() => handleAdminUserRole($adminPath),
    $subPath === '/user/2fa' && isPost() => handleAdminUser2fa($adminPath),

    // --- Profile ---
    $subPath === '/profile' => handleAdminProfile($commonData),
    $subPath === '/profile/save' && isPost() => handleAdminProfileSave($adminPath),
    $subPath === '/profile/2fa/setup' && isPost() => handleAdmin2faSetup($adminPath),
    $subPath === '/profile/2fa/disable' && isPost() => handleAdmin2faDisable($adminPath),

    // --- Plugin management ---
    $subPath === '/plugins' => handleAdminPlugins($commonData),
    $subPath === '/plugin/toggle' && isPost() => handleAdminPluginToggle($adminPath),
    $subPath === '/plugin/install' && isPost() => handleAdminPluginInstall($adminPath),
    $subPath === '/plugin/rebuild' && isPost() => handleAdminPluginRebuild($adminPath),
    $subPath === '/plugin/delete' && isPost() => handleAdminPluginDelete($adminPath),

    // --- Front theme management ---
    $subPath === '/themes' => handleAdminThemes($commonData),
    $subPath === '/theme/activate' && isPost() => handleAdminThemeActivate($adminPath),
    $subPath === '/theme/install' && isPost() => handleAdminThemeInstall($adminPath),
    $subPath === '/theme/delete' && isPost() => handleAdminThemeDelete($adminPath),
    $subPath === '/theme/rebuild' && isPost() => handleAdminThemeRebuild($adminPath),

    // --- NG-keyword management ---
    $subPath === '/badwords' => handleAdminBadwords($commonData),
    $subPath === '/badword/store' && isPost() => handleAdminBadwordStore($adminPath),
    $subPath === '/badword/delete' && isPost() => handleAdminBadwordDelete($adminPath),
    $subPath === '/badword/rebuild' && isPost() => handleAdminBadwordRebuild($adminPath),

    // --- SEO settings ---
    $subPath === '/seo' => handleAdminSeo($commonData),
    $subPath === '/seo/save' && isPost() => handleAdminSeoSave($adminPath),

    // --- Maintenance (regeneration; SSH-free operation) ---
    $subPath === '/maintenance' => handleAdminMaintenance($commonData),
    $subPath === '/maintenance/run' && isPost() => handleAdminMaintenanceRun($adminPath),

    // --- Cache ---
    $subPath === '/cache/clear' && isPost() => handleAdminCacheClear($adminPath),

    // --- Logout ---
    $subPath === '/logout' => handleAdminLogout($adminPath),

    default => handleAdmin404($commonData),
};

// ============================================================
// Route handler functions
// ============================================================

function isPost(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }

function handleAdminLogin(string $adminPath, string $siteName, string $subPath): void
{
    if (str_starts_with($subPath, '/login/two-factor')) {
        $loginLocale = $_SESSION['auth_temp_locale'] ?? Env::get('SUPER_USER_LOCALE', Env::get('SITE_DEFAULT_LANG', 'en'));
        $loginDict   = HbpLocale::loadAdminDictionary($loginLocale);

        // Section 24.2.7: destroy the session after 5 2FA failures -> back to login
        if (class_exists('Throttle') && !Throttle::is2faAllowed(session_id())) {
            session_destroy();
            header('Location: /' . $adminPath . '/login?2fa_locked=1');
            exit;
        }

        // 2FA code entry
        if (isPost()) {
            // Soft CSRF validation (on mismatch, do not 403; redraw the screen with an error)
            if (!Security::checkCsrfToken($_POST['_csrf_token'] ?? '')) {
                View::render('admin/two_factor', ['siteName' => $siteName, 'adminPath' => $adminPath, 'htmlLang' => $loginLocale, 'error' => $loginDict['csrf_error'] ?? 'Session expired. Please reload and try again.']);
                return;
            }
            $code = trim($_POST['code'] ?? '');
            if (Auth::completeTwoFactor($code)) {
                header('Location: /' . $adminPath);
                exit;
            }
            // If the provisional auth was discarded, go back to login
            if (($_SESSION['auth_step'] ?? 0) !== 1) {
                header('Location: /' . $adminPath . '/login?2fa_locked=1');
                exit;
            }
            View::render('admin/two_factor', ['siteName' => $siteName, 'adminPath' => $adminPath, 'htmlLang' => $loginLocale, 'error' => $loginDict['two_factor_invalid_code'] ?? 'Invalid code.']);
            return;
        }
        if (($_SESSION['auth_step'] ?? 0) !== 1) {
            header('Location: /' . $adminPath . '/login');
            exit;
        }
        View::render('admin/two_factor', ['siteName' => $siteName, 'adminPath' => $adminPath, 'htmlLang' => $loginLocale, 'error' => '']);
        return;
    }

    if (Auth::check()) {
        header('Location: /' . $adminPath);
        exit;
    }

    $loginLocale = Env::get('SUPER_USER_LOCALE', Env::get('SITE_DEFAULT_LANG', 'en'));
    $loginDict   = HbpLocale::loadAdminDictionary($loginLocale);

    // Error display when returning to the login screen after a 2FA failure lockout
    $error = '';
    if (!empty($_GET['2fa_locked'])) {
        $error = $loginDict['two_factor_locked_error'] ?? 'Too many 2FA failures. Please log in again.';
    }
    if (isPost()) {
        // Soft CSRF validation (on mismatch, do not 403; redraw the screen with an error)
        if (!Security::checkCsrfToken($_POST['_csrf_token'] ?? '')) {
            $error = $loginDict['csrf_error'] ?? 'Session expired. Please reload and try again.';
            View::render('admin/login', compact('siteName', 'adminPath', 'error') + ['htmlLang' => $loginLocale]);
            return;
        }

        $email    = trim($_POST['email']    ?? '');
        $password = $_POST['password'] ?? '';
        $clientIp = class_exists('AccessControl') ? AccessControl::clientIp() : ($_SERVER['REMOTE_ADDR'] ?? '');

        // throttling check (error display for the UI)
        if (class_exists('Throttle') && !Throttle::isAllowed($email, $clientIp)) {
            $waitSec = class_exists('Throttle') ? Throttle::secondsUntilUnlock($email, $clientIp) : 60;
            $error   = ($loginDict['rate_limit_error'] ?? 'Too many login attempts. Please wait %d seconds.')
                       ? sprintf($loginDict['rate_limit_error'] ?? 'Too many login attempts. Please wait %d seconds.', $waitSec)
                       : "Too many login attempts. Please wait {$waitSec} seconds.";
        } else {
            $result = Auth::login($email, $password);

            if ($result === true) {
                header('Location: /' . $adminPath);
                exit;
            } elseif ($result === '2fa') {
                header('Location: /' . $adminPath . '/login/two-factor');
                exit;
            } else {
                $error = $loginDict['login_error'] ?? 'Invalid credentials.';
            }
        }
    }

    View::render('admin/login', compact('siteName', 'adminPath', 'error') + ['htmlLang' => $loginLocale]);
}

function handleAdminPosts(array $common): void
{
    // normal / trash view + search, filtering, pagination
    $view = ($_GET['view'] ?? '') === 'trash' ? 'trash' : 'active';

    // Filter conditions (kept in the query string so they persist even after returning from edit)
    $filters = [
        'view'        => $view,
        'q'           => trim((string)($_GET['q'] ?? '')),
        'type'        => in_array($_GET['type'] ?? '', ['post', 'page'], true) ? $_GET['type'] : '',
        'status'      => in_array($_GET['status'] ?? '', ['published', 'draft', 'scheduled'], true) ? $_GET['status'] : '',
        'category_id' => (int)($_GET['category_id'] ?? 0),
        'tag_id'      => (int)($_GET['tag_id'] ?? 0),
        'series_id'   => (int)($_GET['series_id'] ?? 0),
        'pub_from'    => trim((string)($_GET['pub_from'] ?? '')),
        'pub_to'      => trim((string)($_GET['pub_to'] ?? '')),
        'cre_from'    => trim((string)($_GET['cre_from'] ?? '')),
        'cre_to'      => trim((string)($_GET['cre_to'] ?? '')),
        'page'        => max(1, (int)($_GET['page'] ?? 1)),
    ];

    $result = AdminPostController::index($filters);

    // Choices for the filter dropdowns
    $allCategories = DB::fetchAll('SELECT id, type, lang, name FROM categories ORDER BY lang, type, name');
    $allTags       = DB::fetchAll('SELECT id, type, lang, name FROM tags ORDER BY lang, type, name');
    $allSeries     = DB::fetchAll('SELECT id, lang, title FROM series ORDER BY lang, title');

    // Build the query string excluding page for pagination links
    $qsParams = $filters;
    unset($qsParams['page']);
    $qsParams = array_filter($qsParams, static fn($v) => $v !== '' && $v !== 0);
    $baseQuery = http_build_query($qsParams);

    // For the listing: resolution map of category names / author names (no JOIN)
    $catNames = [];
    foreach ($allCategories as $c) {
        $catNames[(int)$c['id']] = $c['name'];
    }
    $authorNames = [];
    foreach ($result['posts'] as $p) {
        $uid = $p['user_id'] !== null ? (int)$p['user_id'] : 0;
        if (!isset($authorNames[$uid])) {
            $a = class_exists('AuthorCompiler') ? AuthorCompiler::get($uid) : [];
            $authorNames[$uid] = $a['name'] ?? '';
        }
    }

    View::render('admin/index', array_merge($common, [
        'posts'         => $result['posts'],
        'total'         => $result['total'],
        'page'          => $result['page'],
        'pages'         => $result['pages'],
        'view'          => $view,
        'filters'       => $filters,
        'allCategories' => $allCategories,
        'allTags'       => $allTags,
        'allSeries'     => $allSeries,
        'catNames'      => $catNames,
        'authorNames'   => $authorNames,
        'baseQuery'     => $baseQuery,
    ]));
}

/**
 * post:delete gate + ownership scope check.
 * Author can operate on their own content; Contributor only on their own drafts. Violations are 403.
 * Returns: the target post (status, etc.). null if absent.
 */
function assertCanDeletePost(int $postId): ?array
{
    if ($postId <= 0) {
        return null;
    }
    if (!Auth::isSuperUser() && Gate::cannot('post:delete')) {
        http_response_code(403);
        exit('403 Forbidden');
    }
    $post = DB::fetchOne('SELECT id, user_id, status FROM posts WHERE id = ? LIMIT 1', [$postId]);
    if (!$post) {
        return null;
    }
    if (!Auth::isSuperUser() && Gate::cannot('post:edit_other')) {
        // Own content only
        if ((int)$post['user_id'] !== Auth::id()) {
            http_response_code(403);
            exit('403 Forbidden');
        }
        // Contributor (cannot post:publish) can delete only their own "drafts"
        if (Gate::cannot('post:publish') && $post['status'] !== 'draft') {
            http_response_code(403);
            exit('403 Forbidden');
        }
    }
    return $post;
}

/** Days x publishing slots for one week (PublishSlots). Editors and up: it lists everyone's posts. */
function handleAdminSchedule(array $common): void
{
    Gate::authorize('post:edit_other');
    require_once CORE_PATH . '/PublishSlots.php';
    $tz    = PublishSlots::tz();
    $start = (string)($_GET['week'] ?? '');
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) ? $start : (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $week  = PublishSlots::week($start);
    $base  = new DateTimeImmutable($start, $tz);
    $dayLabels = [];
    foreach ($week['days'] as $d) {
        $dt = new DateTimeImmutable($d, $tz);
        $dayLabels[$d] = $dt->format('m/d') . ' ' . HbpLocale::t('weekday_' . strtolower($dt->format('D')), $dt->format('D'));
    }
    View::render('admin/schedule', array_merge($common, [
        'week'      => $week,
        'dayLabels' => $dayLabels,
        'prevWeek'  => $base->modify('-7 days')->format('Y-m-d'),
        'nextWeek'  => $base->modify('+7 days')->format('Y-m-d'),
    ]));
}

function handleAdminSpeakers(array $common): void
{
    Gate::authorize('blocks:manage');
    $speakers = DB::fetchAll('SELECT id, slug, name, avatar, side FROM speakers ORDER BY slug');
    View::render('admin/speakers', array_merge($common, compact('speakers')));
}

function handleAdminSpeakerSave(string $adminPath): void
{
    Gate::authorize('blocks:manage');
    require_once CORE_PATH . '/SpeakerCompiler.php';
    $id     = (int)($_POST['id'] ?? 0);
    $slug   = trim((string)($_POST['slug'] ?? ''));
    $name   = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 255);
    $avatar = trim((string)($_POST['avatar'] ?? ''));
    $avatar = $avatar !== '' ? SidebarCompiler::safeUrl($avatar, false) : '';
    $side   = ($_POST['side'] ?? '') === 'right' ? 'right' : 'left';
    if (!preg_match(SpeakerCompiler::SLUG_PATTERN, $slug) || $name === '') {
        header('Location: /' . $adminPath . '/speakers?error=1');
        exit;
    }
    try {
        if ($id > 0) {
            DB::execute('UPDATE speakers SET slug=?, name=?, avatar=?, side=?, updated_at=? WHERE id=?', [$slug, $name, $avatar ?: null, $side, gmdate('Y-m-d H:i:s'), $id]);
        } else {
            DB::execute('INSERT INTO speakers (slug, name, avatar, side) VALUES (?,?,?,?)', [$slug, $name, $avatar ?: null, $side]);
        }
    } catch (Throwable) {
        header('Location: /' . $adminPath . '/speakers?error=1');   // duplicate slug
        exit;
    }
    SpeakerCompiler::compile();
    header('Location: /' . $adminPath . '/speakers?saved=1');
    exit;
}

function handleAdminSpeakerDelete(string $adminPath): void
{
    Gate::authorize('blocks:manage');
    require_once CORE_PATH . '/SpeakerCompiler.php';
    DB::execute('DELETE FROM speakers WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
    SpeakerCompiler::compile();
    header('Location: /' . $adminPath . '/speakers');
    exit;
}

function handleAdminTemplates(array $common): void
{
    Gate::authorize('templates:manage');
    $templates = DB::fetchAll('SELECT id, name, type, lang FROM post_templates ORDER BY lang, name');
    $editing   = isset($_GET['id']) ? DB::fetchOne('SELECT * FROM post_templates WHERE id = ? LIMIT 1', [(int)$_GET['id']]) : null;
    $categories = DB::fetchAll("SELECT id, lang, name FROM categories ORDER BY lang, name");
    $layouts = [];
    $uiLocale = View::resolveCurrentLocale('admin/templates');
    foreach (ThemeManager::layouts() as $id => $def) {
        $layouts[$id] = ThemeManager::layoutLabel($def, $uiLocale);
    }
    View::render('admin/templates', array_merge($common, [
        'templates'      => $templates,
        'editing'        => $editing ?: null,
        'categories'     => $categories,
        'layouts'        => $layouts,
        'contentLocales' => HbpLocale::getAvailableContentLocales(),
    ]));
}

function handleAdminTemplateSave(string $adminPath): void
{
    Gate::authorize('templates:manage');
    $id   = (int)($_POST['id'] ?? 0);
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 255);
    $type = ($_POST['type'] ?? '') === 'page' ? 'page' : 'post';
    $lang = (string)($_POST['lang'] ?? '');
    if ($name === '' || !array_key_exists($lang, HbpLocale::getAvailableContentLocales())) {
        header('Location: /' . $adminPath . '/templates?error=1' . ($id ? '&id=' . $id : ''));
        exit;
    }
    $layout = (string)($_POST['layout'] ?? '');
    $layout = $layout !== '' && array_key_exists($layout, ThemeManager::layouts()) ? $layout : null;
    $catId  = (int)($_POST['category_id'] ?? 0) ?: null;
    $tags   = implode(', ', preg_split('/\s*,\s*/u', trim((string)($_POST['tags'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    $row = [
        $name, $type, $lang,
        mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255),
        str_replace(["\r\n", "\r"], "\n", (string)($_POST['body'] ?? '')),
        $catId, $tags !== '' ? $tags : null, $layout,
        !empty($_POST['show_toc']) ? 1 : 0, !empty($_POST['is_sponsored']) ? 1 : 0,
        gmdate('Y-m-d H:i:s'),
    ];
    if ($id > 0) {
        DB::execute(
            'UPDATE post_templates SET name=?, type=?, lang=?, title=?, body=?, category_id=?, tags=?, layout=?, show_toc=?, is_sponsored=?, updated_at=? WHERE id=?',
            array_merge($row, [$id])
        );
    } else {
        DB::execute(
            'INSERT INTO post_templates (name, type, lang, title, body, category_id, tags, layout, show_toc, is_sponsored, updated_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            array_merge($row, [gmdate('Y-m-d H:i:s')])
        );
        $id = (int)DB::lastInsertId();
    }
    header('Location: /' . $adminPath . '/templates?saved=1&id=' . $id);
    exit;
}

function handleAdminTemplateDelete(string $adminPath): void
{
    Gate::authorize('templates:manage');
    DB::execute('DELETE FROM post_templates WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
    header('Location: /' . $adminPath . '/templates');
    exit;
}

/** Forget one old URL of a post (the button lives inside the edit form, so the whole form posts here). */
function handleAdminRedirectDelete(string $adminPath): void
{
    $postId = (int)($_POST['id'] ?? 0);
    $post   = $postId > 0 ? DB::fetchOne('SELECT user_id FROM posts WHERE id = ? LIMIT 1', [$postId]) : null;
    $mine   = $post && (int)($post['user_id'] ?? 0) === Auth::id();
    if (!$post || (!Auth::isSuperUser() && !$mine && Gate::cannot('post:edit_other'))) {
        http_response_code(403);
        exit('403 Forbidden');
    }
    require_once CORE_PATH . '/RedirectCompiler.php';
    DB::execute('DELETE FROM slug_redirects WHERE id = ? AND post_id = ?', [(int)($_POST['redirect_id'] ?? 0), $postId]);
    RedirectCompiler::compile();
    header('Location: /' . $adminPath . '/post/edit/' . $postId);
    exit;
}

function handleAdminPostEdit(array $common, int $postId): void
{
    // New post: allow the form to be pre-seeded via query (e.g. the plugins screen's
    // "create a page with this template" link). Validated inside getEditData().
    $prefill = $postId > 0 ? [] : [
        'type'          => $_GET['type']          ?? '',
        'page_template' => $_GET['page_template'] ?? '',
        'template'      => (int)($_GET['template'] ?? 0),
    ];
    $data = AdminPostController::getEditData($postId > 0 ? $postId : null, $prefill);
    View::render('admin/edit', array_merge($common, $data));
}

function handleAdminPostSave(array $common, string $adminPath): void
{
    try {
        $id = AdminPostController::save($_POST);

        // recompile RouterCompiler only when a page's plugin-template assignment changes
        // Not triggered on post save (because of the pattern-matching approach)
        $type     = $_POST['type'] ?? 'post';
        $template = $_POST['page_template'] ?? '';
        if ($type === 'page' || $template !== '') {
            RouterCompiler::compile();
        }

        // Saved regardless; only remind the author when the body links to managed (/go/) links but the
        // post is not marked as sponsored.
        $hint = empty($_POST['is_sponsored']) && str_contains((string)($_POST['content_markdown'] ?? ''), '](/go/')
            ? '&sponsor_hint=1' : '';
        // Post cards ({{ card:slug }}) that found no published post were left out of the page
        $missing = MarkdownCompiler::missingCards();
        if ($missing) {
            $hint .= '&card_missing=' . rawurlencode(implode(',', $missing));
        }
        // The chosen series was of another language, so the post was saved outside any series
        if (AdminPostController::seriesDropped()) {
            $hint .= '&series_lang=1';
        }
        header('Location: /' . $adminPath . '/post/edit/' . $id . '?saved=1' . $hint);
    } catch (Exception $e) {
        $data = AdminPostController::getEditData(!empty($_POST['id']) ? (int)$_POST['id'] : null);
        View::render('admin/edit', array_merge($common, $data, ['error' => $e->getMessage()]));
    }
    exit;
}

// move to trash (logical delete)
function handleAdminPostTrash(string $adminPath): void
{
    $postId = (int)($_POST['id'] ?? 0);
    if (assertCanDeletePost($postId)) {
        AdminPostController::trash($postId);
    }
    header('Location: /' . $adminPath . '/posts?trashed=1');
    exit;
}

// restore from trash
function handleAdminPostRestore(string $adminPath): void
{
    $postId = (int)($_POST['id'] ?? 0);
    if (assertCanDeletePost($postId)) {
        AdminPostController::restore($postId);
    }
    header('Location: /' . $adminPath . '/posts?view=trash&restored=1');
    exit;
}

// delete permanently (physical)
function handleAdminPostPurge(string $adminPath): void
{
    $postId = (int)($_POST['id'] ?? 0);
    if (assertCanDeletePost($postId)) {
        AdminPostController::delete($postId);
    }
    header('Location: /' . $adminPath . '/posts?view=trash&purged=1');
    exit;
}

function handleAdminComments(array $common): void
{
    Gate::authorize('comment:moderate');
    $allowed = ['pending', 'approved', 'spam', 'trash'];
    $status  = $_GET['status'] ?? 'pending';
    $status  = in_array($status, $allowed, true) ? $status : 'pending';

    // filters (body is out of scope). After narrowing the population by status, LIKE (no index added)
    $filters = [
        'status'   => $status,
        'q_name'   => trim((string)($_GET['q_name']   ?? '')),
        'q_email'  => trim((string)($_GET['q_email']  ?? '')),
        'q_url'    => trim((string)($_GET['q_url']    ?? '')),
        'cre_from' => trim((string)($_GET['cre_from'] ?? '')),
        'cre_to'   => trim((string)($_GET['cre_to']   ?? '')),
        'post_id'  => (int)($_GET['post_id'] ?? 0),
        'page'     => max(1, (int)($_GET['page'] ?? 1)),
    ];

    // Dynamic WHERE construction (all parameterized). Utilizes idx_status_created -> idx_comments_post
    $where  = ['c.status = ?'];
    $params = [$status];
    $like = static fn(string $v): string => '%' . str_replace(['%', '_'], ['\%', '\_'], $v) . '%';
    if ($filters['q_name']  !== '') { $where[] = 'c.author_name LIKE ?';  $params[] = $like($filters['q_name']); }
    if ($filters['q_email'] !== '') { $where[] = 'c.author_email LIKE ?'; $params[] = $like($filters['q_email']); }
    if ($filters['q_url']   !== '') { $where[] = 'c.author_url LIKE ?';   $params[] = $like($filters['q_url']); }
    if ($filters['post_id'] > 0)    { $where[] = 'c.post_id = ?';         $params[] = $filters['post_id']; }
    foreach (['cre_from' => ['>=', ' 00:00:00'], 'cre_to' => ['<=', ' 23:59:59']] as $key => $spec) {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$key])) {
            $where[]  = "c.created_at {$spec[0]} ?";
            $params[] = $filters[$key] . $spec[1];
        }
    }
    $whereSql = implode(' AND ', $where);

    // Total count (same conditions) -> pagination
    $totalRow = DB::fetchOne("SELECT COUNT(*) AS c FROM comments c WHERE {$whereSql}", $params);
    $total    = (int)($totalRow['c'] ?? 0);
    $size     = (int)(class_exists('Env') ? Env::get('LIST_PAGE_SIZE', '10') : 10);
    $size     = $size > 0 ? $size : 10;
    $pages    = max(1, (int)ceil($total / $size));
    $page     = min($filters['page'], $pages);
    $offset   = ($page - 1) * $size;

    $comments = DB::fetchAll(
        "SELECT c.*, p.title AS post_title, p.slug AS post_slug, p.type AS post_type
         FROM comments c JOIN posts p ON p.id = c.post_id
         WHERE {$whereSql} ORDER BY c.created_at DESC LIMIT {$size} OFFSET {$offset}",
        $params
    );

    // Count badges (totals per status, independent of other filters)
    $counts = [];
    foreach ($allowed as $s) {
        $row = DB::fetchOne("SELECT COUNT(*) AS c FROM comments WHERE status=?", [$s]);
        $counts[$s] = (int)($row['c'] ?? 0);
    }

    // Query string for pagination/tabs (keeps everything but page)
    $qsParams = array_filter([
        'status'   => $status,
        'q_name'   => $filters['q_name'],
        'q_email'  => $filters['q_email'],
        'q_url'    => $filters['q_url'],
        'cre_from' => $filters['cre_from'],
        'cre_to'   => $filters['cre_to'],
        'post_id'  => $filters['post_id'] ?: '',
    ], static fn($v) => $v !== '' && $v !== 0);
    $baseQuery = http_build_query($qsParams);

    // For status tabs: keep filters other than status (so filtering persists across tab switches)
    $tabParams = $qsParams;
    unset($tabParams['status']);
    $tabQuery = http_build_query($tabParams);

    View::render('admin/comments', array_merge($common, compact('comments', 'status', 'counts', 'filters', 'page', 'pages', 'total', 'baseQuery', 'tabQuery')));
}

function handleAdminCommentAction(string $adminPath): void
{
    Gate::authorize('comment:moderate');
    $action    = $_POST['action']     ?? '';
    $commentId = (int)($_POST['id']   ?? 0);
    $comment   = DB::fetchOne('SELECT post_id FROM comments WHERE id=? LIMIT 1', [$commentId]);

    if ($comment) {
        match ($action) {
            'approve' => DB::execute("UPDATE comments SET status='approved' WHERE id=?", [$commentId]),
            'spam'    => DB::execute("UPDATE comments SET status='spam'     WHERE id=?", [$commentId]),
            'trash'   => DB::execute("UPDATE comments SET status='trash'    WHERE id=?", [$commentId]),
            'delete'  => DB::execute('DELETE FROM comments WHERE id=?',                  [$commentId]),
            default   => null,
        };
        require_once CORE_PATH . '/CommentCompiler.php';
        CommentCompiler::compile((int)$comment['post_id']);
    }
    header('Location: /' . $adminPath . '/comments');
    exit;
}

function handleAdminMenu(array $common): void
{
    Gate::authorize('nav:manage');
    $menus = MenuCompiler::loadData();
    View::render('admin/menu', array_merge($common, compact('menus')));
}

function handleAdminMenuSave(string $adminPath): void
{
    Gate::authorize('nav:manage');
    $location = $_POST['location'] ?? '';
    // navigation_menus.location is fixed to 6 locations. Reject out-of-range (prevents orphan rows / VARCHAR(30) overflow from invalid values)
    $validLocations = ['pc_header', 'pc_sidebar_left', 'pc_sidebar_right', 'pc_footer', 'sp_header', 'sp_footer'];
    if (!in_array($location, $validLocations, true)) {
        header('Location: /' . $adminPath . '/menu?error=1');
        exit;
    }
    $items    = [];
    $titles   = $_POST['titles'] ?? [];
    $urls     = $_POST['urls']   ?? [];
    foreach ($titles as $i => $title) {
        $title = trim($title);
        $url   = isset($urls[$i]) ? trim($urls[$i]) : '';
        // Output as href, so allow only http(s) or relative (/ or #) (exclude javascript: etc.). title/url are VARCHAR(255)
        $urlOk = $url !== '' && ($url[0] === '/' || $url[0] === '#' || preg_match('#^https?://#i', $url));
        if ($title !== '' && $urlOk) {
            $items[] = ['title' => mb_substr($title, 0, 255), 'url' => mb_substr($url, 0, 255)];
        }
    }
    MenuCompiler::save($location, $items);
    // 'menu' widgets embed a menu, so the areas are rebuilt too
    foreach (hbpContentLangs() as $lang) {
        SidebarCompiler::compileAllLayouts($lang);
    }

    // A menu change affects the site-wide header, so purge all FPC
    require_once CORE_PATH . '/CacheManager.php';
    CacheManager::purgeAll();

    header('Location: /' . $adminPath . '/menu?saved=1');
    exit;
}

function handleAdminSidebar(array $common): void
{
    Gate::authorize('nav:manage');
    $areas = SidebarCompiler::LOCATIONS;
    $area  = in_array($_GET['area'] ?? '', $areas, true) ? $_GET['area'] : $areas[0];

    $widgets = [];
    foreach (DB::fetchAll(
        'SELECT id, widget_key, title, settings, sort_order FROM sidebar_widgets WHERE sidebar_location = ? ORDER BY sort_order, id',
        [$area]
    ) as $row) {
        $settings        = json_decode((string)($row['settings'] ?? ''), true);
        $row['settings'] = is_array($settings) ? $settings : [];
        $widgets[]       = $row;
    }
    $counts = [];
    foreach (DB::fetchAll('SELECT sidebar_location, COUNT(*) AS n FROM sidebar_widgets GROUP BY sidebar_location') as $r) {
        $counts[$r['sidebar_location']] = (int)$r['n'];
    }
    $allWidgets    = SidebarCompiler::WIDGET_KEYS;
    $canRawHtml    = Gate::can('post:raw_html');
    require_once CORE_PATH . '/MenuCompiler.php';
    $menuLocations = MenuCompiler::LOCATIONS;
    View::render('admin/sidebar', array_merge($common, compact('areas', 'area', 'widgets', 'counts', 'allWidgets', 'canRawHtml', 'menuLocations')));
}

/** Content languages, falling back to the default language. */
function hbpContentLangs(): array
{
    $contentLocales = HbpLocale::getAvailableContentLocales();
    return $contentLocales ? array_keys($contentLocales) : [Env::get('SITE_DEFAULT_LANG', 'en')];
}

/** After any widget change: rebuild every area in every language, the CSP, and the page cache. */
function hbpRecompileWidgets(): void
{
    foreach (hbpContentLangs() as $lang) {
        SidebarCompiler::compileRecentCommentsWidget($lang);
        SidebarCompiler::compileArchiveWidget($lang);
        SidebarCompiler::compileAllLayouts($lang);
    }
    // 'html' widgets may carry inline scripts: their hashes go into the CSP
    SidebarCompiler::compileHtmlSources();
    SecurityHeaderCompiler::compile();
    CacheManager::purgeAll();
}

/**
 * Save one area: headings, order, settings and deletions of its existing widgets.
 *
 * 'html' widgets print raw HTML, so only roles with post:raw_html may change or delete them. For
 * anyone else their stored title/settings are kept as they are; reordering is still allowed.
 */
function handleAdminSidebarSave(string $adminPath): void
{
    Gate::authorize('nav:manage');
    $location = (string)($_POST['location'] ?? '');
    if (!in_array($location, SidebarCompiler::LOCATIONS, true)) {
        header('Location: /' . $adminPath . '/sidebar?error=1');
        exit;
    }
    $back       = '/' . $adminPath . '/sidebar?area=' . rawurlencode($location);
    $canRawHtml = Gate::can('post:raw_html');
    $posted     = (array)($_POST['widgets'] ?? []);

    $rows = DB::fetchAll('SELECT id, widget_key, title, settings FROM sidebar_widgets WHERE sidebar_location = ?', [$location]);
    foreach ($rows as $row) {
        $id  = (int)$row['id'];
        $key = (string)$row['widget_key'];
        $in  = isset($posted[$id]) && is_array($posted[$id]) ? $posted[$id] : null;
        if ($in === null) {
            continue;
        }
        $locked = $key === 'html' && !$canRawHtml;

        if (!empty($in['delete'])) {
            if (!$locked) {
                DB::execute('DELETE FROM sidebar_widgets WHERE id = ?', [$id]);
            }
            continue;
        }

        $order = (int)($in['order'] ?? 0);
        if ($locked) {
            DB::execute('UPDATE sidebar_widgets SET sort_order = ? WHERE id = ?', [$order, $id]);
            continue;
        }
        $title    = mb_substr(trim((string)($in['title'] ?? '')), 0, 255);
        $settings = SidebarCompiler::normalizeSettings($key, (array)($in['settings'] ?? []));
        $json     = $settings === null ? null : json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json !== null && strlen($json) > 65535) {
            header('Location: ' . $back . '&error=too_long');
            exit;
        }
        DB::execute(
            'UPDATE sidebar_widgets SET title = ?, settings = ?, sort_order = ? WHERE id = ?',
            [$title === '' ? null : $title, $json, $order, $id]
        );
    }

    hbpRecompileWidgets();
    header('Location: ' . $back . '&saved=1');
    exit;
}

/** Append a new widget to an area, with default settings. */
function handleAdminSidebarAdd(string $adminPath): void
{
    Gate::authorize('nav:manage');
    $location = (string)($_POST['location'] ?? '');
    $key      = (string)($_POST['widget_key'] ?? '');
    if (!in_array($location, SidebarCompiler::LOCATIONS, true) || !in_array($key, SidebarCompiler::WIDGET_KEYS, true)) {
        header('Location: /' . $adminPath . '/sidebar?error=1');
        exit;
    }
    $back = '/' . $adminPath . '/sidebar?area=' . rawurlencode($location);
    if ($key === 'html' && !Gate::can('post:raw_html')) {
        header('Location: ' . $back . '&error=permission');
        exit;
    }
    $max      = DB::fetchOne('SELECT MAX(sort_order) AS m FROM sidebar_widgets WHERE sidebar_location = ?', [$location]);
    $order    = $max && $max['m'] !== null ? (int)$max['m'] + 1 : 0;
    $settings = SidebarCompiler::defaultSettings($key);
    DB::execute(
        'INSERT INTO sidebar_widgets (sidebar_location, widget_key, title, settings, sort_order, created_at) VALUES (?,?,?,?,?,?)',
        [$location, $key, null, $settings === null ? null : json_encode($settings, JSON_UNESCAPED_UNICODE), $order, gmdate('Y-m-d H:i:s')]
    );

    hbpRecompileWidgets();
    header('Location: ' . $back . '&added=1');
    exit;
}

// ------------------------------------------------------------
// Reusable blocks ({{ block:slug }}). Editing one rebuilds blocks.cache.php and purges the page
// cache; the posts that use it are not recompiled (see BlockCompiler).
// ------------------------------------------------------------

function handleAdminBlocks(array $common): void
{
    Gate::authorize('blocks:manage');
    require_once CORE_PATH . '/BlockCompiler.php';
    $blocks  = DB::fetchAll('SELECT id, slug, lang, title, updated_at FROM blocks ORDER BY lang, slug');
    $id      = (int)($_GET['id'] ?? 0);
    $editing = $id > 0 ? (DB::fetchOne('SELECT id, slug, lang, title, body FROM blocks WHERE id = ?', [$id]) ?: null) : null;
    // Preview with the same profile the public pages get (raw HTML allowed: blocks:manage is trusted).
    $preview        = $editing ? MarkdownCompiler::render((string)$editing['body'], true) : '';
    $contentLocales = hbpContentLocales();
    View::render('admin/blocks', array_merge($common, compact('blocks', 'editing', 'preview', 'contentLocales')));
}

/** Content language code => label, falling back to the default language. */
function hbpContentLocales(): array
{
    $locales = HbpLocale::getAvailableContentLocales();
    return $locales ?: [Env::get('SITE_DEFAULT_LANG', 'en') => strtoupper(Env::get('SITE_DEFAULT_LANG', 'en'))];
}

function hbpAfterBlocksChange(): void
{
    require_once CORE_PATH . '/BlockCompiler.php';
    BlockCompiler::compile();
    CacheManager::purgeAll();
}

function handleAdminBlockSave(string $adminPath): void
{
    Gate::authorize('blocks:manage');
    require_once CORE_PATH . '/BlockCompiler.php';
    $id    = (int)($_POST['id'] ?? 0);
    $slug  = strtolower(trim((string)($_POST['slug'] ?? '')));
    $lang  = (string)($_POST['lang'] ?? '');
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
    // LF like every other stored body (a textarea posts CRLF)
    $body  = mb_substr(trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['body'] ?? ''))), 0, BlockCompiler::BODY_MAX);
    $back  = '/' . $adminPath . '/blocks' . ($id > 0 ? '?id=' . $id . '&' : '?');

    if (!preg_match(BlockCompiler::SLUG_PATTERN, $slug) || !isset(hbpContentLocales()[$lang]) || $title === '') {
        header('Location: ' . $back . 'error=invalid');
        exit;
    }
    $dup = DB::fetchOne('SELECT id FROM blocks WHERE slug = ? AND lang = ? AND id <> ?', [$slug, $lang, $id]);
    if ($dup) {
        header('Location: ' . $back . 'error=duplicate');
        exit;
    }
    $now = gmdate('Y-m-d H:i:s');
    if ($id > 0) {
        // updated_at is set explicitly: PostgreSQL has no ON UPDATE CURRENT_TIMESTAMP
        DB::execute('UPDATE blocks SET slug = ?, lang = ?, title = ?, body = ?, updated_at = ? WHERE id = ?', [$slug, $lang, $title, $body, $now, $id]);
    } else {
        DB::execute('INSERT INTO blocks (slug, lang, title, body, created_at, updated_at) VALUES (?,?,?,?,?,?)', [$slug, $lang, $title, $body, $now, $now]);
        $id = (int)DB::lastInsertId();
    }
    hbpAfterBlocksChange();
    header('Location: /' . $adminPath . '/blocks?id=' . $id . '&saved=1');
    exit;
}

function handleAdminBlockDelete(string $adminPath): void
{
    Gate::authorize('blocks:manage');
    DB::execute('DELETE FROM blocks WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
    hbpAfterBlocksChange();
    header('Location: /' . $adminPath . '/blocks?deleted=1');
    exit;
}

// ------------------------------------------------------------
// Managed links (/go/{alias}). Served from links.cache.php, outside the page cache, so a change
// needs no purge.
// ------------------------------------------------------------

function handleAdminLinks(array $common): void
{
    Gate::authorize('links:manage');
    $links = DB::fetchAll('SELECT id, alias, target_url, note, updated_at FROM links ORDER BY alias');
    View::render('admin/links', array_merge($common, compact('links')));
}

/** Save the whole table at once: edits and deletions of existing rows plus one optional new row. */
function handleAdminLinksSave(string $adminPath): void
{
    Gate::authorize('links:manage');
    require_once CORE_PATH . '/LinkCompiler.php';
    $back = '/' . $adminPath . '/links';
    $now  = gmdate('Y-m-d H:i:s');

    $rows = [];
    foreach ((array)($_POST['links'] ?? []) as $id => $in) {
        if (is_array($in) && (int)$id > 0) {
            $rows[(int)$id] = $in;
        }
    }
    $new = [
        'alias'      => strtolower(trim((string)($_POST['new_alias'] ?? ''))),
        'target_url' => trim((string)($_POST['new_target_url'] ?? '')),
        'note'       => mb_substr(trim((string)($_POST['new_note'] ?? '')), 0, 255),
    ];

    // Validate everything first, so a bad row leaves the table untouched.
    $aliases = [];
    foreach ($rows as $id => $in) {
        if (!empty($in['delete'])) {
            continue;
        }
        $alias = strtolower(trim((string)($in['alias'] ?? '')));
        $url   = trim((string)($in['target_url'] ?? ''));
        if (!preg_match(LinkCompiler::ALIAS_PATTERN, $alias) || !LinkCompiler::isValidTarget($url) || isset($aliases[$alias])) {
            header('Location: ' . $back . '?error=invalid&row=' . $id);
            exit;
        }
        $aliases[$alias] = true;
    }
    $addNew = $new['alias'] !== '' || $new['target_url'] !== '';
    if ($addNew && (!preg_match(LinkCompiler::ALIAS_PATTERN, $new['alias']) || !LinkCompiler::isValidTarget($new['target_url']) || isset($aliases[$new['alias']]))) {
        header('Location: ' . $back . '?error=invalid&row=new');
        exit;
    }

    foreach ($rows as $id => $in) {
        if (!empty($in['delete'])) {
            DB::execute('DELETE FROM links WHERE id = ?', [$id]);
            continue;
        }
        DB::execute(
            'UPDATE links SET alias = ?, target_url = ?, note = ?, updated_at = ? WHERE id = ?',
            [strtolower(trim((string)$in['alias'])), trim((string)$in['target_url']), mb_substr(trim((string)($in['note'] ?? '')), 0, 255) ?: null, $now, $id]
        );
    }
    if ($addNew) {
        $exists = DB::fetchOne('SELECT id FROM links WHERE alias = ?', [$new['alias']]);
        if ($exists) {
            LinkCompiler::compile();
            header('Location: ' . $back . '?error=duplicate');
            exit;
        }
        DB::execute(
            'INSERT INTO links (alias, target_url, note, created_at, updated_at) VALUES (?,?,?,?,?)',
            [$new['alias'], $new['target_url'], $new['note'] ?: null, $now, $now]
        );
    }
    LinkCompiler::compile();
    header('Location: ' . $back . '?saved=1');
    exit;
}

/**
 * Series: list, create/edit form, and the ordering of the posts of the series being edited.
 * Same permission as categories (taxonomy:manage): a series is a way of grouping posts.
 */
function handleAdminSeries(array $common): void
{
    Gate::authorize('taxonomy:manage');
    require_once CORE_PATH . '/Series.php';   // Series::path() in the view
    // member_count: every post of the series, drafts included, for the delete confirmation
    $series     = DB::fetchAll(
        'SELECT s.*, (SELECT COUNT(*) FROM posts p WHERE p.series_id = s.id) AS member_count
           FROM series s ORDER BY s.lang, s.title'
    );
    $editId     = (int)($_GET['edit'] ?? 0);
    $editSeries = null;
    foreach ($series as $s) {
        if ((int)$s['id'] === $editId) {
            $editSeries = $s;
        }
    }
    // Every post of the series, drafts and scheduled ones included, in series order
    $episodes = $editSeries ? DB::fetchAll(
        'SELECT id, title, status, series_part, published_at, char_count FROM posts
          WHERE series_id = ? AND deleted_at IS NULL ORDER BY series_order, id',
        [$editId]
    ) : [];
    $contentLocales = HbpLocale::getAvailableContentLocales();
    View::render('admin/series', array_merge($common, compact('series', 'editSeries', 'episodes', 'contentLocales')));
}

function handleAdminSeriesSave(string $adminPath): void
{
    Gate::authorize('taxonomy:manage');
    require_once CORE_PATH . '/Series.php';
    require_once CORE_PATH . '/SeriesCompiler.php';
    $id     = (int)($_POST['id'] ?? 0);
    $title  = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
    $lang   = trim((string)($_POST['lang'] ?? ''));
    if (!isset(hbpContentLocales()[$lang])) {
        $lang = Env::get('SITE_DEFAULT_LANG', 'en');
    }
    // An ASCII slug, typed or derived from the title; a title with no ASCII left needs one typed
    $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
    if ($slug === '') {
        $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-');
    }
    $status = in_array($_POST['status'] ?? '', Series::STATUSES, true) ? $_POST['status'] : 'ongoing';
    $desc   = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['description'] ?? '')));
    $feedOn = !empty($_POST['feed_enabled']) ? 1 : 0;
    $back   = '/' . $adminPath . '/series' . ($id > 0 ? '?edit=' . $id . '&' : '?');

    $before = $id > 0 ? DB::fetchOne('SELECT * FROM series WHERE id = ? LIMIT 1', [$id]) : null;
    if ($title === '' || !preg_match(Series::SLUG_PATTERN, $slug) || ($id > 0 && !$before)) {
        header('Location: ' . $back . 'error=1');
        exit;
    }
    // Every post of a series has the series' language: a series that has posts keeps its language
    if ($before && $before['lang'] !== $lang
        && DB::fetchOne('SELECT 1 AS x FROM posts WHERE series_id = ? LIMIT 1', [$id])) {
        header('Location: ' . $back . 'lang_locked=1');
        exit;
    }

    // Description: Markdown rendered once here, without raw HTML (like a post saved without post:raw_html)
    $descHtml = $desc !== '' ? MarkdownCompiler::render($desc, false, ['lang' => $lang]) : null;

    // Cover: a new upload replaces the old file, the checkbox removes it
    $cover = $before['cover_image'] ?? null;
    try {
        if (isset($_FILES['cover']) && ($_FILES['cover']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $newCover = Media::avatarUrl(AdminUserController::processAvatarUpload($_FILES['cover']));
            hbpDeleteSeriesCover($cover);
            $cover = $newCover;
        } elseif (!empty($_POST['cover_remove'])) {
            hbpDeleteSeriesCover($cover);
            $cover = null;
        }
    } catch (Throwable $e) {
        header('Location: ' . $back . 'cover_error=1');
        exit;
    }

    try {
        if ($id > 0) {
            DB::execute(
                'UPDATE series SET lang=?, slug=?, title=?, description=?, description_html=?, status=?, cover_image=?, feed_enabled=?, updated_at=? WHERE id=?',
                [$lang, $slug, $title, $desc !== '' ? $desc : null, $descHtml, $status, $cover, $feedOn, gmdate('Y-m-d H:i:s'), $id]
            );
        } else {
            DB::execute(
                'INSERT INTO series (lang, slug, title, description, description_html, status, cover_image, feed_enabled) VALUES (?,?,?,?,?,?,?,?)',
                [$lang, $slug, $title, $desc !== '' ? $desc : null, $descHtml, $status, $cover, $feedOn]
            );
            $id = (int)DB::lastInsertId();
        }
    } catch (Throwable) {
        header('Location: ' . $back . 'error=1');   // duplicate (lang, slug)
        exit;
    }

    SeriesCompiler::compile();
    if ($before) {
        // Each post shows the series' title and status; the old table of contents may be at another slug
        Series::purgeAll($id, (string)$before['lang'], (string)$before['slug']);
        Series::purgeToc($id);
    }
    hbpSeriesSideEffects([$lang, (string)($before['lang'] ?? $lang)]);
    header('Location: /' . $adminPath . '/series?edit=' . $id . '&saved=1');
    exit;
}

function handleAdminSeriesDelete(string $adminPath): void
{
    Gate::authorize('taxonomy:manage');
    require_once CORE_PATH . '/Series.php';
    require_once CORE_PATH . '/SeriesCompiler.php';
    $id     = (int)($_POST['id'] ?? 0);
    $series = $id > 0 ? DB::fetchOne('SELECT * FROM series WHERE id = ? LIMIT 1', [$id]) : null;
    if ($series) {
        // Purge while the posts still point at the series, then let them go
        Series::purgeAll($id, (string)$series['lang'], (string)$series['slug']);
        $pdo = DB::getInstance();
        $pdo->beginTransaction();
        try {
            DB::execute('UPDATE posts SET series_id = NULL, series_order = 0, series_part = NULL WHERE series_id = ?', [$id]);
            DB::execute('DELETE FROM series WHERE id = ?', [$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        hbpDeleteSeriesCover($series['cover_image'] ?? null);
        SeriesCompiler::compile();
        hbpSeriesSideEffects([(string)$series['lang']]);
    }
    header('Location: /' . $adminPath . '/series');
    exit;
}

function handleAdminSeriesReorder(string $adminPath): void
{
    Gate::authorize('taxonomy:manage');
    require_once CORE_PATH . '/Series.php';
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0 && DB::fetchOne('SELECT 1 AS x FROM series WHERE id = ? LIMIT 1', [$id])) {
        $parts = [];
        foreach ((array)($_POST['part'] ?? []) as $postId => $part) {
            $parts[(int)$postId] = (string)$part;
        }
        Series::reorder($id, array_map('intval', (array)($_POST['ids'] ?? [])), $parts);
        // Neighbours and chapter headings can change anywhere in the series
        Series::purgeAll($id);
    }
    header('Location: /' . $adminPath . '/series?edit=' . $id . '&saved=1');
    exit;
}

/** Remove a series cover file that was uploaded through the series screen (an avatars-tree path). */
function hbpDeleteSeriesCover(?string $cover): void
{
    $prefix = '/assets/site/avatars/';
    if ($cover !== null && str_starts_with($cover, $prefix)) {
        $file = Media::avatarPath(basename($cover));
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

/**
 * What a series change reaches outside its own pages: the sitemap, the series feeds and the
 * "Series" widget (every cached page embeds the widget areas, so a changed area purges them all —
 * the same rule as a post save).
 */
function hbpSeriesSideEffects(array $langs): void
{
    SiteFileCompiler::compileSitemap();
    require_once CORE_PATH . '/FeedCompiler.php';
    require_once CORE_PATH . '/CacheManager.php';
    $areasChanged = false;
    foreach (array_unique(array_filter($langs)) as $lang) {
        FeedCompiler::compile($lang);
        $areasChanged = SidebarCompiler::compileAllLayouts($lang) || $areasChanged;
    }
    if ($areasChanged) {
        CacheManager::purgeAll();
    }
}

function handleAdminCategories(array $common): void
{
    Gate::authorize('taxonomy:manage');
    $categories    = DB::fetchAll("SELECT * FROM categories ORDER BY lang, type, name");
    $contentLocales = HbpLocale::getAvailableContentLocales();
    View::render('admin/categories', array_merge($common, compact('categories', 'contentLocales')));
}

function handleAdminCategorySave(string $adminPath): void
{
    Gate::authorize('taxonomy:manage');
    $id   = (int)($_POST['id'] ?? 0);
    $type = $_POST['type'] ?? 'post';
    $type = in_array($type, ['post', 'page'], true) ? $type : 'post';
    $lang = trim($_POST['lang'] ?? (Env::get('SITE_DEFAULT_LANG', 'en')));
    if (!preg_match('/^[a-z]{2,5}$/', $lang)) $lang = Env::get('SITE_DEFAULT_LANG', 'en');
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    if ($slug === '') $slug = mb_strtolower(preg_replace('/[^\w-]/u', '-', $name));
    $slug = trim(preg_replace('/-+/', '-', $slug), '-');
    // categories.name NOT NULL (reject empty string) + VARCHAR(255) limit
    if ($name === '') {
        header('Location: /' . $adminPath . '/categories?error=1');
        exit;
    }
    $name = mb_substr($name, 0, 255);
    $slug = mb_substr($slug, 0, 255);
    // Hashtags for generated share text: '#a #b' / 'a, b' -> '#a #b'
    $tags = preg_split('/[\s,]+/u', trim((string)($_POST['hashtags'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $hashtags = $tags ? mb_substr(implode(' ', array_map(static fn($t) => '#' . ltrim($t, '#'), $tags)), 0, 255) : null;
    $feedOn   = $type === 'post' && !empty($_POST['feed_enabled']) ? 1 : 0;
    $before   = $id > 0 ? DB::fetchOne('SELECT lang, hashtags FROM categories WHERE id=? LIMIT 1', [$id]) : null;

    // Do not 500 on a UNIQUE(type,lang,slug) violation; return it as a save failure
    try {
        if ($id > 0) {
            DB::execute('UPDATE categories SET type=?, lang=?, slug=?, name=?, hashtags=?, feed_enabled=? WHERE id=?', [$type, $lang, $slug, $name, $hashtags, $feedOn, $id]);
        } else {
            DB::execute('INSERT INTO categories (type, lang, slug, name, hashtags, feed_enabled) VALUES (?,?,?,?,?,?)', [$type, $lang, $slug, $name, $hashtags, $feedOn]);
        }
    } catch (Throwable) {
        header('Location: /' . $adminPath . '/categories?error=1');
        exit;
    }
    CategoryCompiler::compile();
    // Share text carries the hashtags, and category feeds follow the slug and the switch
    require_once CORE_PATH . '/PostCompiler.php';
    require_once CORE_PATH . '/FeedCompiler.php';
    if ($id > 0 && ($before['hashtags'] ?? null) !== $hashtags) {
        PostCompiler::refreshShareText($id);
    }
    FeedCompiler::compile($lang);
    if ($before && $before['lang'] !== $lang) {
        FeedCompiler::compile((string)$before['lang']);
    }
    // A category name/slug change affects the category-listing FPC, so purge
    if ($slug !== '') {
        require_once CORE_PATH . '/CacheManager.php';
        CacheManager::purgeListPages("{$lang}_list_category_{$slug}");
        CacheManager::purgeListPages("{$lang}_home");
    }
    // categories are resolved by pattern matching, so RouterCompiler is unnecessary
    header('Location: /' . $adminPath . '/categories?saved=1');
    exit;
}

function handleAdminCategoryDelete(string $adminPath): void
{
    Gate::authorize('taxonomy:manage');
    $catId = (int)($_POST['id'] ?? 0);

    // Fetch category info before deletion (for the FPC purge)
    $cat = $catId > 0
        ? DB::fetchOne('SELECT slug, lang FROM categories WHERE id=? LIMIT 1', [$catId])
        : null;

    DB::execute('DELETE FROM categories WHERE id=?', [$catId]);
    CategoryCompiler::compile();
    if ($cat) {
        require_once CORE_PATH . '/FeedCompiler.php';
        FeedCompiler::compile((string)$cat['lang']);   // drops the deleted category's feed files
    }

    // Purge the category-listing FPC (all pages)
    if ($cat) {
        require_once CORE_PATH . '/CacheManager.php';
        CacheManager::purgeListPages("{$cat['lang']}_list_category_{$cat['slug']}");
        // Deleting a category also changes related post listings, so blow away the top page too
        CacheManager::purgeListPages("{$cat['lang']}_home");
    }

    header('Location: /' . $adminPath . '/categories');
    exit;
}

function handleAdminTags(array $common): void
{
    Gate::authorize('taxonomy:manage');
    $tags           = DB::fetchAll("SELECT * FROM tags ORDER BY lang, type, name");
    $contentLocales = HbpLocale::getAvailableContentLocales();
    View::render('admin/tags', array_merge($common, compact('tags', 'contentLocales')));
}

function handleAdminTagSave(string $adminPath): void
{
    Gate::authorize('taxonomy:manage');
    $id   = (int)($_POST['id'] ?? 0);
    $type = $_POST['type'] ?? 'post';
    $type = in_array($type, ['post', 'page'], true) ? $type : 'post';
    $lang = trim($_POST['lang'] ?? (Env::get('SITE_DEFAULT_LANG', 'en')));
    if (!preg_match('/^[a-z]{2,5}$/', $lang)) $lang = Env::get('SITE_DEFAULT_LANG', 'en');
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    if ($slug === '') $slug = mb_strtolower(preg_replace('/[^\w-]/u', '-', $name));
    $slug = trim(preg_replace('/-+/', '-', $slug), '-');
    // tags.name NOT NULL (reject empty string) + VARCHAR(255) limit
    if ($name === '') {
        header('Location: /' . $adminPath . '/tags?error=1');
        exit;
    }
    $name = mb_substr($name, 0, 255);
    $slug = mb_substr($slug, 0, 255);

    // Do not 500 on a UNIQUE(type,lang,slug) violation; return it as a save failure
    try {
        if ($id > 0) {
            DB::execute('UPDATE tags SET type=?, lang=?, slug=?, name=? WHERE id=?', [$type, $lang, $slug, $name, $id]);
        } else {
            DB::execute('INSERT INTO tags (type, lang, slug, name) VALUES (?,?,?,?)', [$type, $lang, $slug, $name]);
        }
    } catch (Throwable) {
        header('Location: /' . $adminPath . '/tags?error=1');
        exit;
    }
    TagCompiler::compile();
    // A tag name/slug change affects the tag-listing FPC, so purge
    if ($slug !== '') {
        require_once CORE_PATH . '/CacheManager.php';
        CacheManager::purgeListPages("{$lang}_list_tag_{$slug}");
    }
    // tags are resolved by pattern matching, so RouterCompiler is unnecessary
    header('Location: /' . $adminPath . '/tags?saved=1');
    exit;
}

function handleAdminTagDelete(string $adminPath): void
{
    Gate::authorize('taxonomy:manage');
    $tagId = (int)($_POST['id'] ?? 0);

    // Fetch tag info before deletion (for the FPC purge)
    $tag = $tagId > 0
        ? DB::fetchOne('SELECT slug, lang FROM tags WHERE id=? LIMIT 1', [$tagId])
        : null;

    DB::execute('DELETE FROM tags WHERE id=?', [$tagId]);
    TagCompiler::compile();

    // Purge the tag-listing FPC (all pages)
    if ($tag) {
        require_once CORE_PATH . '/CacheManager.php';
        CacheManager::purgeListPages("{$tag['lang']}_list_tag_{$tag['slug']}");
    }

    header('Location: /' . $adminPath . '/tags');
    exit;
}

function handleAdminUsers(array $common): void
{
    Gate::authorize('user:manage');
    $users = AdminUserController::list();
    View::render('admin/users', array_merge($common, compact('users')));
}

function handleAdminUserSave(string $adminPath): void
{
    Gate::authorize('user:manage');
    try {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            AdminUserController::update($id, $_POST);
        } else {
            AdminUserController::create($_POST);
        }
        header('Location: /' . $adminPath . '/users?saved=1');
    } catch (Exception $e) {
        header('Location: /' . $adminPath . '/users?error=' . urlencode($e->getMessage()));
    }
    exit;
}

function handleAdminUserRole(string $adminPath): void
{
    Gate::authorize('user:manage');
    try {
        $n = AdminUserController::changeRole((int)($_POST['id'] ?? 0), (string)($_POST['role'] ?? ''), !empty($_POST['revoke_raw_html']));
        header('Location: /' . $adminPath . '/users?saved=1&revoked=' . $n);
    } catch (Throwable $e) {
        header('Location: /' . $adminPath . '/users?error=' . urlencode($e->getMessage()));
    }
    exit;
}

function handleAdminUserDelete(string $adminPath): void
{
    Gate::authorize('user:manage');
    try {
        AdminUserController::delete((int)($_POST['id'] ?? 0));
    } catch (Throwable $e) {
        // Deleting a user who still owns content is refused by fk_posts_user (ON DELETE RESTRICT).
        // Swallowing that made the redirect look identical to a success, leaving the operator with
        // a row that never disappears and no explanation. Surface it like handleAdminUserSave does.
        header('Location: /' . $adminPath . '/users?error=' . urlencode($e->getMessage()));
        exit;
    }
    header('Location: /' . $adminPath . '/users?deleted=1');
    exit;
}

function handleAdminUser2fa(string $adminPath): void
{
    if (Auth::id() !== 0) { http_response_code(403); exit('403 Forbidden'); }
    $msg = AdminUserController::manageStaffTwoFactor((int)($_POST['user_id'] ?? 0), $_POST['action'] ?? '');
    header('Location: /' . $adminPath . '/users?msg=' . urlencode($msg));
    exit;
}

function handleAdminProfile(array $common): void
{
    $userId = Auth::id();
    $user   = $userId === 0
        ? ['id' => 0, 'name' => Env::get('SUPER_USER_NAME', 'Super User'), 'email' => Env::get('SUPER_USER_EMAIL', ''), 'role' => 'super_user', 'locale' => Env::get('SUPER_USER_LOCALE', 'en'), 'biography' => Env::get('SUPER_USER_BIO', ''), 'avatar_filename' => Env::get('SUPER_USER_AVATAR', ''), 'sns_links' => json_encode(['x' => Env::get('SUPER_USER_X', ''), 'github' => Env::get('SUPER_USER_GITHUB', '')])]
        : DB::fetchOne('SELECT id, name, email, role, locale, biography, sns_links, avatar_filename, two_factor_secret FROM users WHERE id=? LIMIT 1', [$userId]);

    $newSecret       = TwoFactorAuth::generateSecret();
    $availableLocales = HbpLocale::getAvailableLocales();
    View::render('admin/profile', array_merge($common, compact('user', 'newSecret', 'availableLocales')));
}

function handleAdminProfileSave(string $adminPath): void
{
    $userId = Auth::id();
    if ($userId === 0) {
        header('Location: /' . $adminPath . '/profile?info=superuser');
        exit;
    }
    try {
        AdminUserController::update($userId, $_POST);
        $_SESSION['user_name'] = trim($_POST['name'] ?? $_SESSION['user_name']);
        header('Location: /' . $adminPath . '/profile?saved=1');
    } catch (Exception $e) {
        header('Location: /' . $adminPath . '/profile?error=' . urlencode($e->getMessage()));
    }
    exit;
}

function handleAdmin2faSetup(string $adminPath): void
{
    $userId = Auth::id();
    if ($userId === 0) { header('Location: /' . $adminPath . '/profile'); exit; }
    try {
        AdminUserController::enableTwoFactor($userId, $_POST['secret'] ?? '', $_POST['code'] ?? '');
        header('Location: /' . $adminPath . '/profile?2fa=enabled');
    } catch (Exception $e) {
        header('Location: /' . $adminPath . '/profile?error=' . urlencode($e->getMessage()));
    }
    exit;
}

function handleAdmin2faDisable(string $adminPath): void
{
    $userId = Auth::id();
    if ($userId === 0) { header('Location: /' . $adminPath . '/profile'); exit; }
    AdminUserController::disableTwoFactor($userId);
    header('Location: /' . $adminPath . '/profile?2fa=disabled');
    exit;
}

function handleAdminPlugins(array $common): void
{
    Gate::authorize('plugin:manage');
    $plugins = PluginManager::scan();
    View::render('admin/plugins', array_merge($common, compact('plugins')));
}

function handleAdminPluginToggle(string $adminPath): void
{
    Gate::authorize('plugin:manage');
    $name   = basename($_POST['name'] ?? '');
    $action = $_POST['action'] ?? '';
    if ($action === 'activate')   PluginManager::activate($name);
    if ($action === 'deactivate') PluginManager::deactivate($name);
    header('Location: /' . $adminPath . '/plugins');
    exit;
}

function handleAdminPluginInstall(string $adminPath): void
{
    Gate::authorize('plugin:manage');

    // fully disable web-based installation when ALLOW_PLUGIN_INSTALL=false
    if (!Env::bool('ALLOW_PLUGIN_INSTALL', false)) {
        header('Location: /' . $adminPath . '/plugins?error=disabled');
        exit;
    }

    if (!isset($_FILES['zip']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
        header('Location: /' . $adminPath . '/plugins?error=upload');
        exit;
    }

    // PluginInstaller performs zip-slip countermeasures, Ed25519 signature verification, and auditing
    require_once CORE_PATH . '/PluginInstaller.php';
    try {
        $pluginId = PluginInstaller::install($_FILES['zip']['tmp_name']);
        // Rebuild the registry after installation
        PluginManager::rebuildCache();
        RouterCompiler::compile();
        header('Location: /' . $adminPath . '/plugins?installed=1');
    } catch (RuntimeException $e) {
        header('Location: /' . $adminPath . '/plugins?error=' . urlencode($e->getMessage()));
    }
    exit;
}

function handleAdminPluginRebuild(string $adminPath): void
{
    Gate::authorize('plugin:manage');
    PluginManager::rebuildCache();
    RouterCompiler::compile();
    header('Location: /' . $adminPath . '/plugins?rebuilt=1');
    exit;
}

function handleAdminPluginDelete(string $adminPath): void
{
    Gate::authorize('plugin:manage');
    $name = basename($_POST['name'] ?? '');
    if ($name !== '') {
        PluginManager::delete($name);
        RouterCompiler::compile();
    }
    header('Location: /' . $adminPath . '/plugins?deleted=1');
    exit;
}

// ----------------------------------------------------------------
// Front theme management
// ----------------------------------------------------------------

function handleAdminThemes(array $common): void
{
    Gate::authorize('theme:manage');
    $themes = ThemeManager::scan();
    View::render('admin/themes', array_merge($common, compact('themes')));
}

function handleAdminThemeActivate(string $adminPath): void
{
    Gate::authorize('theme:manage');
    $name = basename($_POST['name'] ?? '');
    try {
        ThemeManager::activate($name);
        header('Location: /' . $adminPath . '/themes?activated=1');
    } catch (RuntimeException $e) {
        header('Location: /' . $adminPath . '/themes?error=' . urlencode($e->getMessage()));
    }
    exit;
}

function handleAdminThemeInstall(string $adminPath): void
{
    Gate::authorize('theme:manage');

    // Fully disable web-based theme installation when ALLOW_THEME_INSTALL=false.
    if (!Env::bool('ALLOW_THEME_INSTALL', false)) {
        header('Location: /' . $adminPath . '/themes?error=disabled');
        exit;
    }
    if (!isset($_FILES['zip']) || $_FILES['zip']['error'] !== UPLOAD_ERR_OK) {
        header('Location: /' . $adminPath . '/themes?error=upload');
        exit;
    }

    // ThemeInstaller performs zip-slip countermeasures, validation, and auditing.
    require_once CORE_PATH . '/ThemeInstaller.php';
    try {
        ThemeInstaller::install($_FILES['zip']['tmp_name']);
        header('Location: /' . $adminPath . '/themes?installed=1');
    } catch (RuntimeException $e) {
        header('Location: /' . $adminPath . '/themes?error=' . urlencode($e->getMessage()));
    }
    exit;
}

function handleAdminThemeDelete(string $adminPath): void
{
    Gate::authorize('theme:manage');
    $name = basename($_POST['name'] ?? '');
    try {
        ThemeManager::delete($name);
        header('Location: /' . $adminPath . '/themes?deleted=1');
    } catch (RuntimeException $e) {
        header('Location: /' . $adminPath . '/themes?error=' . urlencode($e->getMessage()));
    }
    exit;
}

function handleAdminThemeRebuild(string $adminPath): void
{
    Gate::authorize('theme:manage');
    // Re-publish the active theme's assets + purge caches (for direct on-server theme edits).
    ThemeManager::rebuild();
    header('Location: /' . $adminPath . '/themes?rebuilt=1');
    exit;
}

// ----------------------------------------------------------------
// NG-keyword management
// ----------------------------------------------------------------

/**
 * The NG-keyword screens are backed by the `badwords` table, which only exists once
 * hbp-badwords has been activated (its database/install.{driver}.sql creates it).
 * Without this guard a fresh install would 500 on a plain SELECT, so treat the whole
 * area as absent while the plugin is inactive.
 */
function badwordsPluginActive(): bool
{
    return PluginManager::isActive('hbp-badwords');
}

function handleAdminBadwords(array $common): void
{
    Gate::authorize('plugin:manage');
    if (!badwordsPluginActive()) {
        handleAdmin404($common);
        return;
    }
    $words = DB::fetchAll('SELECT id, word, created_at FROM badwords ORDER BY id DESC');
    View::render('admin/badwords', array_merge($common, compact('words')));
}

function handleAdminBadwordStore(string $adminPath): void
{
    Gate::authorize('plugin:manage');
    if (!badwordsPluginActive()) {
        http_response_code(404);
        exit;
    }
    $word = mb_strtolower(trim($_POST['word'] ?? ''));
    if ($word === '') {
        header('Location: /' . $adminPath . '/badwords');
        exit;
    }
    try {
        DB::execute(
            "INSERT INTO badwords (word, created_at) VALUES (?, NOW())",
            [$word]
        );
        require_once ROOT_PATH . '/plugins/hbp-badwords/plugin.php';
        HbpBadwordsPlugin::rebuildCache();
        header('Location: /' . $adminPath . '/badwords?success=created');
    } catch (Throwable) {
        header('Location: /' . $adminPath . '/badwords?error=duplicate');
    }
    exit;
}

function handleAdminBadwordDelete(string $adminPath): void
{
    Gate::authorize('plugin:manage');
    if (!badwordsPluginActive()) {
        http_response_code(404);
        exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        DB::execute('DELETE FROM badwords WHERE id=?', [$id]);
        require_once ROOT_PATH . '/plugins/hbp-badwords/plugin.php';
        HbpBadwordsPlugin::rebuildCache();
    }
    header('Location: /' . $adminPath . '/badwords?success=deleted');
    exit;
}

function handleAdminBadwordRebuild(string $adminPath): void
{
    Gate::authorize('plugin:manage');
    if (!badwordsPluginActive()) {
        http_response_code(404);
        exit;
    }
    require_once ROOT_PATH . '/plugins/hbp-badwords/plugin.php';
    HbpBadwordsPlugin::rebuildCache();
    header('Location: /' . $adminPath . '/badwords?success=rebuilt');
    exit;
}

function handleAdminSeo(array $common): void
{
    Gate::authorize('site:setting');
    $seoConfig = SeoCompiler::loadGlobal();
    $robots    = file_exists(PUBLIC_PATH . '/robots.txt') ? file_get_contents(PUBLIC_PATH . '/robots.txt') : SiteFileCompiler::defaultRobots();
    View::render('admin/seo', array_merge($common, compact('seoConfig', 'robots')));
}

function handleAdminSeoSave(string $adminPath): void
{
    Gate::authorize('site:setting');
    $config = [
        'default_description'  => trim($_POST['default_description'] ?? ''),
        'default_keywords'     => trim($_POST['default_keywords']    ?? ''),
        'ga4_id'               => trim($_POST['ga4_id']              ?? ''),
        'search_console_token' => trim($_POST['search_console_token'] ?? ''),
        'title_format'         => mb_substr(trim((string)($_POST['title_format'] ?? '')), 0, 100),
        'slug_mode'            => in_array($_POST['slug_mode'] ?? '', ['title', 'datetime', 'id'], true) ? $_POST['slug_mode'] : 'title',
        'publisher_logo'       => mb_substr(trim((string)($_POST['publisher_logo'] ?? '')), 0, 255),
        'publish_slots'        => (function () { require_once CORE_PATH . '/PublishSlots.php'; return implode(', ', PublishSlots::parse((string)($_POST['publish_slots'] ?? ''))); })(),
    ];
    SeoCompiler::compile($config);
    SiteFileCompiler::writeRobots($_POST['robots'] ?? SiteFileCompiler::defaultRobots());
    SiteFileCompiler::compileSitemap(true);
    CacheManager::purgeAll();

    // SEO settings changes (GA4 scripts, etc.) can affect the CSP header, so recompile
    SecurityHeaderCompiler::compile();

    header('Location: /' . $adminPath . '/seo?saved=1');
    exit;
}

function handleAdminMaintenance(array $common): void
{
    Gate::authorize('maintenance:run');
    $common['currentPage'] = 'maintenance';
    View::render('admin/maintenance', $common);
}

/**
 * For environments without SSH, run each compiler from the browser.
 * Lets you call the equivalent of the CLI system:init / feed:rebuild via a button.
 */
function handleAdminMaintenanceRun(string $adminPath): void
{
    Gate::authorize('maintenance:run');

    // Reliably load the core classes used by system:init (require_once is safe for double loading)
    require_once CORE_PATH . '/FeedCompiler.php';
    require_once CORE_PATH . '/Scheduler.php';
    require_once CORE_PATH . '/SiteDenylistCompiler.php';

    $task = $_POST['task'] ?? '';

    switch ($task) {
        case 'sitemap':
            SiteFileCompiler::compileSitemap(true);
            break;

        case 'feed':
            FeedCompiler::rebuildAll();
            break;

        case 'plugins':
            hbpInstallBundledPluginSchemas();   // Create bundled plugin tables (badwords, etc.; idempotent)
            PluginManager::rebuildCache();
            RouterCompiler::compile();
            break;

        case 'theme':
            // Re-publish the active theme's assets + purge FPC / front view cache / CSP hashes.
            // Use after editing the active theme's templates or assets directly on the server.
            ThemeManager::rebuild();
            break;

        case 'all':
            // Bulk regeneration equivalent to CLI system:init
            RouterCompiler::compile();
            MenuCompiler::compile();
            CategoryCompiler::compile();
            TagCompiler::compile();

            $contentLocales = HbpLocale::getAvailableContentLocales();
            if (empty($contentLocales)) {
                $contentLocales = [Env::get('SITE_DEFAULT_LANG', 'en') => 'default'];
            }
            foreach (array_keys($contentLocales) as $lang) {
                SidebarCompiler::compileRecentCommentsWidget($lang);
                SidebarCompiler::compileArchiveWidget($lang);
                SidebarCompiler::compileAllLayouts($lang);
            }
            SidebarCompiler::compileHtmlSources();
            require_once CORE_PATH . '/BlockCompiler.php';
            require_once CORE_PATH . '/LinkCompiler.php';
            BlockCompiler::compile();
            LinkCompiler::compile();
            require_once CORE_PATH . '/RedirectCompiler.php';
            RedirectCompiler::compile();
            require_once CORE_PATH . '/SpeakerCompiler.php';
            SpeakerCompiler::compile();

            SeoCompiler::compile();
            SiteFileCompiler::compileSitemap(true);
            FeedCompiler::rebuildAll();
            hbpInstallBundledPluginSchemas();   // Create bundled plugin tables (badwords, etc.; idempotent)
            PluginManager::rebuildCache();
            AuthorCompiler::rebuild();
            SiteDenylistCompiler::compile();
            SecurityHeaderCompiler::compile();
            Scheduler::recompileNext();
            ThemeManager::publishAll();
            ViewCompiler::clearAll();
            CacheManager::purgeAll();
            break;

        default:
            header('Location: /' . $adminPath . '/maintenance');
            exit;
    }

    header('Location: /' . $adminPath . '/maintenance?done=' . rawurlencode($task));
    exit;
}

/**
 * Run the bundled plugins' database/install.{driver}.sql (idempotent).
 * An alternative for creating plugin tables like badwords in environments without the CLI.
 * Each SQL assumes CREATE TABLE IF NOT EXISTS, so it is safe to re-run.
 */
function hbpInstallBundledPluginSchemas(): void
{
    $driver     = Env::get('DB_DRIVER', 'mysql');
    $pluginsDir = ROOT_PATH . '/plugins';
    if (!is_dir($pluginsDir)) {
        return;
    }
    $pdo = DB::getInstance();
    foreach (scandir($pluginsDir) as $pluginId) {
        if ($pluginId === '.' || $pluginId === '..') {
            continue;
        }
        $sqlFile = $pluginsDir . '/' . $pluginId . '/database/install.' . $driver . '.sql';
        if (!is_file($sqlFile)) {
            continue;
        }
        $sql = (string)file_get_contents($sqlFile);
        foreach (array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '' && !str_starts_with($s, '--')) as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (PDOException) {
                // Ignore existing tables, etc. (idempotent)
            }
        }
    }
}

function handleAdminCacheClear(string $adminPath): void
{
    Gate::authorize('maintenance:run');
    // Clear all FPC
    CacheManager::purgeAll();
    // Clear all view cache (to re-bake MUI)
    ViewCompiler::clearAll();
    // Recompile the CSP header
    SecurityHeaderCompiler::compile();
    // Regenerate the sitemap (to reflect the latest state after the full clear)
    SiteFileCompiler::compileSitemap(true);
    header('Location: /' . $adminPath . '?cache_cleared=1');
    exit;
}

function handleSecureAsset(string $subPath): void
{
    // strict admin-session check (403 if unauthenticated)
    if (!Auth::check()) {
        http_response_code(403);
        exit('403 Forbidden');
    }

    // defend against path-traversal attacks with basename()
    $filename = basename(substr($subPath, strlen('/assets/')));
    if ($filename === '') { http_response_code(404); exit; }

    // inside storage/upload/ is shard placement ({ab}/{cd}/). Legacy flat placement is a fallback.
    $file = Media::resolveUploadPath($filename);
    if ($file === null) { http_response_code(404); exit; }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file);

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string)filesize($file));
    header('X-Content-Type-Options: nosniff');

    // force download for non-images (served with the original filename)
    if (!str_starts_with($mimeType, 'image/')) {
        $db = DB::fetchOne('SELECT original_name FROM attachments WHERE filename=? LIMIT 1', [$filename]);
        $displayName = $db ? $db['original_name'] : $filename;
        $encoded = rawurlencode($displayName);
        header("Content-Disposition: attachment; filename*=UTF-8''{$encoded}");
    }

    readfile($file);
}

function handleAdmin404(array $common): void
{
    http_response_code(404);
    View::render('admin/404', $common);
}

function handleAdminLogout(string $adminPath): void
{
    Auth::logout();
    header('Location: /' . $adminPath . '/login');
    exit;
}

