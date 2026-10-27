<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '{@ theme_review_title @}'; ?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-2">{@ theme_review_title @}</h2>
<p class="text-sm text-slate-500 mb-6">{@ plugin_review_desc @}</p>
{% if $_GET['error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 text-sm">{{ $_GET['error'] }}</div>{% endif %}

<div class="mb-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 text-sm">
    <h3 class="font-medium text-base">{{ $review['title'] }} <span class="text-slate-500 font-normal">(themes/{{ $review['name'] }}/)</span></h3>
    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
        <dt class="text-slate-500">{@ plugin_review_version @}</dt>
        <dd>{% if $review['current'] %}{{ $review['current']['version'] }} → {% endif %}<strong>{{ $review['version'] }}</strong>{% if !$review['current'] %} <span class="text-slate-500">{@ plugin_review_new @}</span>{% endif %}</dd>
        <dt class="text-slate-500">{@ plugin_review_author @}</dt>
        <dd>{% if $review['current'] && $review['current']['author'] !== $review['author'] %}{{ $review['current']['author'] }} → {% endif %}{{ $review['author'] }}</dd>
        <dt class="text-slate-500">{@ plugin_review_source @}</dt>
        <dd>ZIP · {{ $review['created_at'] }}{% if $review['active'] %} · <strong>{@ theme_review_active @}</strong>{% endif %}</dd>
    </dl>
</div>

{% if $review['syntax_errors'] %}
<div class="mb-6 p-4 rounded-2xl bg-rose-50 dark:bg-rose-950/40 text-sm text-rose-700 dark:text-rose-200">
    <p class="font-medium mb-2">{@ plugin_review_syntax @}</p>
    <ul class="list-disc pl-5 space-y-1 text-xs">
        {% foreach $review['syntax_errors'] as $err %}<li><code>{{ $err['file'] }}:{{ $err['line'] }}</code> {{ $err['message'] }}</li>{% endforeach %}
    </ul>
</div>
{% endif %}

<div class="mb-6">
    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
        <h4 class="font-medium text-sm mb-2">{@ plugin_review_files @}
            <span class="font-normal text-slate-500">+{{ count($review['file_diff']['added']) }} −{{ count($review['file_diff']['removed']) }} ~{{ count($review['file_diff']['changed']) }}</span></h4>
        <ul class="text-xs space-y-0.5 font-mono break-all max-h-64 overflow-y-auto">
            {% foreach $review['file_diff']['added'] as $f %}<li class="text-emerald-700 dark:text-emerald-400">+ {{ $f }}</li>{% endforeach %}
            {% foreach $review['file_diff']['removed'] as $f %}<li class="text-rose-600">− {{ $f }}</li>{% endforeach %}
            {% foreach $review['file_diff']['changed'] as $f %}<li class="text-amber-700 dark:text-amber-300">~ {{ $f }}</li>{% endforeach %}
        </ul>
    </div>
</div>

<div class="mb-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 text-sm">
    <h4 class="font-medium mb-1">{@ scan_title @}</h4>
    <p class="text-xs text-slate-500 mb-3">{@ scan_disclaimer @}</p>
    {% if !$review['scan'] %}<p class="text-xs text-slate-500">{@ scan_nothing @}</p>{% endif %}
    <ul class="text-xs space-y-1">
        {% foreach $review['scan'] as $f %}{% if $f['level'] === 'warning' && $f['new'] %}
        <li class="text-rose-700 dark:text-rose-300"><strong>{@ scan_new @}</strong> {{ $f['label'] }} — <code>{{ $f['file'] }}:{{ $f['line'] }}</code> {{ $f['detail'] }}</li>
        {% endif %}{% endforeach %}
    </ul>
    <details class="mt-2">
        <summary class="text-xs text-slate-500 cursor-pointer">{@ scan_all @} ({{ count($review['scan']) }})</summary>
        <ul class="mt-2 text-xs space-y-1">
            {% foreach $review['scan'] as $f %}
            <li class="{{ $f['level'] === 'warning' ? 'text-amber-700 dark:text-amber-300' : 'text-slate-500' }}">{{ $f['label'] }} — <code>{{ $f['file'] }}:{{ $f['line'] }}</code> {{ $f['detail'] }}</li>
            {% endforeach %}
        </ul>
    </details>
</div>

{% if !$review['syntax_errors'] %}
{% if $installGate['open'] %}
{% include 'admin/_reauth' %}
<form method="post" action="/{{ $adminPath }}/theme/apply" class="mb-4 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-3">
    {{{ Security::csrfField() }}}
    <input type="hidden" name="token" value="{{ $review['token'] }}">
    {% if $review['active'] %}<p class="text-xs text-slate-500">{@ theme_review_active_note @}</p>{% endif %}
    <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">{@ plugin_apply_btn @}</button>
</form>
{% else %}
<div class="mb-4 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
    {% include 'admin/_install_gate' %}
</div>
{% endif %}
{% endif %}

<form method="post" action="/{{ $adminPath }}/theme/discard">
    {{{ Security::csrfField() }}}
    <input type="hidden" name="token" value="{{ $review['token'] }}">
    <button type="submit" class="text-sm px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-rose-50 hover:text-rose-500 transition-colors">{@ plugin_discard_btn @}</button>
</form>

{% include 'admin/_layout_foot' %}
