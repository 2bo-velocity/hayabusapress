<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php $pageTitle = '{@ users @}'; ?>
{% include 'admin/_layout_head' %}

<div class="flex items-center justify-between mb-6">
    <h2 class="text-xl font-bold">{@ users @}</h2>
    <button onclick="document.getElementById('createModal').classList.remove('hidden')"
            class="bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium px-4 py-2 rounded-xl transition-colors">
        {@ add_user @}
    </button>
</div>

{% if ($_GET['deleted'] ?? '') === '1' %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ user_deleted_ok @}</div>{% endif %}
{% if $_GET['saved'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}
{% if $_GET['msg'] %}<div class="mb-4 p-3 rounded-xl bg-slate-50 dark:bg-slate-900 text-slate-600 text-sm">{{ $_GET['msg'] }}</div>{% endif %}
{% if $_GET['error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 text-sm">{{ $_GET['error'] }}</div>{% endif %}

<div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500">
            <tr>
                <th class="text-left px-4 py-3 font-medium">{@ name @}</th>
                <th class="text-left px-4 py-3 font-medium">{@ email @}</th>
                <th class="text-left px-4 py-3 font-medium w-32">{@ role @}</th>
                <th class="text-left px-4 py-3 font-medium w-16">2FA</th>
                <th class="px-4 py-3 w-32"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            {% foreach $users as $user %}
            <tr class="hover:bg-slate-50 dark:hover:bg-slate-900/50">
                <td class="px-4 py-3 font-medium">{{ $user['name'] }}</td>
                <td class="px-4 py-3 text-slate-500">{{ $user['email'] }}</td>
                <td class="px-4 py-3 text-slate-500">{{ $user['role'] }}</td>
                <td class="px-4 py-3">
                    {% if $user['two_factor_secret'] %}
                    <span class="text-emerald-500 text-xs">ON</span>
                    {% else %}
                    <span class="text-slate-300 dark:text-slate-600 text-xs">OFF</span>
                    {% endif %}
                </td>
                <td class="px-4 py-3">
                    <div class="flex items-center gap-2">
                        {% if $authUser['id'] === 0 %}
                        <form method="post" action="/{{ $adminPath }}/user/2fa">
    {{{ Security::csrfField() }}}
                            <input type="hidden" name="user_id" value="{{ $user['id'] }}">
                            <input type="hidden" name="action" value="{{ $user['two_factor_secret'] ? 'force_off' : 'force_on' }}">
                            <button type="submit" class="text-xs text-slate-400 hover:text-slate-600">
                                {{ $user['two_factor_secret'] ? '{@ two_factor_force_off @}' : '{@ two_factor_force_on @}' }}
                            </button>
                        </form>
                        {% endif %}
                        <form method="post" action="/{{ $adminPath }}/user/delete" onsubmit="return confirm('{@ confirm_delete @}')">
    {{{ Security::csrfField() }}}
                            <input type="hidden" name="id" value="{{ $user['id'] }}">
                            <button type="submit" class="text-xs text-rose-400 hover:text-rose-600">{@ delete @}</button>
                        </form>
                    </div>
                </td>
            </tr>
            {% endforeach %}
        </tbody>
    </table>
</div>

<!-- Add-user modal -->
<div id="createModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-950 rounded-2xl shadow-xl w-full max-w-md p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold">{@ new_user_modal_title @}</h3>
            <button type="button" aria-label="{@ cancel @}" onclick="document.getElementById('createModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button>
        </div>
        <form method="post" action="/{{ $adminPath }}/user/save" class="space-y-3">
    {{{ Security::csrfField() }}}
            <input type="text" name="name" placeholder="{@ name @}" required
                   class="w-full px-4 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
            <input type="email" name="email" placeholder="{@ email @}" required
                   class="w-full px-4 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
            <input type="password" name="password" placeholder="{@ password @}" required
                   class="w-full px-4 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
            <select name="role" class="w-full px-4 py-2.5 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white text-sm focus:outline-none focus:ring-2 focus:border-emerald-500">
                <option value="author">{@ role_author @}</option>
                <option value="editor">{@ role_editor @}</option>
                <option value="administrator">{@ role_administrator @}</option>
                <option value="contributor">{@ role_contributor @}</option>
            </select>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="flex-1 bg-emerald-500 hover:bg-emerald-600 text-white font-medium py-2.5 rounded-xl transition-colors text-sm">{@ add_btn @}</button>
                <button type="button" onclick="document.getElementById('createModal').classList.add('hidden')" class="flex-1 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 py-2.5 rounded-xl transition-colors text-sm">{@ cancel @}</button>
            </div>
        </form>
    </div>
</div>

{% include 'admin/_layout_foot' %}
