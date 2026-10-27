<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '{@ maintenance @}'; ?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-6">{@ maintenance @}</h2>

{% if $_GET['done'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ maintenance_done @}</div>{% endif %}

<p class="text-sm text-slate-500 dark:text-slate-400 mb-6 max-w-2xl">{@ maintenance_desc @}</p>

<div class="space-y-3 max-w-2xl">

    <a href="/{{ $adminPath }}/maintenance/upgrade" class="block bg-white dark:bg-slate-950 border border-emerald-200 dark:border-emerald-800 rounded-2xl p-5 hover:bg-emerald-50 dark:hover:bg-emerald-950/30 transition-colors">
        <span class="text-sm font-medium">{@ upg_title @} →</span>
        <span class="block text-xs text-slate-500 mt-1">{@ upg_link_desc @}</span>
    </a>

    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 flex items-center justify-between gap-4">
        <span class="text-sm font-medium">{@ maintenance_sitemap @}</span>
        <form method="post" action="/{{ $adminPath }}/maintenance/run">
            {{{ Security::csrfField() }}}
            <input type="hidden" name="task" value="sitemap">
            <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-medium px-5 py-2 rounded-xl transition-colors text-sm whitespace-nowrap">{@ maintenance_sitemap @}</button>
        </form>
    </div>

    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 flex items-center justify-between gap-4">
        <span class="text-sm font-medium">{@ maintenance_feed @}</span>
        <form method="post" action="/{{ $adminPath }}/maintenance/run">
            {{{ Security::csrfField() }}}
            <input type="hidden" name="task" value="feed">
            <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-medium px-5 py-2 rounded-xl transition-colors text-sm whitespace-nowrap">{@ maintenance_feed @}</button>
        </form>
    </div>

    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 flex items-center justify-between gap-4">
        <span class="text-sm font-medium">{@ maintenance_plugins @}</span>
        <form method="post" action="/{{ $adminPath }}/maintenance/run">
            {{{ Security::csrfField() }}}
            <input type="hidden" name="task" value="plugins">
            <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-medium px-5 py-2 rounded-xl transition-colors text-sm whitespace-nowrap">{@ maintenance_plugins @}</button>
        </form>
    </div>

    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-5 flex items-center justify-between gap-4">
        <span class="text-sm font-medium">{@ maintenance_theme @}</span>
        <form method="post" action="/{{ $adminPath }}/maintenance/run">
            {{{ Security::csrfField() }}}
            <input type="hidden" name="task" value="theme">
            <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-medium px-5 py-2 rounded-xl transition-colors text-sm whitespace-nowrap">{@ maintenance_theme @}</button>
        </form>
    </div>

    <div class="bg-white dark:bg-slate-950 border border-emerald-200 dark:border-emerald-800 rounded-2xl p-5 flex items-center justify-between gap-4">
        <span class="text-sm font-medium">{@ maintenance_all @}</span>
        <form method="post" action="/{{ $adminPath }}/maintenance/run">
            {{{ Security::csrfField() }}}
            <input type="hidden" name="task" value="all">
            <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white font-medium px-5 py-2 rounded-xl transition-colors text-sm whitespace-nowrap">{@ maintenance_all @}</button>
        </form>
    </div>

</div>

{% include 'admin/_layout_foot' %}
