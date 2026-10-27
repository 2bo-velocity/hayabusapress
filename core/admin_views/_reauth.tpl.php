<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php /* Password confirmation before installing or deleting code (adminReauthView() in public/admin/index.php) */ ?>
<div class="mb-6 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
    {% if $reauthOk %}
    <p class="flex items-center gap-2 text-sm text-emerald-600">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
        {{ $reauthStatus }}
    </p>
    {% else %}
    <h3 class="font-medium text-sm mb-1">{@ reauth_title @}</h3>
    <p class="text-xs text-slate-500 mb-3">{@ reauth_desc @}</p>
    <form method="post" action="/{{ $adminPath }}/reauth" class="flex flex-wrap items-end gap-3">
    {{{ Security::csrfField() }}}
        <input type="hidden" name="back" value="{{ $reauthBack }}">
        <label class="text-sm">
            <span class="block text-xs text-slate-500 mb-1">{@ reauth_password @}</span>
            <input type="password" name="reauth_password" required autocomplete="current-password"
                   class="px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-black focus:outline-none focus:ring-2 focus:border-emerald-500">
        </label>
        {% if $reauthCode %}
        <label class="text-sm">
            <span class="block text-xs text-slate-500 mb-1">{@ reauth_code @}</span>
            <input type="text" name="reauth_code" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code"
                   class="w-32 px-3 py-2 rounded-xl border border-slate-200 dark:border-slate-700 bg-white dark:bg-black focus:outline-none focus:ring-2 focus:border-emerald-500">
        </label>
        {% endif %}
        <button type="submit" class="bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">
            {@ reauth_submit @}
        </button>
    </form>
    {% endif %}
</div>
