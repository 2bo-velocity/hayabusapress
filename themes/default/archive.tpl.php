<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
$seoMeta    = '<title>' . htmlspecialchars(Format::yearMonth($month) . ' ' . ($__['archive_title'] ?? 'Archive') . ' — ' . $siteName, ENT_QUOTES, 'UTF-8') . '</title>';
$langPrefix = ($lang === $defaultLang) ? '' : "/{$lang}";
?>
{% include 'header' %}

<h1 class="hbp-archive-title">{{ Format::yearMonth($month) }} {@ archive_title @}</h1>

<div class="hbp-posts-list">
    {% foreach $posts as $post %}
    <article class="hbp-post-card">
        {% if $post['featured_image'] %}
        <a href="{{ $langPrefix }}/post/{{ $post['slug'] }}" class="hbp-card-thumb">
            <img src="{{ $post['featured_image'] }}" alt="{{ $post['featured_image_alt'] }}" loading="lazy">
        </a>
        {% endif %}
        <h2 class="hbp-post-title"><a href="{{ $langPrefix }}/post/{{ $post['slug'] }}">{{ $post['title'] }}</a></h2>
        <div class="hbp-post-meta"><time>{{ Format::date($post['published_at']) }}</time></div>
    </article>
    {% endforeach %}
    {% if count($posts) === 0 %}<p class="hbp-empty">{@ no_posts @}</p>{% endif %}
</div>

{% include 'pagination' %}

{% include 'footer' %}
