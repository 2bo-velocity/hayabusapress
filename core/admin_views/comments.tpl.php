<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
$pageTitle = '{@ comments @}';
$tabs = ['pending' => '{@ pending @}', 'approved' => '{@ approved @}', 'spam' => '{@ spam @}', 'trash' => '{@ trash @}'];
?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-6">{@ comments @}</h2>

<!-- Status tabs (filters other than status are kept) -->
<div class="flex gap-2 mb-4 overflow-x-auto">
    {% foreach $tabs as $s => $label %}
    <a href="?status={{ $s }}{% if $tabQuery %}&{{ $tabQuery }}{% endif %}"
       class="px-3 py-1.5 rounded-lg text-sm whitespace-nowrap transition-colors {{ $status === $s ? 'bg-emerald-500 text-white' : 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200' }}">
        {{ $label }} ({{ $counts[$s] }})
    </a>
    {% endforeach %}
</div>

<!-- display while filtering by the linked content -->
{% if ($filters['post_id'] ?? 0) > 0 %}
<div class="mb-4 flex items-center gap-2 text-sm">
    <span class="px-3 py-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600">{@ comment_filtered_post @} #{{ $filters['post_id'] }}</span>
    <a href="?status={{ $status }}" class="text-slate-400 hover:text-slate-600">{@ reset_filter @}</a>
</div>
{% endif %}

<!-- filter bar (body is out of scope; unified design with the content listing) -->
<details class="mb-4 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl overflow-hidden" {% if ($filters['q_name'] ?? '') || ($filters['q_email'] ?? '') || ($filters['q_url'] ?? '') || ($filters['cre_from'] ?? '') || ($filters['cre_to'] ?? '') %}open{% endif %}>
    <summary class="px-4 py-3 cursor-pointer text-sm font-medium select-none">
        <svg class="w-4 h-4 inline-block align-text-bottom mr-1" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>{@ filter @}
    </summary>
    <form method="get" action="/{{ $adminPath }}/comments" class="px-4 pb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-sm">
        <input type="hidden" name="status" value="{{ $status }}">
        {% if ($filters['post_id'] ?? 0) > 0 %}<input type="hidden" name="post_id" value="{{ $filters['post_id'] }}">{% endif %}
        <div>
            <label class="block text-xs text-slate-500 mb-1">{@ comment_filter_name @}</label>
            <input type="text" name="q_name" value="{{ $filters['q_name'] }}"
                   class="w-full text-base px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">{@ comment_filter_email @}</label>
            <input type="text" name="q_email" value="{{ $filters['q_email'] }}"
                   class="w-full text-base px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">{@ comment_filter_website @}</label>
            <input type="text" name="q_url" value="{{ $filters['q_url'] }}"
                   class="w-full text-base px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
        </div>
        <div class="lg:col-span-3">
            <label class="block text-xs text-slate-500 mb-1">{@ created_period @}</label>
            <div class="flex items-center gap-1">
                <input type="date" name="cre_from" value="{{ $filters['cre_from'] }}" class="w-full text-base px-2 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white">
                <span class="text-slate-400">–</span>
                <input type="date" name="cre_to" value="{{ $filters['cre_to'] }}" class="w-full text-base px-2 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white">
            </div>
        </div>
        <div class="sm:col-span-2 lg:col-span-3 flex items-center gap-3">
            <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white font-medium px-4 py-2 rounded-xl transition-colors">{@ apply_filter @}</button>
            <a href="/{{ $adminPath }}/comments?status={{ $status }}" class="text-slate-400 hover:text-slate-600 px-3 py-2">{@ reset_filter @}</a>
        </div>
    </form>
</details>

<div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl overflow-hidden">
    {% if count($comments) === 0 %}
    <p class="text-center text-slate-400 py-10">{@ no_comments_admin @}</p>
    {% else %}
    <div class="divide-y divide-slate-100 dark:divide-slate-800">
        {% foreach $comments as $comment %}
        <div class="p-4">
            <div class="flex items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1 flex-wrap">
                        <span class="font-medium text-sm">{{ $comment['author_name'] }}</span>
                        {% if $comment['author_email'] %}<span class="text-xs text-slate-400">{{ $comment['author_email'] }}</span>{% endif %}
                        {% if $comment['author_url'] %}<a href="{{ $comment['author_url'] }}" target="_blank" rel="noopener noreferrer nofollow" class="text-xs text-emerald-500 hover:underline">{{ $comment['author_url'] }}</a>{% endif %}
                        <span class="text-xs text-slate-400">{{ Format::datetime($comment['created_at']) }}</span>
                    </div>
                    <p class="text-sm text-slate-600 dark:text-slate-300 mb-1">{{{ $comment['content'] }}}</p>
                    <a href="/post/{{ $comment['post_slug'] }}" target="_blank" class="text-xs text-emerald-500 hover:underline">{{ $comment['post_title'] }}</a>
                </div>
                <div class="flex flex-wrap gap-2 flex-shrink-0">
                    {% if $status !== 'approved' %}
                    <form method="post" action="/{{ $adminPath }}/comment/action">
    {{{ Security::csrfField() }}}
                        <input type="hidden" name="id"     value="{{ $comment['id'] }}">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="text-xs bg-emerald-100 dark:bg-emerald-900/30 text-emerald-600 px-2 py-1 rounded-lg hover:bg-emerald-200">{@ approve @}</button>
                    </form>
                    {% endif %}
                    {% if $status !== 'spam' %}
                    <form method="post" action="/{{ $adminPath }}/comment/action">
    {{{ Security::csrfField() }}}
                        <input type="hidden" name="id"     value="{{ $comment['id'] }}">
                        <input type="hidden" name="action" value="spam">
                        <button type="submit" class="text-xs bg-slate-100 dark:bg-slate-800 text-slate-500 px-2 py-1 rounded-lg hover:bg-slate-200">{@ spam @}</button>
                    </form>
                    {% endif %}
                    <form method="post" action="/{{ $adminPath }}/comment/action" onsubmit="return confirm('{@ confirm_delete @}')">
    {{{ Security::csrfField() }}}
                        <input type="hidden" name="id"     value="{{ $comment['id'] }}">
                        <input type="hidden" name="action" value="delete">
                        <button type="submit" class="text-xs bg-rose-50 dark:bg-rose-900/20 text-rose-500 px-2 py-1 rounded-lg hover:bg-rose-100">{@ delete @}</button>
                    </form>
                </div>
            </div>
        </div>
        {% endforeach %}
    </div>
    {% endif %}
</div>

<!-- pagination (unified with the content listing) -->
{% if ($pages ?? 1) > 1 %}
<div class="mt-4 flex items-center justify-center gap-3 text-sm">
    {% if ($page ?? 1) > 1 %}
    <a href="/{{ $adminPath }}/comments?{{ $baseQuery }}&page={{ ($page ?? 1) - 1 }}"
       class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">{@ pager_prev @}</a>
    {% endif %}
    <span class="text-slate-500">{{ $page }} / {{ $pages }}</span>
    {% if ($page ?? 1) < ($pages ?? 1) %}
    <a href="/{{ $adminPath }}/comments?{{ $baseQuery }}&page={{ ($page ?? 1) + 1 }}"
       class="px-3 py-1.5 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-100 dark:hover:bg-slate-800">{@ pager_next @}</a>
    {% endif %}
</div>
{% endif %}

{% include 'admin/_layout_foot' %}
