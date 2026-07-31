<?php
/**
 * HayabusaPress - Public comment submission endpoint.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

require_once CORE_PATH . '/controllers/CommentController.php';

///B-series: accurate IP retrieval considering TRUSTED_PROXIES
$ip = class_exists('AccessControl') ? AccessControl::clientIp() : ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

if (!CommentController::store($_POST, $ip)) {
    http_response_code(400);
    // Show the error message from the content-language dictionary (default is the English fallback)
    $__cl = defined('CURRENT_LANG') ? CURRENT_LANG : '';
    $__d  = class_exists('HbpLocale') ? HbpLocale::loadFrontendDictionary($__cl) : [];
    exit($__d['invalid_request'] ?? 'Invalid request.');
}

// Using the Referer as-is for the redirect target would produce an external URL (open redirect) or
// a broken URL from double-appending '?' to an existing query. Adopt only the same-site path (+ existing query),
// and decide the joiner by whether an existing query is present.
$ref   = $_SERVER['HTTP_REFERER'] ?? '/';
$path  = parse_url($ref, PHP_URL_PATH) ?: '/';
$query = parse_url($ref, PHP_URL_QUERY);
$dest  = $path . ($query !== null && $query !== '' ? '?' . $query . '&' : '?') . 'commented=1';
header('Location: ' . $dest);
exit;
