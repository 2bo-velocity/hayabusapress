<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
$pageTitle = '{@ sidebar @}';
$widgetLabels = [
    'search'          => '{@ widget_search @}',
    'recent_comments' => '{@ widget_recent_comments @}',
    'archive'         => '{@ widget_archive @}',
    'categories'      => '{@ widget_categories @}',
    'tags'            => '{@ widget_tags @}',
];
$sidebarLabels = ['sidebar_right' => '{@ sidebar_right @}', 'sidebar_left' => '{@ sidebar_left @}'];
?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-6">{@ sidebar @}</h2>
{% if $_GET['saved'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}

<div class="grid md:grid-cols-2 gap-6">
    {% foreach [['sidebar_right', $sidebarLabels['sidebar_right'], $widgetsRight], ['sidebar_left', $sidebarLabels['sidebar_left'], $widgetsLeft]] as $sbInfo %}
    <div>
        <h3 class="font-medium mb-3">{{ $sbInfo[1] }}</h3>
        <form method="post" action="/{{ $adminPath }}/sidebar/save" class="space-y-3">
    {{{ Security::csrfField() }}}
            <input type="hidden" name="location" value="{{ $sbInfo[0] }}">

            <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-2">
                <p class="text-xs text-slate-500 mb-2">{@ sidebar_widget_hint @}</p>
                {% foreach $allWidgets as $wKey %}
                <?php
                $isActive = in_array($wKey, array_column($sbInfo[2], 'widget_key'));
                $label = $widgetLabels[$wKey] ?? $wKey;
                ?>
                <label class="flex items-center gap-2 text-sm py-1 cursor-pointer">
                    <input type="checkbox" name="widget_keys[]" value="{{ $wKey }}" {{ $isActive ? 'checked' : '' }}>
                    {{ $label }}
                </label>
                {% endforeach %}
            </div>

            <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium py-2 rounded-xl transition-colors">
                {@ save @}
            </button>
        </form>
    </div>
    {% endforeach %}
</div>

{% include 'admin/_layout_foot' %}
