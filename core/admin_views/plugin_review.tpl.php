<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '{@ plugin_review_title @}'; ?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-2">{@ plugin_review_title @}</h2>
<p class="text-sm text-slate-500 mb-6">{@ plugin_review_desc @}</p>
{% if $_GET['error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 text-sm">{{ $_GET['error'] }}</div>{% endif %}

<div class="mb-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 text-sm">
    <h3 class="font-medium text-base">{{ $review['name'] }} <span class="text-slate-500 font-normal">({{ $review['id'] }})</span></h3>
    <dl class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1">
        <dt class="text-slate-500">{@ plugin_review_version @}</dt>
        <dd>{% if $review['current'] %}{{ $review['current']['version'] }} → {% endif %}<strong>{{ $review['version'] }}</strong>{% if !$review['current'] %} <span class="text-slate-500">{@ plugin_review_new @}</span>{% endif %}</dd>
        <dt class="text-slate-500">{@ plugin_review_author @}</dt>
        <dd>{% if $review['current'] && $review['current']['author'] !== $review['author'] %}{{ $review['current']['author'] }} → {% endif %}{{ $review['author'] }}</dd>
        <dt class="text-slate-500">{@ plugin_review_license @}</dt>
        <dd>{{ $review['license'] !== '' ? $review['license'] : '—' }}</dd>
        <dt class="text-slate-500">{@ plugin_review_signature @}</dt>
        <dd>
            {% if $review['signature']['state'] === 'signed' %}<span class="text-emerald-600">{@ plugin_sig_signed @} ({{ $review['signature']['key'] }}…)</span>
            {% elseif $review['signature']['state'] === 'untrusted' %}<span class="text-amber-700 dark:text-amber-300">{@ plugin_sig_untrusted @}</span>
            {% else %}{@ plugin_sig_unsigned @}{% endif %}
        </dd>
        <dt class="text-slate-500">{@ plugin_review_source @}</dt>
        <dd>{% if $review['source'] === 'incoming' %}plugins/_incoming/{{ $review['id'] }}/{% else %}ZIP{% endif %} · {{ $review['created_at'] }}</dd>
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

<div class="mb-6 grid gap-4 md:grid-cols-2">
    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
        <h4 class="font-medium text-sm mb-2">{@ plugin_review_declarations @}</h4>
        {% if !$review['manifest_diff']['added'] && !$review['manifest_diff']['removed'] %}<p class="text-xs text-slate-500">{@ plugin_review_no_change @}</p>{% endif %}
        <ul class="text-xs space-y-0.5 font-mono break-all">
            {% foreach $review['manifest_diff']['added'] as $line %}<li class="text-emerald-700 dark:text-emerald-400">+ {{ $line }}</li>{% endforeach %}
            {% foreach $review['manifest_diff']['removed'] as $line %}<li class="text-rose-600">− {{ $line }}</li>{% endforeach %}
        </ul>
    </div>
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
<form method="post" action="/{{ $adminPath }}/plugin/apply" class="mb-4 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-3">
    {{{ Security::csrfField() }}}
    <input type="hidden" name="token" value="{{ $review['token'] }}">
    {% if in_array('author', $review['requires'], true) %}
    <label class="flex items-start gap-2 text-sm text-amber-800 dark:text-amber-200"><input type="checkbox" name="consent[]" value="author" required class="mt-1"> <span>{@ plugin_consent_author @}</span></label>
    {% endif %}
    {% if in_array('signer', $review['requires'], true) %}
    <label class="flex items-start gap-2 text-sm text-amber-800 dark:text-amber-200"><input type="checkbox" name="consent[]" value="signer" required class="mt-1"> <span>{@ plugin_consent_signer @}</span></label>
    {% endif %}
    {% if in_array('version', $review['requires'], true) %}
    <label class="flex items-start gap-2 text-sm text-amber-800 dark:text-amber-200"><input type="checkbox" name="consent[]" value="version" required class="mt-1"> <span>{@ plugin_consent_version @}</span></label>
    {% endif %}
    {% if $review['active'] %}<p class="text-xs text-slate-500">{@ plugin_review_active_note @}</p>{% endif %}
    {% if $review['content_render'] %}<p class="text-xs text-amber-700 dark:text-amber-300">{@ plugin_recompile_needed @}</p>{% endif %}
    <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">{@ plugin_apply_btn @}</button>
</form>
{% else %}
<div class="mb-4 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
    {% include 'admin/_install_gate' %}
    <p class="mt-2 text-xs text-slate-500">{@ plugin_apply_cli_hint @} <code>php core/cli.php plugins:apply {{ $review['id'] }}</code></p>
</div>
{% endif %}
{% endif %}

<form method="post" action="/{{ $adminPath }}/plugin/discard">
    {{{ Security::csrfField() }}}
    <input type="hidden" name="token" value="{{ $review['token'] }}">
    <button type="submit" class="text-sm px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-rose-50 hover:text-rose-500 transition-colors">{@ plugin_discard_btn @}</button>
</form>

{% include 'admin/_layout_foot' %}
