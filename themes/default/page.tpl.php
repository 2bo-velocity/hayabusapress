<?php /* SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE */ ?>
{% include 'header' %}

<article class="hbp-single-post">
    <header class="hbp-article-header">
        <h1 class="hbp-article-title">{{ $post['title'] }}</h1>
    </header>

    <!-- show the featured image as a header image -->
    {% if $post['featured_image'] %}
    <figure class="hbp-featured-image">
        <img src="{{ $post['featured_image'] }}" alt="{{ $post['featured_image_alt'] }}" loading="lazy">
    </figure>
    {% endif %}

    {{{ SidebarCompiler::areaFor('content_before', $lang ?? '', $layout ?? null) }}}

    <div class="hbp-content">
        {{{ $post['content_html'] }}}
    </div>

    {{{ SidebarCompiler::areaFor('content_after', $lang ?? '', $layout ?? null) }}}

    {% if $post['show_share'] %}
    <?php
    $langPrefix = ($lang === $defaultLang) ? '' : "/{$lang}";
    $shareUrl   = htmlspecialchars(rtrim($_ENV['SITE_URL'] ?? '', '/') . $langPrefix . '/page/' . $post['slug'], ENT_QUOTES, 'UTF-8');
    $shareTitle = htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8');
    ?>
    <div class="hbp-share">
        <p class="hbp-share-label">{@ share_label @}</p>
        <div class="hbp-share-links">
            <a href="https://x.com/intent/tweet?url={{ $shareUrl }}&text={{ $shareTitle }}" target="_blank" rel="noopener noreferrer" class="hbp-share-x">X</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u={{ $shareUrl }}" target="_blank" rel="noopener noreferrer" class="hbp-share-fb">Facebook</a>
        </div>
    </div>
    {% endif %}

    {% if $post['show_author'] && count($post['author_profile']) > 0 %}
    <div class="hbp-author-profile-box">
        <div class="hbp-author-avatar-area">
            {% if $post['author_profile']['avatar'] %}
            <img src="/assets/site/avatars/{{ $post['author_profile']['avatar'] }}" alt="{{ $post['author_profile']['name'] }}" class="hbp-avatar-img" loading="lazy">
            {% endif %}
        </div>
        <div class="hbp-author-details-area">
            <span class="hbp-author-label">{@ writer @}</span>
            <h4 class="hbp-author-name">{{ $post['author_profile']['name'] }}</h4>
            {% if $post['author_profile']['bio'] %}
            <p class="hbp-author-bio">{{ $post['author_profile']['bio'] }}</p>
            {% endif %}
        </div>
    </div>
    {% endif %}
</article>

{% include 'footer' %}
