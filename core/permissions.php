<?php
/**
 * HayabusaPress - Static RBAC matrix (17 permissions x 4 roles).
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Licensed under the GNU AGPL v3 or later, with the HayabusaPress Plugin & Theme Exception
 * for third-party plugins and themes. See LICENSE for the full text.
 */
declare(strict_types=1);

// static RBAC matrix — 17 permissions x 4 roles (Super User bypasses the matrix via .env only).
//   The ownership scope (own) is resolved by the caller checking posts.user_id / attachment ownership.
//   The matrix holds only per-role allow/deny; zero DB queries at check time.
//   Spec: matches the permission matrix in docs/hayabusapress/hayabusapress-spec-v1.0.md "Roles and permissions".

return [
    'administrator' => [
        'post:create'      => true,
        'post:publish'     => true,
        'post:edit_other'  => true,
        'post:delete'      => true,
        'post:raw_html'    => true,   // Pass raw HTML (embeds) through the sanitizer
        'comment:moderate' => true,
        'media:manage'     => true,
        'taxonomy:manage'  => true,   // Create/edit/delete categories & tags
        'nav:manage'       => true,   // Nav menus & sidebar widgets
        'blocks:manage'    => true,   // Reusable content blocks ({{ block:slug }})
        'links:manage'     => true,   // Managed outbound links (/go/{alias})
        'maintenance:run'  => true,   // Rebuild cache/sitemap/feeds
        'ip:manage'        => true,   // Admin allowlist & public blocklist
        'plugin:manage'    => true,
        'theme:manage'     => true,   // Front theme switch/upload/delete
        'user:manage'      => true,
        'site:setting'     => true,
    ],
    'editor' => [
        'post:create'      => true,
        'post:publish'     => true,
        'post:edit_other'  => true,
        'post:delete'      => true,
        'post:raw_html'    => false,
        'comment:moderate' => true,
        'media:manage'     => true,
        'taxonomy:manage'  => true,
        'nav:manage'       => false,
        'blocks:manage'    => false,
        'links:manage'     => false,
        'maintenance:run'  => false,
        'ip:manage'        => false,
        'plugin:manage'    => false,
        'theme:manage'     => false,
        'user:manage'      => false,
        'site:setting'     => false,
    ],
    'author' => [
        'post:create'      => true,
        'post:publish'     => true,   // (own) — own posts only (checked by the caller)
        'post:edit_other'  => false,
        'post:delete'      => true,   // (own)
        'post:raw_html'    => false,
        'comment:moderate' => false,
        'media:manage'     => true,   // (own)
        'taxonomy:manage'  => false,
        'nav:manage'       => false,
        'blocks:manage'    => false,
        'links:manage'     => false,
        'maintenance:run'  => false,
        'ip:manage'        => false,
        'plugin:manage'    => false,
        'theme:manage'     => false,
        'user:manage'      => false,
        'site:setting'     => false,
    ],
    'contributor' => [
        'post:create'      => true,
        'post:publish'     => false,  // Cannot publish (posts await Editor/Admin approval = stay as drafts)
        'post:edit_other'  => false,
        'post:delete'      => true,   // (own drafts) — own drafts only
        'post:raw_html'    => false,
        'comment:moderate' => false,
        'media:manage'     => false,
        'taxonomy:manage'  => false,
        'nav:manage'       => false,
        'blocks:manage'    => false,
        'links:manage'     => false,
        'maintenance:run'  => false,
        'ip:manage'        => false,
        'plugin:manage'    => false,
        'theme:manage'     => false,
        'user:manage'      => false,
        'site:setting'     => false,
    ],
];
