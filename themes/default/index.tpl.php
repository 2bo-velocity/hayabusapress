<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
$seoMeta  = '<title>' . htmlspecialchars($siteName ?? 'HBP', ENT_QUOTES, 'UTF-8') . '</title>';
$langPrefix = ($lang === $defaultLang) ? '' : "/{$lang}";
?>
{% include 'header' %}

<div class="hbp-posts-list">
    {% foreach $posts as $post %}
    <article class="hbp-post-card">
        {% if $post['featured_image'] %}
        <a href="{{ $langPrefix }}/post/{{ $post['slug'] }}" class="hbp-card-thumb">
            <img src="{{ $post['featured_image'] }}" alt="{{ $post['featured_image_alt'] }}" loading="lazy">
        </a>
        {% endif %}
        <h2 class="hbp-post-title">
            <a href="{{ $langPrefix }}/post/{{ $post['slug'] }}">{{ $post['title'] }}</a>
        </h2>
        <div class="hbp-post-meta">
            <time>{{ Format::date($post['published_at']) }}</time>
            {% if $post['author']['name'] %}<span>{{ $post['author']['name'] }}</span>{% endif %}
            {% if $post['comment_count'] > 0 %}
            <span><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-2px"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg> {{ $post['comment_count'] }}</span>
            {% endif %}
        </div>
        <div class="hbp-post-excerpt">
            <?php
            $excerpt = strip_tags($post['content_html'] ?? '');
            echo htmlspecialchars(mb_substr($excerpt, 0, 200), ENT_QUOTES, 'UTF-8');
            if (mb_strlen($excerpt) > 200) echo '…';
            ?>
        </div>
        <a href="{{ $langPrefix }}/post/{{ $post['slug'] }}" class="hbp-read-more">{@ read_more @} →</a>
    </article>
    {% endforeach %}

    {% if count($posts) === 0 %}
    <p class="hbp-empty">{@ no_posts @}</p>
    {% endif %}
</div>

<!-- Pagination -->
{% include 'pagination' %}

{% include 'footer' %}
