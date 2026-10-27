<?php
/**
 * HayabusaPress - Comment intake controller.
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

/**
 * Comment intake controller
 *
 * Spec Phase 4: serial static-hook execution -> honeypot -> validation -> double lock -> DB save
 * Included from public/comment_post.php.
 */
class CommentController
{
    public static function store(array $post, string $ip): bool
    {
        // Loaded here, not guarded by class_exists(): the public front controller does not load it,
        // and the guard used to skip the blocklist (and everything below that relies on it) silently.
        require_once CORE_PATH . '/IpBlocklist.php';

        // ---- Stage 0: site-specific IP blocklist (front line of write POSTs) ----
        // Fail open on DB error (lets the post through)
        if (class_exists('IpBlocklist') && IpBlocklist::isBlocked($ip)) {
            $onHit = class_exists('Env') ? Env::get('BLOCKLIST_ON_HIT', 'reject') : ($_ENV['BLOCKLIST_ON_HIT'] ?? 'reject');
            if ($onHit === 'reject') {
                http_response_code(403);
                exit;
            }
            // moderate: to the pending queue (just save via the normal flow)
        }

        // ---- Stage 0.5: per-IP submission rate (COMMENT_RATE_LIMIT, "count/seconds") ----
        if (class_exists('IpBlocklist') && IpBlocklist::rateLimited('comment', $ip, 'COMMENT_RATE_LIMIT', '5/600')) {
            http_response_code(429);
            header('Retry-After: 600');
            exit;
        }

        // ---- Stage 1: serial static-hook execution (before_comment_create) ----
        // An anti-spam plugin etc. may exit(403) here
        $context = ['post_data' => &$post, 'ip_address' => $ip];
        $hooks   = PluginManager::getHooks('before_comment_create');
        foreach ($hooks as $hook) {
            // hooks.cache.php is already absolute-pathed at compile time.
            // Even if the cache is stale after a plugin is removed, do not let a fatal error take down all comment posting;
            // verify the existence of the file, class, and method before executing.
            if (empty($hook['file']) || !is_file($hook['file'])) {
                continue;
            }
            require_once $hook['file'];
            if (!method_exists($hook['class'], $hook['method'])) {
                continue;
            }
            // A listener that throws must not turn every comment into a 500. It is skipped and the
            // rest still run; the honeypot, validation and moderation below still apply.
            // (An interceptor blocks with exit, which is not a Throwable and is not caught here.)
            try {
                call_user_func_array([$hook['class'], $hook['method']], [&$context]);
            } catch (Throwable $e) {
                error_log(sprintf(
                    '[HBP WARNING] before_comment_create listener %s::%s failed: %s',
                    $hook['class'],
                    $hook['method'],
                    $e->getMessage()
                ));
                if (class_exists('PluginErrors')) {
                    PluginErrors::record(PluginErrors::pluginOfFile($hook['file']) ?? '?', 'before_comment_create', $e->getMessage());
                }
            }
        }

        // ---- Stage 2: honeypot (last line of defense even when the plugin is disabled) ----
        if (!empty($post['honey_pot_email'])) {
            if (class_exists('IpBlocklist')) {
                IpBlocklist::autoBlock($ip, 'honeypot');   // counts toward BLOCKLIST_AUTO_THRESHOLD
            }
            return true; // Pretend a normal completion to the bot
        }

        // ---- Stage 3: input validation ----
        // Fields arrive as arrays when a bot posts name[]=...; trim() would throw a TypeError (a 500)
        $str = static fn($v): string => is_string($v) ? $v : '';
        $postId    = is_numeric($post['post_id'] ?? null) ? (int)$post['post_id'] : 0;
        $name      = trim($str($post['author_name']  ?? ''));
        $email     = trim($str($post['author_email'] ?? ''));
        $authorUrl = trim($str($post['author_url']   ?? ''));
        $content   = trim($str($post['content']      ?? ''));

        // email is optional. Only name + body are required.
        if ($postId <= 0 || $name === '' || $content === '') {
            return false;
        }

        // Body length limit (prevent bloat / DoS). Reject when exceeded.
        if (mb_strlen($content) > 10000) {
            return false;
        }

        // Format check only when provided (optional, so empty is allowed)
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if ($authorUrl !== '' && !filter_var($authorUrl, FILTER_VALIDATE_URL)) {
            $authorUrl = '';
        }

        // Truncate to each column's schema limit. These MUST match schema.{mysql,pgsql}.sql:
        //   author_name VARCHAR(100) / author_email VARCHAR(255) / author_url VARCHAR(255)
        // Prevents an overflow error on strict-mode MySQL / PostgreSQL (a 500 on the unauthenticated path).
        $name      = mb_substr($name, 0, 100);
        $email     = mb_substr($email, 0, 255);
        $authorUrl = mb_substr($authorUrl, 0, 255);

        // ip_address is VARCHAR(45) NOT NULL (max length of an IPv6 + zone/IPv4-mapped form).
        // clientIp() normally returns a validated address, but REMOTE_ADDR is passed through as-is,
        // so clamp it here too rather than letting a malformed value abort the INSERT.
        $ip = substr($ip, 0, 45);

        // ---- Stage 4: double lock (type='post' AND allow_comment=1 AND status='published') ----
        $target = DB::fetchOne(
            "SELECT id FROM posts WHERE id = ? AND type = 'post' AND allow_comment = TRUE AND status = 'published' LIMIT 1",
            [$postId]
        );
        if (!$target) {
            return false;
        }

        // ---- Stage 5: DB save ----
        $content = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');

        DB::execute(
            "INSERT INTO comments (post_id, author_name, author_email, author_url, content, status, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW())",
            [$postId, $name, $email ?: null, $authorUrl ?: null, $content, $ip]
        );

        return true;
    }
}
