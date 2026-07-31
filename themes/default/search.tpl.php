<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
// Public search is rendered server-side (core/PostSearch.php).
// Without ?q= this page is the bare form and is baked into the FPC; with a query it is generated
// per request and never cached, because the query space is unbounded.
$searched   = $searched ?? false;
$query      = (string)($query ?? '');
$heading    = $searched
    ? sprintf($__['search_results_heading'] ?? 'Search results for “%s”', $query)
    : ($__['search_button'] ?? 'Search');
$seoMeta    = '<title>' . htmlspecialchars($heading . ' — ' . ($siteName ?? 'HBP'), ENT_QUOTES, 'UTF-8') . '</title>';
$langPrefix = ($lang === $defaultLang) ? '' : "/{$lang}";
$formAction = $langPrefix . '/search';
$pageBase   = $formAction . '?q=' . rawurlencode($query);
?>
{% include 'header' %}

<h1 class="hbp-archive-title"><?php echo htmlspecialchars($heading, ENT_QUOTES, 'UTF-8'); ?></h1>

<div class="hbp-search-box">
    <form method="get" action="<?php echo htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8'); ?>" role="search">
        <input type="search" name="q" id="hbp-search-input" class="hbp-search-input"
               value="{{ $query }}" placeholder="{@ search_placeholder @}"
               autocomplete="off" aria-label="{@ search_button @}">
        <button type="submit">{@ search_button @}</button>
    </form>
</div>

{% if ($tooShort ?? false) %}
<p class="hbp-empty"><?php echo htmlspecialchars(sprintf($__['search_too_short'] ?? 'Please enter at least %d characters.', (int)($minChars ?? 2)), ENT_QUOTES, 'UTF-8'); ?></p>
{% endif %}

{% if $searched && !($tooShort ?? false) %}
<div class="hbp-posts-list">
    {% foreach $posts as $post %}
    <article class="hbp-post-card">
        {% if $post['featured_image'] %}
        <a href="{{ $langPrefix }}/{{ $post['type'] }}/{{ $post['slug'] }}" class="hbp-card-thumb">
            <img src="{{ $post['featured_image'] }}" alt="{{ $post['featured_image_alt'] }}" loading="lazy">
        </a>
        {% endif %}
        <h2 class="hbp-post-title"><a href="{{ $langPrefix }}/{{ $post['type'] }}/{{ $post['slug'] }}">{{ $post['title'] }}</a></h2>
        <div class="hbp-post-meta"><time>{{ Format::date($post['published_at']) }}</time></div>
    </article>
    {% endforeach %}
    {% if count($posts) === 0 %}<p class="hbp-empty"><?php echo htmlspecialchars(sprintf($__['search_no_results'] ?? 'No articles matched “%s”.', $query), ENT_QUOTES, 'UTF-8'); ?></p>{% endif %}
</div>

{% if ($page > 1) || ($hasNext ?? false) %}
<nav class="hbp-pagination" aria-label="<?php echo htmlspecialchars($__['pagination_aria'] ?? 'Pagination', ENT_QUOTES, 'UTF-8'); ?>">
    {% if $page > 1 %}<a href="{{ $pageBase }}&amp;page={{ $page - 1 }}" class="hbp-page-link">{@ prev_page @}</a>{% endif %}
    <span class="hbp-page-info"><?php echo htmlspecialchars(sprintf($__['page_current'] ?? 'Page %d', (int)$page), ENT_QUOTES, 'UTF-8'); ?></span>
    {% if ($hasNext ?? false) %}<a href="{{ $pageBase }}&amp;page={{ $page + 1 }}" class="hbp-page-link">{@ next_page @}</a>{% endif %}
</nav>
{% endif %}
{% endif %}

{% include 'footer' %}
