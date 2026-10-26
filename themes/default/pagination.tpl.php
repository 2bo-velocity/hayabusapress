<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
//
// Shared prev/next pagination for every listing (home / category / tag / archive).
//
// Path form (/page/2), not ?page=2. Two reasons:
//   - The router only understands /page/N; the query form silently returned page 1 forever.
//   - The FPC key comes from the path alone (a query string is ignored outside search), so a
//     ?page=2 link would just serve page 1 from cache. The path form has an FPC key of its own.
//
// Prev/next only, with no page count: a real total needs COUNT(*) over the whole listing, which is
// exactly the per-request full scan this engine exists to avoid. $hasNext already respects
// LIST_FPC_DEPTH, so the "next" link disappears at the last reachable page instead of pointing at
// a 404.
$pgBase = (string)($pageBase ?? '');
$pgNow  = max(1, (int)($page ?? 1));
$pgNext = (bool)($hasNext ?? false);

// Page 1 carries no suffix — the router 301s /page/1 to the bare URL, and sending readers through
// a redirect for the sake of a uniform template is a poor trade.
$pgPrevHref = $pgNow > 2
    ? $pgBase . '/page/' . ($pgNow - 1)
    : ($pgBase === '' ? '/' : $pgBase);
$pgNextHref = $pgBase . '/page/' . ($pgNow + 1);

if ($pgNow > 1 || $pgNext):
?>
<nav class="hbp-pagination" aria-label="<?php echo htmlspecialchars($__['pagination_aria'] ?? 'Pagination', ENT_QUOTES, 'UTF-8'); ?>">
    <?php if ($pgNow > 1): ?>
    <a href="<?php echo htmlspecialchars($pgPrevHref, ENT_QUOTES, 'UTF-8'); ?>" class="hbp-page-link">{@ prev_page @}</a>
    <?php endif; ?>
    <span class="hbp-page-info"><?php echo htmlspecialchars(sprintf($__['page_current'] ?? 'Page %d', $pgNow), ENT_QUOTES, 'UTF-8'); ?></span>
    <?php if ($pgNext): ?>
    <a href="<?php echo htmlspecialchars($pgNextHref, ENT_QUOTES, 'UTF-8'); ?>" class="hbp-page-link">{@ next_page @}</a>
    <?php endif; ?>
</nav>
<?php endif; ?>
