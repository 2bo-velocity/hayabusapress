<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '{@ badwords @}'; ?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-6">{@ badwords @}</h2>

{% if ($_GET['success'] ?? '') === 'created' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ badword_added_ok @}</div>{% endif %}
{% if ($_GET['success'] ?? '') === 'deleted' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ badword_deleted_ok @}</div>{% endif %}
{% if ($_GET['success'] ?? '') === 'rebuilt' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}
{% if ($_GET['error'] ?? '') === 'duplicate' %}<div class="mb-4 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 text-amber-600 text-sm">{@ badword_duplicate @}</div>{% endif %}

<div class="grid md:grid-cols-2 gap-6">

    <!-- Add form -->
    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
        <h3 class="font-medium text-sm mb-3">{@ badword_add @}</h3>
        <form method="post" action="/{{ $adminPath }}/badword/store" class="flex items-center gap-2">
    {{{ Security::csrfField() }}}
            <input type="text" name="word" required maxlength="255"
                   class="flex-1 text-sm px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-900 focus:outline-none focus:ring-2 focus:border-emerald-500"
                   placeholder="{@ badword_placeholder @}">
            <button type="submit"
                    class="bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">
                {@ add_btn @}
            </button>
        </form>
        <p class="text-xs text-slate-400 mt-2">{@ badword_hint @}</p>
    </div>

    <!-- Cache info -->
    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 flex flex-col justify-between">
        <div>
            <h3 class="font-medium text-sm mb-1">{@ badword_cache_info @}</h3>
            <p class="text-xs text-slate-400">{@ badword_cache_desc @}</p>
        </div>
        <form method="post" action="/{{ $adminPath }}/badword/rebuild" class="mt-3">
    {{{ Security::csrfField() }}}
            <button type="submit" class="text-sm px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors">
                {@ badword_rebuild_cache @}
            </button>
        </form>
    </div>
</div>

<!-- Keyword list -->
<div class="mt-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl overflow-hidden">
    {% if count($words) === 0 %}
    <p class="text-center text-slate-400 py-10 text-sm">{@ badword_none @}</p>
    {% else %}
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-slate-100 dark:border-slate-800 text-xs text-slate-400 uppercase tracking-wider">
                <th class="text-left px-4 py-3 font-medium">{@ name @}</th>
                <th class="text-left px-4 py-3 font-medium">{@ created_at @}</th>
                <th class="px-4 py-3 font-medium text-right">{@ actions @}</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-50 dark:divide-slate-900">
            {% foreach $words as $w %}
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors">
                <td class="px-4 py-3 font-mono font-medium">{{ $w['word'] }}</td>
                <td class="px-4 py-3 text-slate-400 text-xs">{{ $w['created_at'] }}</td>
                <td class="px-4 py-3 text-right">
                    <form method="post" action="/{{ $adminPath }}/badword/delete" onsubmit="return confirm('{@ confirm_delete @}')">
    {{{ Security::csrfField() }}}
                        <input type="hidden" name="id" value="{{ $w['id'] }}">
                        <button type="submit" class="text-xs text-rose-500 hover:text-rose-700 font-medium transition-colors">
                            {@ delete @}
                        </button>
                    </form>
                </td>
            </tr>
            {% endforeach %}
        </tbody>
    </table>
    {% endif %}
</div>

{% include 'admin/_layout_foot' %}
