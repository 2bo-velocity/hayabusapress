<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
//
// A post's place in its series, included by post.tpl.php above the body ($seriesNavPlace = 'top':
// the series title and chapter) and below it ('bottom': previous / contents / next).
// $post['series'] is null for a post outside a series, and then nothing is printed.
//
// Other themes are not given this automatically — their post.tpl.php has to include it (or read
// $post['series'] itself), like PluginManager::snippet() and SidebarCompiler::areaFor().
$sn = $post['series'] ?? null;
?>
<?php if ($sn): ?>
<?php if (($seriesNavPlace ?? 'bottom') === 'top'): ?>
<p class="hbp-series-crumb">
    <a href="{{ $sn['url'] }}">{{ $sn['title'] }}</a>{% if $sn['part'] %}<span class="hbp-series-crumb-part">{{ $sn['part'] }}</span>{% endif %}
</p>
<?php else: ?>
<nav class="hbp-series-nav" aria-label="{@ series_nav_aria @}">
    <ul class="hbp-series-nav-links">
        <li class="hbp-series-nav-prev">{% if $sn['prev'] %}<a href="{{ $sn['prev']['url'] }}"><span class="hbp-series-nav-dir">{@ series_prev @}</span> <span class="hbp-series-nav-name">{{ $sn['prev']['title'] }}</span></a>{% endif %}</li>
        <li class="hbp-series-nav-index"><a href="{{ $sn['url'] }}">{@ series_index @}</a></li>
        <li class="hbp-series-nav-next">{% if $sn['next'] %}<a href="{{ $sn['next']['url'] }}"><span class="hbp-series-nav-dir">{@ series_next @}</span> <span class="hbp-series-nav-name">{{ $sn['next']['title'] }}</span></a>{% endif %}</li>
    </ul>
</nav>
<?php endif; ?>
<?php endif; ?>
