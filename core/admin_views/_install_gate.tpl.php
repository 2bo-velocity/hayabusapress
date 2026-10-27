<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php /* Shown in place of an install form while its .env switch is closed (adminGateView() in public/admin/index.php) */ ?>
<p class="text-sm text-slate-600 dark:text-slate-300">{{ $installGate['closed'] }}</p>
{% if $installGate['reason'] %}<p class="mt-1 text-sm text-amber-700 dark:text-amber-300">{{ $installGate['reason'] }}</p>{% endif %}
<p class="mt-2 text-xs text-slate-500">{@ gate_open_hint @}</p>
<pre class="mt-1 px-3 py-2 rounded-xl bg-slate-100 dark:bg-slate-900 text-xs overflow-x-auto"><code>{{ $installGate['line'] }}</code></pre>
