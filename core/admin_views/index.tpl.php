<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '{@ posts @}'; ?>
{% include 'admin/_layout_head' %}

<div class="flex items-center justify-between mb-6">
    <h2 class="text-xl font-bold">{@ posts @}</h2>
    {% if ($view ?? 'active') !== 'trash' %}
    <a href="/{{ $adminPath }}/post/new"
       class="bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">
        + {@ new_post @}
    </a>
    {% endif %}
</div>

<!-- normal / trash view switch -->
<div class="flex items-center gap-2 mb-4 text-sm">
    <a href="/{{ $adminPath }}/posts"
       class="px-3 py-1.5 rounded-xl transition-colors {% if ($view ?? 'active') !== 'trash' %}bg-emerald-500 text-white{% else %}text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800{% endif %}">
        {@ view_normal @}
    </a>
    <a href="/{{ $adminPath }}/posts?view=trash"
       class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl transition-colors {% if ($view ?? 'active') === 'trash' %}bg-rose-500 text-white{% else %}text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800{% endif %}">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
        {@ view_trash @}
    </a>
</div>

{% if $_GET['cache_cleared'] %}
<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 dark:text-emerald-400 text-sm">
    {@ cache_cleared_msg @}
</div>
{% endif %}

<!-- search / filtering (on mobile, collapsed in a details element = bottom-sheet-like; expanded on PC) -->
<details class="mb-4 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl overflow-hidden" {% if $filters['q'] || $filters['type'] || $filters['status'] || $filters['category_id'] || $filters['tag_id'] || $filters['pub_from'] || $filters['cre_from'] %}open{% endif %}>
    <summary class="cursor-pointer select-none px-4 py-3 text-sm font-medium text-slate-600 dark:text-slate-300">
        <svg class="w-4 h-4 inline-block align-text-bottom mr-1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>{@ filter @}
    </summary>
    <form method="get" action="/{{ $adminPath }}/posts" class="px-4 pb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
        <input type="hidden" name="view" value="{{ $view }}">

        <div class="sm:col-span-2 lg:col-span-3">
            <label class="block text-xs text-slate-500 mb-1">{@ keyword @}</label>
            <input type="text" name="q" value="{{ $filters['q'] }}"
                   class="w-full text-base px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">{@ post_type @}</label>
            <select name="type" class="w-full text-base px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
                <option value="">{@ filter_any @}</option>
                <option value="post" {% if ($filters['type'] ?? '') === 'post' %}selected{% endif %}>{@ post @}</option>
                <option value="page" {% if ($filters['type'] ?? '') === 'page' %}selected{% endif %}>{@ page @}</option>
            </select>
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">{@ status @}</label>
            <select name="status" class="w-full text-base px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
                <option value="">{@ filter_any @}</option>
                <option value="published" {% if ($filters['status'] ?? '') === 'published' %}selected{% endif %}>{@ published @}</option>
                <option value="draft"     {% if ($filters['status'] ?? '') === 'draft' %}selected{% endif %}>{@ draft @}</option>
                <option value="scheduled" {% if ($filters['status'] ?? '') === 'scheduled' %}selected{% endif %}>{@ scheduled @}</option>
            </select>
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">{@ category @}</label>
            <select name="category_id" class="w-full text-base px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
                <option value="">{@ filter_any @}</option>
                {% foreach $allCategories as $c %}
                <option value="{{ $c['id'] }}" {% if (int)($filters['category_id'] ?? 0) === (int)$c['id'] %}selected{% endif %}>{{ $c['name'] }} ({{ $c['lang'] }})</option>
                {% endforeach %}
            </select>
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">{@ tags @}</label>
            <select name="tag_id" class="w-full text-base px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
                <option value="">{@ filter_any @}</option>
                {% foreach $allTags as $t %}
                <option value="{{ $t['id'] }}" {% if (int)($filters['tag_id'] ?? 0) === (int)$t['id'] %}selected{% endif %}>{{ $t['name'] }} ({{ $t['lang'] }})</option>
                {% endforeach %}
            </select>
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">{@ published_period @}</label>
            <div class="flex items-center gap-1">
                <input type="date" name="pub_from" value="{{ $filters['pub_from'] }}" class="w-full text-base px-2 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white">
                <span class="text-slate-400">–</span>
                <input type="date" name="pub_to" value="{{ $filters['pub_to'] }}" class="w-full text-base px-2 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white">
            </div>
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">{@ created_period @}</label>
            <div class="flex items-center gap-1">
                <input type="date" name="cre_from" value="{{ $filters['cre_from'] }}" class="w-full text-base px-2 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white">
                <span class="text-slate-400">–</span>
                <input type="date" name="cre_to" value="{{ $filters['cre_to'] }}" class="w-full text-base px-2 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white">
            </div>
        </div>

        <div class="sm:col-span-2 lg:col-span-3 flex items-center gap-2">
            <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-medium px-4 py-2 rounded-xl transition-colors">{@ apply_filter @}</button>
            <a href="/{{ $adminPath }}/posts{% if ($view ?? 'active') === 'trash' %}?view=trash{% endif %}" class="text-slate-400 hover:text-slate-600 px-3 py-2">{@ reset_filter @}</a>
            <span class="ml-auto text-xs text-slate-400">{{ $total }} {@ results_count @}</span>
        </div>
    </form>
</details>

<!-- PC: table layout -->
<div class="hidden md:block bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500 dark:text-slate-400">
            <tr>
                <th class="text-left px-4 py-3 font-medium">{@ title @}</th>
                <th class="text-left px-4 py-3 font-medium w-20">{@ post_type @}</th>
                <th class="text-left px-4 py-3 font-medium w-24">{@ status @}</th>
                <th class="text-left px-4 py-3 font-medium w-28">{@ category @}</th>
                <th class="text-left px-4 py-3 font-medium w-28">{@ author @}</th>
                <th class="text-left px-4 py-3 font-medium w-12">
                    <svg class="w-4 h-4 inline-block align-middle" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-label="{@ comments @}"><title>{@ comments @}</title><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </th>
                <th class="text-left px-4 py-3 font-medium w-32">{% if ($view ?? 'active') === 'trash' %}{@ deleted_at @}{% else %}{@ published_at @}{% endif %}</th>
                <th class="px-4 py-3 w-32"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            {% foreach $posts as $post %}
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-900/50 transition-colors">
                <td class="px-4 py-3">
                    {% if ($view ?? 'active') === 'trash' %}
                    <span class="font-medium text-slate-500">{{ $post['title'] }}</span>
                    {% else %}
                    <a href="/{{ $adminPath }}/post/edit/{{ $post['id'] }}" class="hover:text-emerald-500 font-medium">
                        {{ $post['title'] }}
                    </a>
                    {% endif %}
                    <span class="ml-1 font-mono text-xs text-slate-400">{{ $post['lang'] }}</span>
                </td>
                <td class="px-4 py-3 text-slate-500">{{ $post['type'] === 'post' ? '{@ post @}' : '{@ page @}' }}</td>
                <td class="px-4 py-3">
                    {% if $post['status'] === 'published' %}
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-400">{@ published @}</span>
                    {% elseif $post['status'] === 'scheduled' %}
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-400">{@ scheduled @}</span>
                    {% else %}
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-400">{@ draft @}</span>
                    {% endif %}
                </td>
                <td class="px-4 py-3 text-slate-500">{{ ($post['category_id'] ?? null) ? ($catNames[$post['category_id']] ?? '') : '—' }}</td>
                <td class="px-4 py-3 text-slate-500">{{ $authorNames[(int)($post['user_id'] ?? 0)] ?? '' }}</td>
                <td class="px-4 py-3 text-slate-500">{% if $post['comment_count'] > 0 %}<a href="/{{ $adminPath }}/comments?status=approved&post_id={{ $post['id'] }}" class="text-emerald-500 hover:underline" title="{@ comment_filtered_post @}">{{ $post['comment_count'] }}</a>{% else %}{{ $post['comment_count'] }}{% endif %}</td>
                <td class="px-4 py-3 text-slate-500">{% if ($view ?? 'active') === 'trash' %}{{ Format::date($post['deleted_at'] ?? '') }}{% else %}{{ Format::date($post['published_at'] ?: $post['created_at']) }}{% endif %}</td>
                <td class="px-4 py-3">
                    {% if ($view ?? 'active') === 'trash' %}
                    <div class="flex items-center gap-3">
                        <form method="post" action="/{{ $adminPath }}/post/restore">
    {{{ Security::csrfField() }}}
                            <input type="hidden" name="id" value="{{ $post['id'] }}">
                            <button type="submit" class="text-emerald-500 hover:text-emerald-700 text-xs">{@ restore @}</button>
                        </form>
                        <form method="post" action="/{{ $adminPath }}/post/purge" onsubmit="return confirm('{@ confirm_purge @}')">
    {{{ Security::csrfField() }}}
                            <input type="hidden" name="id" value="{{ $post['id'] }}">
                            <button type="submit" class="text-rose-400 hover:text-rose-600 text-xs">{@ delete_permanently @}</button>
                        </form>
                    </div>
                    {% else %}
                    <form method="post" action="/{{ $adminPath }}/post/delete" onsubmit="return confirm('{@ confirm_move_trash @}')">
    {{{ Security::csrfField() }}}
                        <input type="hidden" name="id" value="{{ $post['id'] }}">
                        <button type="submit" class="text-rose-400 hover:text-rose-600 text-xs">{@ move_to_trash @}</button>
                    </form>
                    {% endif %}
                </td>
            </tr>
            {% endforeach %}
        </tbody>
    </table>
    {% if count($posts) === 0 %}
    <p class="text-center text-slate-400 py-10">{% if ($view ?? 'active') === 'trash' %}{@ trash_empty @}{% else %}{@ no_posts_yet @}{% endif %}</p>
    {% endif %}
</div>

<!-- Mobile: card type -->
<div class="md:hidden space-y-2">
    {% foreach $posts as $post %}
    <div class="p-4 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl">
        <div class="flex items-center justify-between">
            <div class="min-w-0">
                {% if ($view ?? 'active') === 'trash' %}
                <div class="font-medium text-sm mb-1 truncate text-slate-500">{{ $post['title'] }}</div>
                {% else %}
                <a href="/{{ $adminPath }}/post/edit/{{ $post['id'] }}" class="block font-medium text-sm mb-1 truncate">{{ $post['title'] }}</a>
                {% endif %}
                <div class="flex items-center gap-2 text-xs text-slate-400">
                    {% if $post['status'] === 'published' %}
                    <span class="text-emerald-500">{@ published @}</span>
                    {% elseif $post['status'] === 'scheduled' %}
                    <span class="text-amber-500">{@ scheduled @}</span>
                    {% else %}
                    <span>{@ draft @}</span>
                    {% endif %}
                    <span>{% if ($view ?? 'active') === 'trash' %}{{ Format::date($post['deleted_at'] ?? '') }}{% else %}{{ Format::date($post['published_at'] ?: $post['created_at']) }}{% endif %}</span>
                    <span class="inline-flex items-center gap-1"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>{{ $post['comment_count'] }}</span>
                </div>
            </div>
            {% if ($view ?? 'active') === 'trash' %}
            <div class="flex items-center gap-3 flex-shrink-0 text-xs">
                <form method="post" action="/{{ $adminPath }}/post/restore">
    {{{ Security::csrfField() }}}
                    <input type="hidden" name="id" value="{{ $post['id'] }}">
                    <button type="submit" class="text-emerald-500">{@ restore @}</button>
                </form>
                <form method="post" action="/{{ $adminPath }}/post/purge" onsubmit="return confirm('{@ confirm_purge @}')">
    {{{ Security::csrfField() }}}
                    <input type="hidden" name="id" value="{{ $post['id'] }}">
                    <button type="submit" class="text-rose-400">{@ delete_permanently @}</button>
                </form>
            </div>
            {% else %}
            <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            {% endif %}
        </div>
    </div>
    {% endforeach %}
    {% if count($posts) === 0 %}
    <p class="text-center text-slate-400 py-10">{% if ($view ?? 'active') === 'trash' %}{@ trash_empty @}{% else %}{@ no_posts_yet @}{% endif %}</p>
    {% endif %}
</div>

<!-- pagination -->
{% if ($pages ?? 1) > 1 %}
<div class="mt-4 flex items-center justify-center gap-3 text-sm">
    {% if ($page ?? 1) > 1 %}
    <a href="/{{ $adminPath }}/posts?{{ $baseQuery }}&page={{ ($page ?? 1) - 1 }}"
       class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">{@ pager_prev @}</a>
    {% endif %}
    <span class="text-slate-500">{{ $page }} / {{ $pages }}</span>
    {% if ($page ?? 1) < ($pages ?? 1) %}
    <a href="/{{ $adminPath }}/posts?{{ $baseQuery }}&page={{ ($page ?? 1) + 1 }}"
       class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">{@ pager_next @}</a>
    {% endif %}
</div>
{% endif %}

<!-- Clear-FPC button (only for holders of the maintenance:run permission) -->
{% if Gate::can('maintenance:run') %}
<div class="mt-6 flex justify-end">
    <form method="post" action="/{{ $adminPath }}/cache/clear">
    {{{ Security::csrfField() }}}
        <button type="submit" class="text-xs text-slate-400 hover:text-rose-500 transition-colors">
            {@ clear_all_cache @}
        </button>
    </form>
</div>
{% endif %}

{% include 'admin/_layout_foot' %}
