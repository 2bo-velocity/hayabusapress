<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '404 Not Found'; ?>
{% include 'admin/_layout_head' %}
<div class="flex flex-col items-center justify-center py-24 text-center">
    <p class="text-6xl font-bold text-slate-200 dark:text-slate-800 mb-4">404</p>
    <p class="text-slate-500">{@ not_found_msg @}</p>
    <a href="/{{ $adminPath }}" class="mt-4 text-emerald-500 hover:underline text-sm">{@ back_to_dashboard @}</a>
</div>
{% include 'admin/_layout_foot' %}
