<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '{@ plugins @}'; ?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-6">{@ plugins @}</h2>
{% if ($_GET['installed'] ?? '') === '1' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ plugin_installed_ok @}</div>{% endif %}
{% if ($_GET['installed'] ?? '') === '0' %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 text-sm">{@ plugin_installed_fail @}</div>{% endif %}
{% if ($_GET['rebuilt'] ?? '') === '1' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ plugin_cache_rebuilt @}</div>{% endif %}
{% if ($_GET['deleted'] ?? '') === '1' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ plugin_deleted_ok @}</div>{% endif %}

<!-- Rebuild-cache button -->
<div class="mb-4 flex justify-end">
    <form method="post" action="/{{ $adminPath }}/plugin/rebuild">
    {{{ Security::csrfField() }}}
        <button type="submit" class="text-sm px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors">
            {@ plugin_rebuild_cache @}
        </button>
    </form>
</div>

<!-- ZIP upload -->
<div class="mb-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
    <h3 class="font-medium text-sm mb-3">{@ plugin_install_zip @}</h3>
    <form method="post" action="/{{ $adminPath }}/plugin/install" enctype="multipart/form-data" class="flex items-center gap-3">
    {{{ Security::csrfField() }}}
        <input type="file" name="zip" accept=".zip" required class="text-sm flex-1">
        <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">
            {@ plugin_install_btn @}
        </button>
    </form>
</div>

<!-- Plugin list -->
{% if count($plugins) === 0 %}
<p class="text-center text-slate-400 py-10">{@ plugin_no_plugins @}</p>
{% else %}
<div class="grid gap-3">
    {% foreach $plugins as $name => $plugin %}
    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h4 class="font-medium">
                    {{ $plugin['name'] }}
                    {% if $plugin['page_template'] %}<span class="ml-1 align-middle text-xs bg-sky-50 dark:bg-sky-900/20 text-sky-600 px-2 py-0.5 rounded-full">{@ page_template @}</span>{% endif %}
                </h4>
                <p class="text-xs text-slate-500 mt-0.5">v{{ $plugin['version'] }} — {{ $plugin['author'] }}</p>
                <p class="text-sm text-slate-400 mt-1">{{ $plugin['description'] }}</p>
            </div>
            <div class="flex items-center gap-2">
                <form method="post" action="/{{ $adminPath }}/plugin/toggle">
    {{{ Security::csrfField() }}}
                    <input type="hidden" name="name"   value="{{ $name }}">
                    <input type="hidden" name="action" value="{{ $plugin['active'] ? 'deactivate' : 'activate' }}">
                    <button type="submit"
                            class="text-sm px-4 py-1.5 rounded-xl font-medium transition-colors {{ $plugin['active'] ? 'bg-rose-50 dark:bg-rose-900/20 text-rose-500 hover:bg-rose-100' : 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-500 hover:bg-emerald-100' }}">
                        {{ $plugin['active'] ? '{@ plugin_deactivate @}' : '{@ plugin_activate @}' }}
                    </button>
                </form>
                <form method="post" action="/{{ $adminPath }}/plugin/delete" onsubmit="return confirm('{@ plugin_delete_confirm @}')">
    {{{ Security::csrfField() }}}
                    <input type="hidden" name="name" value="{{ $name }}">
                    <button type="submit" class="text-sm px-3 py-1.5 rounded-xl font-medium transition-colors bg-slate-100 dark:bg-slate-800 text-slate-500 hover:bg-rose-50 hover:text-rose-500">
                        {@ plugin_delete @}
                    </button>
                </form>
            </div>
        </div>
        {% if $plugin['active'] %}
        <div class="mt-2 flex flex-wrap gap-1">
            {% foreach array_keys($plugin['hooks'] ?? []) as $hookName %}
            <span class="text-xs bg-slate-100 dark:bg-slate-800 px-2 py-0.5 rounded-full text-slate-500">{{ $hookName }}</span>
            {% endforeach %}
        </div>
        {% endif %}
        {% if $plugin['page_template'] %}
        <div class="mt-3 pt-3 border-t border-slate-100 dark:border-slate-800">
            {% if $plugin['active'] %}
            <a href="/{{ $adminPath }}/post/new?type=page&page_template={{ $plugin['page_template'] }}"
               class="inline-flex items-center gap-1 text-sm text-emerald-600 hover:underline">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="M9 15h6"/></svg>
                {@ plugin_create_page @}
            </a>
            {% else %}
            <p class="text-xs text-slate-400">{@ plugin_page_template_hint @}</p>
            {% endif %}
        </div>
        {% endif %}
    </div>
    {% endforeach %}
</div>
{% endif %}

{% include 'admin/_layout_foot' %}
