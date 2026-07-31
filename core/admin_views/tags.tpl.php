<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '{@ tag_management @}'; ?>
{% include 'admin/_layout_head' %}

<div class="flex items-center gap-4 mb-6">
    <a href="/{{ $adminPath }}/categories" class="text-slate-400 hover:text-slate-600 text-sm">{@ back_to_categories @}</a>
    <h2 class="text-xl font-bold">{@ tag_management @}</h2>
</div>
{% if $_GET['saved'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}
{% if $_GET['error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ save_error @}</div>{% endif %}

<div class="grid md:grid-cols-2 gap-6">
    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500">
                <tr>
                    <th class="text-left px-4 py-3 font-medium">{@ name @}</th>
                    <th class="text-left px-4 py-3 font-medium w-16">{@ language @}</th>
                    <th class="text-left px-4 py-3 font-medium w-20">{@ post_type @}</th>
                    <th class="px-4 py-3 w-16"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                {% foreach $tags as $tag %}
                <tr>
                    <td class="px-4 py-2">{{ $tag['name'] }} <span class="text-slate-400 text-xs">/ {{ $tag['slug'] }}</span></td>
                    <td class="px-4 py-2">
                        <span class="inline-block px-2 py-0.5 text-xs rounded-lg bg-slate-100 dark:bg-slate-800 font-mono">{{ $tag['lang'] }}</span>
                    </td>
                    <td class="px-4 py-2 text-slate-500 text-xs">{{ $tag['type'] }}</td>
                    <td class="px-4 py-2">
                        <form method="post" action="/{{ $adminPath }}/tag/delete" onsubmit="return confirm('{@ confirm_delete @}')">
    {{{ Security::csrfField() }}}
                            <input type="hidden" name="id" value="{{ $tag['id'] }}">
                            <button type="submit" class="text-xs text-rose-400 hover:text-rose-600">{@ delete @}</button>
                        </form>
                    </td>
                </tr>
                {% endforeach %}
            </tbody>
        </table>
    </div>

    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
        <h3 class="font-medium text-sm mb-3">{@ add_tag @}</h3>
        <form method="post" action="/{{ $adminPath }}/tag/save" class="space-y-3">
    {{{ Security::csrfField() }}}
            <input type="text" name="name" placeholder="{@ name @}" required
                   class="w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
            <input type="text" name="slug" placeholder="{@ slug_optional @}"
                   class="w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
            <div class="grid grid-cols-2 gap-2">
                <select name="type" class="w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
                    <option value="post">{@ type_for_post @}</option>
                    <option value="page">{@ type_for_page @}</option>
                </select>
                <select name="lang" class="w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
                    {% foreach $contentLocales as $lCode => $lLabel %}
                    <option value="{{ $lCode }}">{{ $lLabel }}</option>
                    {% endforeach %}
                </select>
            </div>
            <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium py-2 rounded-xl transition-colors">{@ add @}</button>
        </form>
    </div>
</div>

{% include 'admin/_layout_foot' %}
