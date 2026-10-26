<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
//
// A series' table of contents (/post/series/{slug}).
//
// Not a required template: a theme without one gets this file (ViewCompiler falls back to the
// default theme per view), inside that theme's own header and footer and under its own CSS. So the
// markup is plain and meaningful on its own — headings and ordered lists that read fine unstyled —
// and every class is hbp-series-* for a theme to style.
$seoMeta    = SeoCompiler::buildSeriesMeta($series, $canonical ?? '', $lang ?? '');
$langPrefix = ($lang === $defaultLang) ? '' : "/{$lang}";
$e          = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt        = static fn(string $key, string $fallback, $n) => $e(sprintf($__[$key] ?? $fallback, $n));
?>
{% include 'header' %}

<section class="hbp-series">
    <header class="hbp-series-header">
        {% if $series['cover_image'] %}
        <img class="hbp-series-cover" src="{{ $series['cover_image'] }}" alt="" loading="lazy">
        {% endif %}
        <p class="hbp-series-label">{@ series_label @}</p>
        <h1 class="hbp-archive-title hbp-series-title">{{ $series['title'] }}</h1>
        <p class="hbp-series-meta">
            <span class="hbp-series-status hbp-series-status-{{ $series['status'] }}">{% if $series['status'] === 'completed' %}{@ series_status_completed @}{% elseif $series['status'] === 'hiatus' %}{@ series_status_hiatus @}{% else %}{@ series_status_ongoing @}{% endif %}</span>
            <span class="hbp-series-count"><?= $fmt('series_count', '%d parts', (int)$series['post_count']) ?></span>
            <span class="hbp-series-chars"><?= $fmt('series_chars', '%s characters', number_format((int)$series['char_count'])) ?></span>
        </p>
        {% if $series['description_html'] %}
        <div class="hbp-series-desc">{{{ $series['description_html'] }}}</div>
        {% endif %}
        {% if $page === 1 %}
        <p class="hbp-series-start"><a class="hbp-button" href="{{ $firstUrl }}">{@ series_start @}</a></p>
        {% endif %}
    </header>

    <nav class="hbp-series-toc" aria-label="{@ series_index @}">
        <h2 class="hbp-series-toc-title">{@ series_index @}</h2>
        <?php $open = false; foreach ($posts as $p): ?>
            <?php if ($p['part_heading'] !== null || !$open): ?>
                <?php if ($open): ?></ol><?php endif; ?>
                <?php if ($p['part_heading'] !== null): ?><h3 class="hbp-series-part"><?= $e($p['part_heading']) ?></h3><?php endif; ?>
                <ol class="hbp-series-list">
                <?php $open = true; ?>
            <?php endif; ?>
            <li class="hbp-series-item">
                <a class="hbp-series-item-title" href="<?= $e($langPrefix . '/post/' . $p['slug']) ?>"><?= $e($p['title']) ?></a>
                <span class="hbp-series-item-meta">
                    <time datetime="<?= $e(Format::iso($p['published_at'])) ?>"><?= $e(Format::date($p['published_at'])) ?></time>
                    <span><?= $fmt('series_chars', '%s characters', number_format((int)$p['char_count'])) ?></span>
                </span>
            </li>
        <?php endforeach; ?>
        <?php if ($open): ?></ol><?php endif; ?>
    </nav>
</section>

{% include 'pagination' %}

{% include 'footer' %}
