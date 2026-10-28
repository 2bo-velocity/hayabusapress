<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
//
// A series' place in its collection, included by series.tpl.php above the table of contents
// ($collectionNavPlace = 'top': the collection's name) and below it ('bottom': the previous / next
// series). $collectionNav is null for a series outside a collection, and then nothing is printed.
//
// Other themes are not given this automatically — their series.tpl.php has to include it (or read
// $collectionNav itself), like series_nav on a post page.
$cn = $collectionNav ?? null;
?>
<?php if ($cn): ?>
<?php if (($collectionNavPlace ?? 'bottom') === 'top'): ?>
<p class="hbp-collection-crumb"><a href="{{ $cn['url'] }}">{{ $cn['title'] }}</a></p>
<?php elseif ($cn['prev'] || $cn['next']): ?>
<nav class="hbp-collection-nav" aria-label="{@ collection_nav_aria @}">
    <ul class="hbp-series-nav-links">
        <li class="hbp-series-nav-prev">{% if $cn['prev'] %}<a href="{{ $cn['prev']['url'] }}"><span class="hbp-series-nav-dir">{@ collection_prev @}</span> <span class="hbp-series-nav-name">{{ $cn['prev']['title'] }}</span></a>{% endif %}</li>
        <li class="hbp-series-nav-index"><a href="{{ $cn['url'] }}">{{ $cn['title'] }}</a></li>
        <li class="hbp-series-nav-next">{% if $cn['next'] %}<a href="{{ $cn['next']['url'] }}"><span class="hbp-series-nav-dir">{@ collection_next @}</span> <span class="hbp-series-nav-name">{{ $cn['next']['title'] }}</span></a>{% endif %}</li>
    </ul>
</nav>
<?php endif; ?>
<?php endif; ?>
