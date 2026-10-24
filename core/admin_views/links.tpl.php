<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
$pageTitle  = '{@ links @}';
$inputClass = 'w-full text-sm px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500';
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$errRow = (string)($_GET['row'] ?? '');
?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-2">{@ links @}</h2>
<p class="text-xs text-slate-500 mb-6">{@ links_hint @}</p>
{% if $_GET['saved'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}
<?php if (($_GET['error'] ?? '') === 'duplicate'): ?>
<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ link_duplicate @}</div>
<?php elseif (!empty($_GET['error'])): ?>
<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ link_invalid @}</div>
<?php endif; ?>

<form method="post" action="/{{ $adminPath }}/links/save" class="space-y-3">
    {{{ Security::csrfField() }}}

    <?php foreach ($links as $l): $id = (int)$l['id']; $name = 'links[' . $id . ']'; ?>
    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-2<?= $errRow === (string)$id ? ' bg-rose-50 dark:bg-rose-900/20' : '' ?>">
        <div class="flex items-center gap-2">
            <span class="inline-block px-2 py-0.5 text-xs rounded-lg bg-slate-100 dark:bg-slate-800 font-mono">/go/<?= $e($l['alias']) ?></span>
            <span class="flex-1"></span>
            <label class="flex items-center gap-1 text-xs text-rose-500 cursor-pointer">
                <input type="checkbox" name="<?= $name ?>[delete]" value="1"> {@ widget_delete @}
            </label>
        </div>
        <div class="grid md:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ link_alias @}</label>
                <input type="text" name="<?= $name ?>[alias]" value="<?= $e($l['alias']) ?>" maxlength="64" class="<?= $inputClass ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ link_note @}</label>
                <input type="text" name="<?= $name ?>[note]" value="<?= $e($l['note'] ?? '') ?>" maxlength="255" class="<?= $inputClass ?>">
            </div>
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">{@ link_target @}</label>
            <input type="url" name="<?= $name ?>[target_url]" value="<?= $e($l['target_url']) ?>" maxlength="2048" class="<?= $inputClass ?>">
        </div>
    </div>
    <?php endforeach; ?>

    <!-- New link -->
    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-2<?= $errRow === 'new' ? ' bg-rose-50 dark:bg-rose-900/20' : '' ?>">
        <p class="text-sm font-medium">{@ link_new @}</p>
        <div class="grid md:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ link_alias @}</label>
                <input type="text" name="new_alias" maxlength="64" placeholder="partner-signup" class="<?= $inputClass ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ link_note @}</label>
                <input type="text" name="new_note" maxlength="255" class="<?= $inputClass ?>">
            </div>
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">{@ link_target @}</label>
            <input type="url" name="new_target_url" maxlength="2048" placeholder="https://" class="<?= $inputClass ?>">
        </div>
    </div>

    <button type="submit" class="text-sm px-6 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl transition-colors">{@ save @}</button>
</form>

{% include 'admin/_layout_foot' %}
