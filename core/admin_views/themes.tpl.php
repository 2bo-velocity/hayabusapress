<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '{@ themes @}'; ?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-6">{@ themes @}</h2>

{% if ($_GET['activated'] ?? '') === '1' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ theme_activated_ok @}</div>{% endif %}
{% if ($_GET['installed'] ?? '') === '1' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ theme_installed_ok @}</div>{% endif %}
{% if ($_GET['deleted'] ?? '') === '1' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ theme_deleted_ok @}</div>{% endif %}
{% if ($_GET['rebuilt'] ?? '') === '1' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ theme_rebuilt_ok @}</div>{% endif %}
{% if $_GET['error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 text-sm">{{ $_GET['error'] }}</div>{% endif %}

<!-- ZIP upload -->
<div class="mb-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
    <h3 class="font-medium text-sm mb-3">{@ theme_install_zip @}</h3>
    <form method="post" action="/{{ $adminPath }}/theme/install" enctype="multipart/form-data" class="flex items-center gap-3">
    {{{ Security::csrfField() }}}
        <input type="file" name="zip" accept=".zip" required class="text-sm flex-1 min-w-0">
        <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">
            {@ theme_install_btn @}
        </button>
    </form>
    <p class="text-xs text-slate-400 mt-2">{@ theme_install_note @}</p>
</div>

<!-- Theme list -->
{% if count($themes) === 0 %}
<p class="text-center text-slate-400 py-10">{@ theme_no_themes @}</p>
{% else %}
<div class="grid gap-3">
    {% foreach $themes as $name => $theme %}
    <div class="bg-white dark:bg-slate-950 border {{ $theme['active'] ? 'border-emerald-300 dark:border-emerald-800' : 'border-slate-100 dark:border-slate-800' }} rounded-2xl p-4">
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-start gap-3 min-w-0">
                {% if $theme['screenshot'] %}
                <img src="{{ $theme['screenshot'] }}" alt="{{ $theme['name'] }}" class="w-16 h-16 rounded-lg object-cover bg-slate-100 dark:bg-slate-900 shrink-0">
                {% else %}
                <div class="w-16 h-16 rounded-lg bg-slate-100 dark:bg-slate-900 text-slate-300 dark:text-slate-700 flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>
                </div>
                {% endif %}
                <div class="min-w-0">
                    <h4 class="font-medium flex items-center gap-2 flex-wrap">
                        {{ $theme['name'] }}
                        {% if $theme['active'] %}<span class="text-xs font-medium bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 px-2 py-0.5 rounded-full">{@ theme_active @}</span>{% endif %}
                        {% if !$theme['complete'] %}<span class="text-xs font-medium bg-amber-50 dark:bg-amber-900/20 text-amber-600 px-2 py-0.5 rounded-full">{@ theme_incomplete_badge @}</span>{% endif %}
                    </h4>
                    <p class="text-xs text-slate-500 mt-0.5">v{{ $theme['version'] }}{% if $theme['author'] %} — {{ $theme['author'] }}{% endif %}</p>
                    <p class="text-sm text-slate-400 mt-1">{{ $theme['description'] }}</p>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                {% if $theme['active'] %}
                <form method="post" action="/{{ $adminPath }}/theme/rebuild">
    {{{ Security::csrfField() }}}
                    <button type="submit" class="text-sm px-4 py-1.5 rounded-xl font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">{@ theme_rebuild @}</button>
                </form>
                {% else %}
                {% if $theme['complete'] %}
                <form method="post" action="/{{ $adminPath }}/theme/activate">
    {{{ Security::csrfField() }}}
                    <input type="hidden" name="name" value="{{ $name }}">
                    <button type="submit" class="text-sm px-4 py-1.5 rounded-xl font-medium bg-emerald-50 dark:bg-emerald-900/20 text-emerald-500 hover:bg-emerald-100 transition-colors">{@ theme_activate @}</button>
                </form>
                {% endif %}
                {% if $name !== 'default' %}
                <form method="post" action="/{{ $adminPath }}/theme/delete" onsubmit="return confirm('{@ theme_delete_confirm @}')">
    {{{ Security::csrfField() }}}
                    <input type="hidden" name="name" value="{{ $name }}">
                    <button type="submit" class="text-sm px-3 py-1.5 rounded-xl font-medium bg-slate-100 dark:bg-slate-800 text-slate-500 hover:bg-rose-50 hover:text-rose-500 transition-colors">{@ theme_delete @}</button>
                </form>
                {% endif %}
                {% endif %}
            </div>
        </div>
    </div>
    {% endforeach %}
</div>
{% endif %}

{% include 'admin/_layout_foot' %}
