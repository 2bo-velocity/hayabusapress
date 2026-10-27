<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '{@ upg_title @}'; ?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-2">{@ upg_title @}</h2>
<p class="text-sm text-slate-500 mb-2">{@ upg_desc @}</p>
<p class="text-xs text-slate-500 mb-6">{@ upg_cli_hint @} <code>php core/cli.php db:migrate</code> · <code>php core/cli.php posts:recompile</code> · <code>php core/cli.php system:init</code></p>
{% if $_GET['error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 text-sm">{{ $_GET['error'] }}</div>{% endif %}
{% if $_GET['notice'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{{ $_GET['notice'] }}</div>{% endif %}

<!-- 1. Checks -->
<section class="mb-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
    <h3 class="font-medium mb-3">1. {@ upg_step_check @}</h3>
    <ul class="space-y-1 text-sm">
        {% foreach $preflight['checks'] as $c %}
        <li class="flex gap-2">
            {% if $c['state'] === 'ok' %}<span class="text-emerald-600 w-5">✓</span>
            {% elseif $c['state'] === 'fail' %}<span class="text-rose-600 w-5">✗</span>
            {% elseif $c['state'] === 'warn' %}<span class="text-amber-600 w-5">!</span>
            {% else %}<span class="text-slate-400 w-5">?</span>{% endif %}
            <span>{{ $c['label'] }}{% if $c['detail'] %} <span class="text-slate-500">— {{ $c['detail'] }}</span>{% endif %}</span>
        </li>
        {% endforeach %}
    </ul>
</section>

<!-- 2. Opcode cache -->
<section class="mb-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
    <h3 class="font-medium mb-1">2. {@ upg_step_opcache @}</h3>
    <p class="text-xs text-slate-500 mb-3">{@ upg_opcache_desc @}
        {% if $opcache['enabled'] %}(opcache.validate_timestamps={{ $opcache['validate'] }}){% else %}({@ upg_opcache_off @}){% endif %}</p>
    <form method="post" action="/{{ $adminPath }}/maintenance/opcache">
        {{{ Security::csrfField() }}}
        <button type="submit" class="text-sm px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors">{@ upg_opcache_btn @}</button>
    </form>
</section>

<!-- 3. Database -->
<section class="mb-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
    <h3 class="font-medium mb-1">3. {@ upg_step_migrate @}</h3>
    <p class="text-sm mb-3">{@ upg_db_version @} <code>{{ $dbVersion }}</code> · {@ upg_code_version @} <code>{{ $codeVersion }}</code></p>
    {% if $upgradeLog %}<pre class="mb-3 p-3 rounded-xl bg-slate-100 dark:bg-slate-900 text-xs overflow-x-auto">{% foreach $upgradeLog as $line %}{{ $line }}
{% endforeach %}</pre>{% endif %}
    {% if !$pending %}
    <p class="text-sm text-emerald-600">{@ upg_up_to_date @}</p>
    {% else %}
    <p class="text-sm mb-2">{@ upg_pending @}</p>
    <ul class="mb-3 text-xs font-mono">{% foreach $pending as $m %}<li>{{ $m['version'] }} {{ $m['name'] }}</li>{% endforeach %}</ul>
    {% if $preflight['blocking'] %}
    <p class="text-sm text-rose-600">{@ err_migrate_preflight @}</p>
    {% elseif $migrateGate['open'] %}
    {% include 'admin/_reauth' %}
    <form method="post" action="/{{ $adminPath }}/maintenance/migrate" class="space-y-3">
        {{{ Security::csrfField() }}}
        <label class="flex items-start gap-2 text-sm text-amber-800 dark:text-amber-200"><input type="checkbox" name="backup_done" value="1" required class="mt-1"> <span>{@ upg_backup_confirm @}</span></label>
        <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">{@ upg_migrate_btn @}</button>
    </form>
    {% else %}
    {% include 'admin/_install_gate' %}
    {% endif %}
    {% endif %}
</section>

<!-- 4. Posts -->
<section class="mb-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
    <h3 class="font-medium mb-1">4. {@ upg_step_recompile @}</h3>
    <p class="text-xs text-slate-500 mb-3">{@ upg_recompile_desc @}</p>
    <form id="recompileForm" data-url="/{{ $adminPath }}/maintenance/recompile" data-resume="{{ $recompileFrom }}" class="space-y-3">
        {{{ Security::csrfField() }}}
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" id="recompileReassess"> {@ upg_reassess @}</label>
        <div class="flex flex-wrap gap-2">
            <button type="button" id="recompileStart" class="text-sm px-4 py-2 rounded-xl border border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-900 disabled:opacity-50 disabled:cursor-wait transition-colors">{@ upg_recompile_btn @}</button>
            {% if $recompileFrom > 0 %}<button type="button" id="recompileResume" class="text-sm px-4 py-2 rounded-xl border border-amber-300 text-amber-700 hover:bg-amber-50 disabled:opacity-50 disabled:cursor-wait transition-colors">{@ upg_recompile_resume @} (id &gt; {{ $recompileFrom }})</button>{% endif %}
        </div>
        <div class="h-2 rounded-full bg-slate-100 dark:bg-slate-800 overflow-hidden"><div id="recompileBar" class="h-2 bg-emerald-500 w-0"></div></div>
        <p id="recompileStatus" class="text-sm text-slate-500" aria-live="polite"></p>
        <ul id="recompileFailed" class="text-xs text-rose-600 font-mono"></ul>
    </form>
</section>

<!-- 5. Caches -->
<section class="mb-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
    <h3 class="font-medium mb-1">5. {@ upg_step_caches @}</h3>
    <p class="text-xs text-slate-500 mb-3">{@ upg_caches_desc @}</p>
    <form method="post" action="/{{ $adminPath }}/maintenance/run">
        {{{ Security::csrfField() }}}
        <input type="hidden" name="task" value="all">
        <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">{@ upg_caches_btn @}</button>
    </form>
</section>

<script>
(function () {
    var form = document.getElementById('recompileForm');
    if (!form) return;
    var url = form.getAttribute('data-url');
    var token = form.querySelector('input[name="_csrf_token"]').value;
    var bar = document.getElementById('recompileBar');
    var status = document.getElementById('recompileStatus');
    var failedList = document.getElementById('recompileFailed');
    var start = document.getElementById('recompileStart');
    var resume = document.getElementById('recompileResume');
    var after = 0, done = 0, total = 0, tries = 0, failures = 0;

    function show(text) { status.textContent = text; }
    function setBusy(busy) { start.disabled = busy; if (resume) resume.disabled = busy; }

    function step(first) {
        var fd = new FormData();
        fd.append('_csrf_token', token);
        fd.append('after_id', String(after));
        if (first && after === 0 && document.getElementById('recompileReassess').checked) fd.append('reassess', '1');
        fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function (d) {
                tries = 0;
                if (typeof d.total === 'number') total = d.total;
                done += d.processed;
                after = d.last_id;
                Object.keys(d.failed || {}).forEach(function (id) {
                    failures++;
                    var li = document.createElement('li');
                    li.textContent = 'post ' + id + ': ' + d.failed[id];
                    failedList.appendChild(li);
                });
                if (total > 0) bar.style.width = Math.min(100, Math.round(done * 100 / total)) + '%';
                show(done + (total > 0 ? ' / ' + total : '') + ' {@ upg_recompile_progress @}');
                if (d.done) {
                    bar.style.width = '100%';
                    show(done + ' {@ upg_recompile_done @}' + (failures ? ' — ' + failures + ' {@ upg_recompile_failed @}' : ''));
                    setBusy(false);
                    if (resume) resume.remove();
                } else {
                    step(false);
                }
            })
            .catch(function () {
                if (++tries <= 3) {
                    show('{@ upg_recompile_retry @}');
                    setTimeout(function () { step(false); }, 2000 * tries);
                } else {
                    show('{@ upg_recompile_stopped @} (id > ' + after + ')');
                    setBusy(false);
                    start.textContent = '{@ upg_recompile_resume @}';
                    start.onclick = function () { setBusy(true); tries = 0; step(false); };
                }
            });
    }

    start.addEventListener('click', function () {
        if (start.onclick) return;   // after a stop, the button resumes (see above)
        setBusy(true); after = 0; done = 0; failures = 0; failedList.textContent = ''; step(true);
    });
    if (resume) resume.addEventListener('click', function () {
        setBusy(true); after = parseInt(form.getAttribute('data-resume'), 10) || 0; step(false);
    });
})();
</script>

{% include 'admin/_layout_foot' %}
