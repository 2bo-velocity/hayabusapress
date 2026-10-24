<?php /* SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE */ ?>
{% include 'header' %}

<article class="hbp-single-post" itemscope itemtype="https://schema.org/Article">
    <header class="hbp-article-header">
        <h1 class="hbp-article-title" itemprop="headline">{{ $post['title'] }}</h1>
        <div class="hbp-article-meta">
            <time itemprop="datePublished" datetime="{{ Format::iso($post['published_at']) }}">{{ Format::date($post['published_at']) }}</time>
            <meta itemprop="dateModified" content="{{ $post['updated_at'] }}">
            {% if $post['status'] === 'scheduled' %}<span class="hbp-scheduled-badge">{@ scheduled @}</span>{% endif %}
            {% if $post['author']['name'] %}<span itemprop="author">{{ $post['author']['name'] }}</span>{% endif %}
            {% if $post['comment_count'] > 0 %}
            <span><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" class="hbp-icon-inline"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/></svg> {{ $post['comment_count'] }}</span>
            {% endif %}
        </div>

        <!-- Tags -->
        {% if count($post['tags']) > 0 %}
        <div class="hbp-tags">
            <?php $langPrefix = ($lang === $defaultLang) ? '' : "/{$lang}"; ?>
            {% foreach $post['tags'] as $tag %}
            <a href="{{ $langPrefix }}/post/tag/{{ $tag['slug'] }}" class="hbp-tag">#{{ $tag['name'] }}</a>
            {% endforeach %}
        </div>
        {% endif %}
    </header>

    <!-- show the featured image as a header image (same image as og:image, resolved at save time) -->
    {% if $post['featured_image'] %}
    <figure class="hbp-featured-image">
        <img src="{{ $post['featured_image'] }}" alt="{{ $post['featured_image_alt'] }}" loading="lazy">
    </figure>
    {% endif %}

    <!-- Body (HTML compiled at save time; zero front-end Markdown conversion cost) -->
    {{{ SidebarCompiler::areaFor('content_before', $lang ?? '', $layout ?? null) }}}

    <div class="hbp-content" itemprop="articleBody">
        {{{ $post['content_html'] }}}
    </div>

    {{{ SidebarCompiler::areaFor('content_after', $lang ?? '', $layout ?? null) }}}

    <!-- Social share -->
    {% if $post['show_share'] %}
    <?php
    $langPrefix = ($lang === $defaultLang) ? '' : "/{$lang}";
    $shareUrl   = htmlspecialchars(rtrim($siteUrl ?? '', '/') . $langPrefix . '/post/' . $post['slug'], ENT_QUOTES, 'UTF-8');
    $shareTitle = htmlspecialchars($post['title'], ENT_QUOTES, 'UTF-8');
    ?>
    <div class="hbp-share">
        <p class="hbp-share-label">{@ share_label @}</p>
        <div class="hbp-share-links">
            <a href="https://x.com/intent/tweet?url={{ $shareUrl }}&text={{ $shareTitle }}"
               target="_blank" rel="noopener noreferrer" class="hbp-share-x">X (Twitter)</a>
            <a href="https://www.facebook.com/sharer/sharer.php?u={{ $shareUrl }}"
               target="_blank" rel="noopener noreferrer" class="hbp-share-fb">Facebook</a>
            <a href="https://line.me/R/msg/text/?{{ $shareTitle }}%0D%0A{{ $shareUrl }}"
               target="_blank" rel="noopener noreferrer" class="hbp-share-line">LINE</a>
            <a href="https://b.hatena.ne.jp/entry/{{ $shareUrl }}"
               target="_blank" rel="noopener noreferrer" class="hbp-share-hb">{@ share_hatena @}</a>
        </div>
    </div>
    {% endif %}

    <!-- Author profile (resolved from the authors.cache.php singleton) -->
    {% if $post['show_author'] && $post['author']['name'] %}
    <div class="hbp-author-profile-box">
        <div class="hbp-author-avatar-area">
            {% if $post['author']['avatar'] %}
            <img src="/assets/site/avatars/{{ $post['author']['avatar'] }}"
                 alt="{{ $post['author']['name'] }}"
                 class="hbp-avatar-img" loading="lazy">
            {% endif %}
        </div>
        <div class="hbp-author-details-area">
            <span class="hbp-author-label">{@ writer @}</span>
            <h4 class="hbp-author-name">{{ $post['author']['name'] }}</h4>
            {% if $post['author']['bio'] %}
            <p class="hbp-author-bio">{{ $post['author']['bio'] }}</p>
            {% endif %}
            {% if count($post['author']['sns'] ?? []) > 0 %}
            <ul class="hbp-author-sns-links">
                {% if $post['author']['sns']['x'] %}
                <li class="hbp-sns-x"><a href="{{ $post['author']['sns']['x'] }}" target="_blank" rel="noopener noreferrer">X</a></li>
                {% endif %}
                {% if $post['author']['sns']['github'] %}
                <li class="hbp-sns-github"><a href="{{ $post['author']['sns']['github'] }}" target="_blank" rel="noopener noreferrer">GitHub</a></li>
                {% endif %}
            </ul>
            {% endif %}
        </div>
    </div>
    {% endif %}

    <!-- Related posts -->
    {% if $post['show_related_posts'] && count($post['related_posts']) > 0 %}
    <div class="hbp-related-posts">
        <h3 class="hbp-related-title">{@ related_posts @}</h3>
        <div class="hbp-related-list">
            <?php $langPrefix = ($lang === $defaultLang) ? '' : "/{$lang}"; ?>
            {% foreach $post['related_posts'] as $related %}
            <a href="{{ $langPrefix }}/post/{{ $related['slug'] }}" class="hbp-related-item">
                <span class="hbp-related-post-title">{{ $related['title'] }}</span>
                <time class="hbp-related-date">{{ Format::date($related['published_at']) }}</time>
            </a>
            {% endforeach %}
        </div>
    </div>
    {% endif %}
</article>

<!-- Comments -->
{% if $post['allow_comment'] && $post['type'] === 'post' %}
<section class="hbp-comments-section">
    <h3 class="hbp-comments-title">{@ comment_title @} ({{ $post['comment_count'] }})</h3>

    {% if count($comments) > 0 %}
    <div class="hbp-comments-list">
        {% foreach $comments as $comment %}
        <div class="hbp-comment">
            <div class="hbp-comment-header">
                <!-- a bundled SVG silhouette shared by all commenters (no external Gravatar; follows the theme via currentColor) -->
                <span class="hbp-comment-avatar" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="36" height="36" fill="currentColor" role="img"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.42 0-8 2.69-8 6v2h16v-2c0-3.31-3.58-6-8-6Z"/></svg>
                </span>
                <strong class="hbp-comment-author">
                    {% if $comment['url'] %}
                    <a href="{{ $comment['url'] }}" target="_blank" rel="noopener noreferrer nofollow">{{ $comment['author'] }}</a>
                    {% else %}
                    {{ $comment['author'] }}
                    {% endif %}
                </strong>
                <time class="hbp-comment-date">{{ Format::date($comment['date']) }}</time>
            </div>
            <div class="hbp-comment-content">{{{ $comment['content'] }}}</div>
        </div>
        {% endforeach %}
    </div>
    {% endif %}

    {% if $_GET['commented'] %}<p class="hbp-comment-thanks">{@ comment_thanks @}</p>{% endif %}

    <div class="hbp-comment-form-wrap">
        <h4>{@ comment_form_title @}</h4>
        <form method="post" action="/comment_post" class="hbp-comment-form">
            <input type="hidden" name="post_id" value="{{ $post['id'] }}">
            <!-- Honeypot (spam-bot defense) -->
            <input type="text" name="honey_pot_email" hidden tabindex="-1" autocomplete="off" aria-hidden="true">

            <div class="hbp-form-group">
                <label>{@ comment_author @}</label>
                <input type="text" name="author_name" required>
            </div>
            <div class="hbp-form-group">
                <label>{@ comment_email @}</label>
                <input type="email" name="author_email">
            </div>
            <div class="hbp-form-group">
                <label>{@ comment_url @}</label>
                <input type="url" name="author_url">
            </div>
            <div class="hbp-form-group">
                <label>{@ comment_content @}</label>
                <textarea name="content" rows="5" required></textarea>
            </div>
            <button type="submit" class="hbp-btn-submit">{@ comment_submit @}</button>
        </form>
    </div>
</section>
{% endif %}

{% include 'footer' %}
