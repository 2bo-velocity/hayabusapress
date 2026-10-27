<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '{@ integrity_title @}'; ?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-2">{@ integrity_title @}</h2>
{% if $_GET['error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 text-sm">{{ $_GET['error'] }}</div>{% endif %}
<p class="text-sm text-slate-500 mb-2">{@ integrity_desc @}</p>
<p class="text-xs text-slate-500 mb-6">
    {% if $integrity['checked_at'] %}{@ integrity_checked_at @} {{ $integrity['checked_at'] }}{% else %}{@ integrity_never @}{% endif %}
    {% if $integrity['core']['version'] %} · {@ integrity_release @} {{ $integrity['core']['version'] }}{% endif %}
    {% if $integrity['initialized_at'] %} · {@ integrity_since @} {{ $integrity['initialized_at'] }}{% endif %}
</p>

<form method="post" action="/{{ $adminPath }}/integrity/check" class="mb-6">
    {{{ Security::csrfField() }}}
    <button type="submit" class="text-sm px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors">{@ integrity_check_now @}</button>
</form>

<!-- Plugins -->
<h3 class="font-medium mb-3">{@ plugins @}</h3>
{% if !$integrity['plugins'] %}<p class="mb-6 text-sm text-slate-500">{@ integrity_plugins_ok @}</p>{% endif %}
{% foreach $integrity['plugins'] as $pid => $p %}
<div class="mb-4 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 text-sm">
    <div class="flex items-start justify-between gap-4">
        <div>
            <p class="font-medium">{{ $pid }}
                {% if $p['disabled'] === 'parse_error' %}<span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-900/40 text-rose-700">{@ integrity_stopped_syntax @}</span>
                {% elseif $p['disabled'] %}<span class="ml-2 text-xs px-2 py-0.5 rounded-full bg-rose-100 dark:bg-rose-900/40 text-rose-700">{@ integrity_stopped @}</span>{% endif %}
            </p>
            <p class="text-xs text-slate-500 mt-0.5">{@ integrity_since_label @} {{ $p['since'] }}{% if $p['no_record'] %} · {@ integrity_no_record @}{% endif %}</p>
        </div>
        <form method="post" action="/{{ $adminPath }}/integrity/ack-plugin" onsubmit="return confirm('{@ integrity_ack_confirm @}')">
            {{{ Security::csrfField() }}}
            <input type="hidden" name="name" value="{{ $pid }}">
            <button type="submit" class="text-sm px-4 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 hover:bg-emerald-100 font-medium transition-colors">{@ integrity_ack @}</button>
        </form>
    </div>
    <ul class="mt-3 text-xs font-mono space-y-0.5 break-all max-h-64 overflow-y-auto">
        {% foreach $p['diff']['added'] as $f %}<li class="text-emerald-700 dark:text-emerald-400">+ {{ $f }}</li>{% endforeach %}
        {% foreach $p['diff']['removed'] as $f %}<li class="text-rose-600">− {{ $f }}</li>{% endforeach %}
        {% foreach $p['diff']['changed'] as $f %}<li class="text-amber-700 dark:text-amber-300">~ {{ $f }}</li>{% endforeach %}
    </ul>
    {% foreach $p['syntax'] as $e %}<p class="mt-2 text-xs text-rose-700"><code>{{ $e['file'] }}:{{ $e['line'] }}</code> {{ $e['message'] }}</p>{% endforeach %}
    {% if $p['scan'] %}
    <p class="mt-3 text-xs font-medium">{@ scan_in_changed @}</p>
    <ul class="mt-1 text-xs space-y-0.5">
        {% foreach $p['scan'] as $f %}<li class="{{ $f['level'] === 'warning' ? 'text-amber-700 dark:text-amber-300' : 'text-slate-500' }}">{{ $f['label'] }} — <code>{{ $f['file'] }}:{{ $f['line'] }}</code> {{ $f['detail'] }}</li>{% endforeach %}
    </ul>
    {% endif %}
</div>
{% endforeach %}

<!-- Core -->
<h3 class="font-medium mt-8 mb-3">{@ integrity_core @}</h3>
{% if $integrity['core']['list'] !== 'ok' %}
<p class="mb-6 text-sm text-amber-700 dark:text-amber-300">{@ integrity_list_missing @}</p>
{% elseif !$integrity['core']['changed'] && !$integrity['core']['missing'] && !$integrity['core']['unknown'] %}
<p class="mb-6 text-sm text-slate-500">{@ integrity_core_ok @}</p>
{% else %}
<div class="mb-4 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 text-sm">
    <ul class="text-xs font-mono space-y-0.5 break-all max-h-80 overflow-y-auto">
        {% foreach $integrity['core']['changed'] as $f %}<li class="text-amber-700 dark:text-amber-300">~ {{ $f }}</li>{% endforeach %}
        {% foreach $integrity['core']['missing'] as $f %}<li class="text-rose-600">− {{ $f }}</li>{% endforeach %}
        {% foreach $integrity['core']['unknown'] as $f %}<li class="text-rose-700 font-bold">? {{ $f }}</li>{% endforeach %}
    </ul>
    <p class="mt-3 text-xs text-slate-500">{@ integrity_core_legend @}</p>
    <form method="post" action="/{{ $adminPath }}/integrity/ack-core" class="mt-3" onsubmit="return confirm('{@ integrity_ack_confirm @}')">
        {{{ Security::csrfField() }}}
        <button type="submit" class="text-sm px-4 py-1.5 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 hover:bg-emerald-100 font-medium transition-colors">{@ integrity_ack @}</button>
    </form>
</div>
{% endif %}

<p class="mt-8 text-xs text-slate-500">{@ integrity_limits @}</p>

{% include 'admin/_layout_foot' %}
