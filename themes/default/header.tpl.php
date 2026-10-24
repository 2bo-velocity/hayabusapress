<!-- SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE -->
<!DOCTYPE html>
<html lang="{{ $lang ?? 'en' }}">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
{{{ $seoMeta ?? '<title>' . htmlspecialchars($siteName ?? 'HBP', ENT_QUOTES, 'UTF-8') . '</title>' }}}
{{{ $seoHead ?? '' }}}
<?php
// Self-referencing canonical. Paginated listings point at themselves, never back at page 1 —
// canonicalizing page 2+ to page 1 is what drops everything past page 1 out of the index.
// Empty on search result pages (noindex, unbounded URL space) and on 404.
if (!empty($canonical)): ?>
<link rel="canonical" href="{{ $canonical }}">
<?php endif; ?>
<link rel="stylesheet" href="{{ ThemeManager::assetBase() }}/style.css">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/site/favicon-32x32.png">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/site/favicon-16x16.png">
<link rel="icon" type="image/png" sizes="96x96" href="/assets/site/favicon-96x96.png">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/site/apple-touch-icon-180x180.png">
<?php
// Atom/RSS autodiscovery (only when FEED_ENABLED=true)
$feedBase    = ($lang === $defaultLang) ? '' : "/{$lang}";
$feedEnabled = class_exists('Env') ? Env::bool('FEED_ENABLED', true) : true;
if ($feedEnabled):
    $feedSiteUrl = htmlspecialchars(rtrim($siteUrl ?? '', '/'), ENT_QUOTES, 'UTF-8');
    $feedBase_e  = htmlspecialchars($feedBase, ENT_QUOTES, 'UTF-8');
    $feedTitle_e = htmlspecialchars($siteName ?? '', ENT_QUOTES, 'UTF-8');
?>
<?php require_once CORE_PATH . '/FeedCompiler.php'; foreach (FeedCompiler::formats() as $feedFmt): $feedLabel = ['atom' => 'Atom', 'rss' => 'RSS', 'json' => 'JSON Feed'][$feedFmt]; ?>
<link rel="alternate" type="<?= FeedCompiler::FORMATS[$feedFmt][1] ?>" title="<?= $feedTitle_e ?> (<?= $feedLabel ?>)" href="<?= $feedSiteUrl ?><?= $feedBase_e ?>/feed.<?= FeedCompiler::FORMATS[$feedFmt][0] ?>">
<?php endforeach; ?>
<?php endif; ?>
<?php
// hreflang output (only when there is a translation group; from posts.hreflang_cache)
if (!empty($post['hreflang'])) {
    foreach ($post['hreflang'] as $code => $relUrl) {
        $absUrl = htmlspecialchars(rtrim($siteUrl ?? '', '/') . $relUrl, ENT_QUOTES, 'UTF-8');
        echo "<link rel=\"alternate\" hreflang=\"{$code}\" href=\"{$absUrl}\">\n";
    }
}
?>
<?php echo PluginManager::snippet('head'); ?>
</head>
<?php
// $layout comes from a post/page (posts.layout); listings and plugin pages use the theme default.
$layout = $layout ?? ThemeManager::layout(null);
?>
<body<?php if ($layout['body_class'] !== ''): ?> class="{{ $layout['body_class'] }}"<?php endif; ?>>

<header class="hbp-header">
    <div class="hbp-container">
        <a href="/" class="hbp-site-title">{{ $siteName }}</a>
        <nav class="hbp-header-nav">
            {{{ $menus['pc_header'] ?? '' }}}
        </nav>
        <?php
        // content language switcher (shown only for posts that have a translation group)
        // Show the language switch links when the translation post's hreflang exists
        if (!empty($post['hreflang']) && count($post['hreflang']) > 1) {
            echo '<div class="hbp-lang-switcher">';
            foreach ($post['hreflang'] as $code => $relUrl) {
                if ($code === 'x-default') continue;
                $absUrl = htmlspecialchars(rtrim($siteUrl ?? '', '/') . $relUrl, ENT_QUOTES, 'UTF-8');
                $active = $code === $lang ? ' class="hbp-lang-active"' : '';
                echo "<a href=\"{$absUrl}\"{$active}>" . strtoupper($code) . '</a>';
            }
            echo '</div>';
        }
        ?>
        <button type="button" class="hbp-hamburger" id="hamburgerBtn" aria-label="{@ menu_aria @}" aria-controls="spMenu" aria-expanded="false">
            <span></span><span></span><span></span>
        </button>
    </div>
</header>

<!-- Mobile menu -->
<div id="spMenu" class="hbp-sp-menu" hidden>
    {{{ $menus['sp_header'] ?? '' }}}
</div>

{{{ SidebarCompiler::areaFor('header_below', $lang ?? '', $layout) }}}

<div class="hbp-container hbp-layout">
    <main class="hbp-main">
