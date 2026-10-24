<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
$pageTitle  = '{@ speakers @}';
$inputClass = 'w-full text-sm px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500';
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-2">{@ speakers @}</h2>
<p class="text-xs text-slate-500 mb-6">{@ speakers_hint @}</p>
{% if $_GET['saved'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}
{% if $_GET['error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ speaker_invalid @}</div>{% endif %}

<div class="space-y-3 max-w-2xl">
    <?php foreach (array_merge($speakers, [['id' => 0, 'slug' => '', 'name' => '', 'avatar' => '', 'side' => 'left']]) as $sp): $new = (int)$sp['id'] === 0; ?>
    <form method="post" action="/{{ $adminPath }}/speakers/save" class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-2">
        {{{ Security::csrfField() }}}
        <input type="hidden" name="id" value="<?= (int)$sp['id'] ?>">
        <?php if ($new): ?><p class="text-sm font-medium">{@ speaker_new @}</p><?php else: ?>
        <p class="text-xs text-slate-500 font-mono">:::say <?= $e($sp['slug']) ?></p><?php endif; ?>
        <div class="grid md:grid-cols-2 gap-3">
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ speaker_slug @}</label>
                <input type="text" name="slug" value="<?= $e($sp['slug']) ?>" maxlength="64" pattern="[a-z0-9][a-z0-9\-]*" required class="<?= $inputClass ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ speaker_name @}</label>
                <input type="text" name="name" value="<?= $e($sp['name']) ?>" maxlength="255" required class="<?= $inputClass ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ speaker_avatar @}</label>
                <input type="text" name="avatar" value="<?= $e($sp['avatar'] ?? '') ?>" maxlength="255" placeholder="/assets/site/avatars/..." class="<?= $inputClass ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ speaker_side @}</label>
                <select name="side" class="<?= $inputClass ?>">
                    <option value="left">{@ speaker_side_left @}</option>
                    <option value="right" <?= $sp['side'] === 'right' ? 'selected' : '' ?>>{@ speaker_side_right @}</option>
                </select>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <button type="submit" class="text-sm px-5 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl transition-colors"><?= $new ? '{@ add @}' : '{@ save @}' ?></button>
            <?php if (!$new): ?>
            <button type="submit" formaction="/{{ $adminPath }}/speakers/delete" formnovalidate class="text-xs text-rose-500 hover:text-rose-700">{@ delete @}</button>
            <?php endif; ?>
        </div>
    </form>
    <?php endforeach; ?>
</div>

{% include 'admin/_layout_foot' %}
