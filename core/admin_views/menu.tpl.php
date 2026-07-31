<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
$pageTitle = '{@ menus @}';
$locations = [
    'pc_header'       => '{@ menu_pc_header @}',
    'pc_sidebar_left' => '{@ menu_pc_sidebar_left @}',
    'pc_sidebar_right'=> '{@ menu_pc_sidebar_right @}',
    'pc_footer'       => '{@ menu_pc_footer @}',
    'sp_header'       => '{@ menu_sp_header @}',
    'sp_footer'       => '{@ menu_sp_footer @}',
];
$activeLocation = $_GET['location'] ?? 'pc_header';
$activeItems = $menus[$activeLocation] ?? [];
?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-6">{@ menus @}</h2>
{% if $_GET['saved'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}

<!-- Tabs -->
<div class="flex flex-wrap gap-2 mb-4">
    {% foreach $locations as $key => $label %}
    <a href="?location={{ $key }}"
       class="px-3 py-1.5 rounded-lg text-sm transition-colors {{ $activeLocation === $key ? 'bg-emerald-500 text-white' : 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700' }}">
        {{ $label }}
    </a>
    {% endforeach %}
</div>

<form method="post" action="/{{ $adminPath }}/menu/save" id="menuForm">
    {{{ Security::csrfField() }}}
    <input type="hidden" name="location" value="{{ $activeLocation }}">

    <div id="menuItems" class="space-y-2 mb-4">
        {% foreach $activeItems as $i => $item %}
        <div class="flex items-center gap-2 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl px-3 py-2">
            <input type="text" name="titles[]" value="{{ $item['title'] }}" placeholder="{@ menu_link_text @}"
                   class="flex-1 text-sm bg-transparent focus:outline-none dark:text-white">
            <input type="text" name="urls[]" value="{{ $item['url'] }}" placeholder="URL"
                   class="flex-1 text-sm bg-transparent focus:outline-none text-slate-500">
            <button type="button" onclick="this.closest('div').remove()" class="text-rose-400 hover:text-rose-600 text-xs px-2">{@ menu_delete_item @}</button>
        </div>
        {% endforeach %}
    </div>

    <div class="flex gap-3">
        <button type="button" onclick="addItem()"
                class="text-sm px-4 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-xl transition-colors">
            {@ menu_add_item @}
        </button>
        <button type="submit"
                class="text-sm px-6 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl transition-colors">
            {@ save @}
        </button>
    </div>
</form>

<script>
var menuLinkText = '{@ menu_link_text @}';
var menuDeleteItem = '{@ menu_delete_item @}';
function addItem() {
    var item = document.createElement('div');
    item.className = 'flex items-center gap-2 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl px-3 py-2';
    item.innerHTML = '<input type="text" name="titles[]" placeholder="' + menuLinkText + '" class="flex-1 text-sm bg-transparent focus:outline-none dark:text-white">'
        + '<input type="text" name="urls[]" placeholder="URL" class="flex-1 text-sm bg-transparent focus:outline-none text-slate-500">'
        + '<button type="button" onclick="this.closest(\'div\').remove()" class="text-rose-400 hover:text-rose-600 text-xs px-2">' + menuDeleteItem + '</button>';
    document.getElementById('menuItems').appendChild(item);
}
</script>

{% include 'admin/_layout_foot' %}
