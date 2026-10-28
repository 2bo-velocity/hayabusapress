<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
//
// A collection page (/post/collection/{slug}): its series in collection order.
//
// Not a required template: a theme without one gets this file (ViewCompiler falls back to the
// default theme per view), inside that theme's own header and footer and under its own CSS. Every
// class is hbp-collection-*. Each series' status is its own element (hbp-collection-series-status),
// so a theme for which "completed" means nothing can hide it with CSS alone.
$seoMeta = SeoCompiler::buildCollectionMeta($collection, $series, $canonical ?? '', $lang ?? '');
$e       = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$fmt     = static fn(string $key, string $fallback, $n) => $e(sprintf($__[$key] ?? $fallback, $n));
?>
{% include 'header' %}

<section class="hbp-collection">
    <header class="hbp-collection-header">
        {% if $collection['cover_image'] %}
        <img class="hbp-collection-cover" src="{{ $collection['cover_image'] }}" alt="" loading="lazy">
        {% endif %}
        <p class="hbp-collection-label">{@ collection_label @}</p>
        <h1 class="hbp-archive-title hbp-collection-title">{{ $collection['title'] }}</h1>
        <p class="hbp-collection-meta">
            <span class="hbp-collection-count"><?= $fmt('collection_series_count', '%d series', count($series)) ?></span>
            <span class="hbp-collection-parts"><?= $fmt('series_count', '%d parts', (int)$collection['post_count']) ?></span>
            <span class="hbp-collection-chars"><?= $fmt('series_chars', '%s characters', number_format((int)$collection['char_count'])) ?></span>
        </p>
        {% if $collection['description_html'] %}
        <div class="hbp-collection-desc">{{{ $collection['description_html'] }}}</div>
        {% endif %}
    </header>

    <nav class="hbp-collection-list" aria-label="{@ collection_series @}">
        <h2 class="hbp-collection-list-title">{@ collection_series @}</h2>
        <ol class="hbp-collection-series">
            <?php foreach ($series as $s): ?>
            <li class="hbp-collection-series-item">
                <?php if ($s['cover'] !== ''): ?>
                <img class="hbp-collection-series-cover" src="<?= $e($s['cover']) ?>" alt="" loading="lazy">
                <?php endif; ?>
                <div class="hbp-collection-series-body">
                    <a class="hbp-collection-series-title" href="<?= $e($s['url']) ?>"><?= $e($s['title']) ?></a>
                    <span class="hbp-collection-series-meta">
                        <span class="hbp-collection-series-status hbp-series-status hbp-series-status-<?= $e($s['status']) ?>"><?php if ($s['status'] === 'completed'): ?>{@ series_status_completed @}<?php elseif ($s['status'] === 'hiatus'): ?>{@ series_status_hiatus @}<?php else: ?>{@ series_status_ongoing @}<?php endif; ?></span>
                        <span><?= $fmt('series_count', '%d parts', (int)$s['post_count']) ?></span>
                        <span><?= $fmt('series_chars', '%s characters', number_format((int)$s['char_count'])) ?></span>
                    </span>
                </div>
            </li>
            <?php endforeach; ?>
        </ol>
    </nav>
</section>

{% include 'footer' %}
