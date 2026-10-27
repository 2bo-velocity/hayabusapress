<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '{@ plugins @}'; ?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-6">{@ plugins @}</h2>
{% if ($_GET['installed'] ?? '') === '1' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ plugin_installed_ok @}</div>{% endif %}
{% if ($_GET['installed'] ?? '') === '0' %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 text-sm">{@ plugin_installed_fail @}</div>{% endif %}
{% if ($_GET['rebuilt'] ?? '') === '1' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ plugin_cache_rebuilt @}</div>{% endif %}
{% if ($_GET['deleted'] ?? '') === '1' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ plugin_deleted_ok @}</div>{% endif %}
{% if $_GET['error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 text-sm">{{ $_GET['error'] }}</div>{% endif %}
{% if $_GET['applied'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ plugin_applied_ok @} {{ $_GET['applied'] }}</div>{% endif %}
{% if ($_GET['rolledback'] ?? '') === '1' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ plugin_rolled_back_ok @}</div>{% endif %}
{% if ($_GET['recompile'] ?? '') === '1' %}<div class="mb-4 p-3 rounded-xl bg-amber-50 dark:bg-amber-950/40 text-amber-800 dark:text-amber-200 text-sm">{@ plugin_recompile_needed @} <a href="/{{ $adminPath }}/maintenance/upgrade" class="underline font-medium">{@ upg_step_recompile @}</a></div>{% endif %}
{% if $_GET['notice'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{{ $_GET['notice'] }}</div>{% endif %}

{% foreach $interrupted as $jid => $journal %}
<div class="mb-4 p-4 rounded-2xl border border-rose-300 bg-rose-50 dark:border-rose-700 dark:bg-rose-950/40 text-sm text-rose-800 dark:text-rose-200">
    <p class="font-medium">{@ plugin_interrupted @} <code>{{ $jid }}</code> ({{ $journal['op'] }} · {{ $journal['started_at'] }})</p>
    <p class="mt-1 text-xs">{@ plugin_interrupted_desc @} <code>php core/cli.php plugins:repair {{ $jid }}</code></p>
    <form method="post" action="/{{ $adminPath }}/plugin/repair" class="mt-2">
    {{{ Security::csrfField() }}}
        <input type="hidden" name="name" value="{{ $jid }}">
        <button type="submit" class="text-sm px-4 py-1.5 rounded-xl bg-rose-500 hover:bg-rose-600 text-white font-medium transition-colors">{@ plugin_repair_btn @}</button>
    </form>
</div>
{% endforeach %}

{% include 'admin/_reauth' %}

<!-- Rebuild-cache button -->
<div class="mb-4 flex justify-end">
    <form method="post" action="/{{ $adminPath }}/plugin/rebuild">
    {{{ Security::csrfField() }}}
        <button type="submit" class="text-sm px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors">
            {@ plugin_rebuild_cache @}
        </button>
    </form>
</div>

<!-- Folders put in plugins/_incoming/ over FTP or SSH (the recommended way to update) -->
<div class="mb-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
    <h3 class="font-medium text-sm mb-1">{@ plugin_incoming_title @}</h3>
    <p class="text-xs text-slate-500 mb-3">{@ plugin_incoming_desc @}</p>
    {% if !$incoming %}<p class="text-xs text-slate-400">{@ plugin_incoming_empty @}</p>{% endif %}
    {% foreach $incoming as $inId => $inc %}
    <form method="post" action="/{{ $adminPath }}/plugin/stage-incoming" class="flex items-center justify-between gap-3 py-1">
    {{{ Security::csrfField() }}}
        <input type="hidden" name="name" value="{{ $inId }}">
        <span class="text-sm">{{ $inc['name'] }} <span class="text-slate-500">v{{ $inc['version'] }} · plugins/_incoming/{{ $inId }}/</span></span>
        <button type="submit" class="text-sm px-4 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 hover:bg-emerald-100 font-medium transition-colors">{@ plugin_review_btn @}</button>
    </form>
    {% endforeach %}
</div>

<!-- ZIP upload -->
<div class="mb-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
    <h3 class="font-medium text-sm mb-3">{@ plugin_install_zip @}</h3>
    {% if $installGate['open'] %}
    <form method="post" action="/{{ $adminPath }}/plugin/install" enctype="multipart/form-data" class="flex items-center gap-3">
    {{{ Security::csrfField() }}}
        <input type="file" name="zip" accept=".zip" required class="text-sm flex-1">
        <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">
            {@ plugin_install_btn @}
        </button>
    </form>
    {% else %}
    {% include 'admin/_install_gate' %}
    {% endif %}
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
                {% if $backups[$name] %}
                <form method="post" action="/{{ $adminPath }}/plugin/rollback" onsubmit="return confirm('{@ plugin_rollback_confirm @}')">
    {{{ Security::csrfField() }}}
                    <input type="hidden" name="name" value="{{ $name }}">
                    <button type="submit" title="{{ $backups[$name]['backed_up_at'] }}" class="text-sm px-3 py-1.5 rounded-xl font-medium transition-colors bg-slate-100 dark:bg-slate-800 text-slate-500 hover:bg-amber-50 hover:text-amber-700">
                        {@ plugin_rollback_btn @} v{{ $backups[$name]['version'] }}
                    </button>
                </form>
                {% endif %}
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
        {% if $changedPlugins[$name] %}
        <div class="mt-3 p-3 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-xs text-rose-700 dark:text-rose-200">
            {% if $changedPlugins[$name]['disabled'] %}{@ integrity_stopped @} · {% endif %}{@ integrity_changed_since @} {{ $changedPlugins[$name]['since'] }}
            · <a href="/{{ $adminPath }}/integrity" class="underline">{@ integrity_details @}</a>
        </div>
        {% endif %}
        {% if $pluginWarnings[$name] %}
        <div class="mt-3 p-3 rounded-xl bg-amber-50 dark:bg-amber-950/40 text-xs text-amber-800 dark:text-amber-200">
            <p class="font-medium mb-1">{@ plugin_warnings @}</p>
            <ul class="list-disc pl-4 space-y-0.5">
                {% foreach $pluginWarnings[$name] as $warning %}<li>{{ $warning }}</li>{% endforeach %}
            </ul>
        </div>
        {% endif %}
        {% if $pluginErrors[$name] %}
        <div class="mt-3 p-3 rounded-xl bg-rose-50 dark:bg-rose-950/40 text-xs text-rose-700 dark:text-rose-200">
            <p class="font-medium">{@ plugin_errors_recent @} {{ $pluginErrors[$name]['count'] }}</p>
            <p class="mt-1">{{ $pluginErrors[$name]['last_at'] }} · {{ $pluginErrors[$name]['last_where'] }}</p>
            <p class="mt-1 break-all">{{ $pluginErrors[$name]['last_message'] }}</p>
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

{% if $unreadablePlugins %}
<div class="mt-6 p-4 rounded-2xl bg-amber-50 dark:bg-amber-950/40 text-sm text-amber-800 dark:text-amber-200">
    <p class="font-medium mb-2">{@ plugin_unreadable @}</p>
    {% foreach $unreadablePlugins as $dir => $warnings %}
    <p class="text-xs"><code>plugins/{{ $dir }}/</code>: {{ implode(' ', $warnings) }}</p>
    {% endforeach %}
</div>
{% endif %}

{% if $pluginErrors %}
<div class="mt-6 flex justify-end">
    <form method="post" action="/{{ $adminPath }}/plugin/errors/clear">
    {{{ Security::csrfField() }}}
        <button type="submit" class="text-sm px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors">
            {@ plugin_errors_clear @}
        </button>
    </form>
</div>
{% endif %}

{% if $auditRecent %}
<details class="mt-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
    <summary class="text-sm font-medium cursor-pointer">{@ plugin_audit_title @}</summary>
    <pre class="mt-3 text-xs text-slate-500 overflow-x-auto">{% foreach $auditRecent as $auditLine %}{{ $auditLine }}
{% endforeach %}</pre>
</details>
{% endif %}

{% include 'admin/_layout_foot' %}
