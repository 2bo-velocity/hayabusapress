<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
$pageTitle  = '{@ post_templates @}';
$inputClass = 'w-full text-sm px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500';
$monoClass  = 'w-full text-xs px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white font-mono focus:outline-none focus:ring-2 focus:border-emerald-500';
$e   = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$cur = $editing ?? ['id' => 0, 'name' => '', 'type' => 'post', 'lang' => array_key_first($contentLocales), 'title' => '', 'body' => '',
                    'category_id' => null, 'tags' => '', 'layout' => null, 'show_toc' => 0, 'is_sponsored' => 0];
?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-2">{@ post_templates @}</h2>
<p class="text-xs text-slate-500 mb-6">{@ post_templates_hint @}</p>
{% if $_GET['saved'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}
{% if $_GET['error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ template_invalid @}</div>{% endif %}

<div class="grid md:grid-cols-2 gap-6">
    <div class="space-y-2">
        <a href="/{{ $adminPath }}/templates" class="inline-block text-sm px-4 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-xl transition-colors">{@ template_new @}</a>
        <?php if (!$templates): ?><p class="text-sm text-slate-400">{@ template_none @}</p><?php endif; ?>
        <?php foreach ($templates as $t): ?>
        <div class="flex items-center gap-2 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl px-3 py-2">
            <a href="/{{ $adminPath }}/templates?id=<?= (int)$t['id'] ?>" class="text-sm font-medium hover:text-emerald-700"><?= $e($t['name']) ?></a>
            <span class="text-xs text-slate-400"><?= $e(strtoupper((string)$t['lang'])) ?> · <?= $e($t['type']) ?></span>
            <span class="flex-1"></span>
            <a href="/{{ $adminPath }}/post/new?template=<?= (int)$t['id'] ?>" class="text-xs text-emerald-600 hover:text-emerald-700">{@ template_use @}</a>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="space-y-3">
        <form method="post" action="/{{ $adminPath }}/templates/save" class="space-y-3">
            {{{ Security::csrfField() }}}
            <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ template_name @}</label>
                <input type="text" name="name" value="<?= $e($cur['name']) ?>" maxlength="255" required class="<?= $inputClass ?>">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ post_type @}</label>
                    <select name="type" class="<?= $inputClass ?>">
                        <option value="post">{@ post @}</option>
                        <option value="page" <?= $cur['type'] === 'page' ? 'selected' : '' ?>>{@ page @}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ content_language @}</label>
                    <select name="lang" class="<?= $inputClass ?>">
                        <?php foreach ($contentLocales as $lc => $ll): ?>
                        <option value="<?= $e($lc) ?>" <?= $cur['lang'] === $lc ? 'selected' : '' ?>><?= $e($ll) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ template_title @}</label>
                <input type="text" name="title" value="<?= $e($cur['title']) ?>" maxlength="255" class="<?= $inputClass ?>">
            </div>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ template_body @}</label>
                <textarea name="body" rows="14" class="<?= $monoClass ?>"><?= $e($cur['body']) ?></textarea>
                <p class="text-xs text-slate-400 mt-1">{@ template_placeholder_hint @}</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ category @}</label>
                    <select name="category_id" class="<?= $inputClass ?>">
                        <option value="">—</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= (int)($cur['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= $e($c['name']) ?> (<?= $e(strtoupper((string)$c['lang'])) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ template_tags @}</label>
                    <input type="text" name="tags" value="<?= $e($cur['tags'] ?? '') ?>" placeholder="tag-a, tag-b" class="<?= $inputClass ?>">
                </div>
            </div>
            <?php if (count($layouts) > 1): ?>
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ layout @}</label>
                <select name="layout" class="<?= $inputClass ?>">
                    <option value="">—</option>
                    <?php foreach ($layouts as $lid => $llabel): ?>
                    <option value="<?= $e($lid) ?>" <?= ($cur['layout'] ?? '') === $lid ? 'selected' : '' ?>><?= $e($llabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <label class="flex items-center gap-2 text-sm cursor-pointer"><input type="checkbox" name="show_toc" value="1" <?= !empty($cur['show_toc']) ? 'checked' : '' ?>> {@ show_toc @}</label>
            <label class="flex items-center gap-2 text-sm cursor-pointer"><input type="checkbox" name="is_sponsored" value="1" <?= !empty($cur['is_sponsored']) ? 'checked' : '' ?>> {@ is_sponsored @}</label>
            <button type="submit" class="text-sm px-6 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl transition-colors">{@ save @}</button>
        </form>
        <?php if ($editing): ?>
        <form method="post" action="/{{ $adminPath }}/templates/delete">
            {{{ Security::csrfField() }}}
            <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
            <button type="submit" class="text-xs text-rose-500 hover:text-rose-700 font-medium transition-colors">{@ delete @}</button>
        </form>
        <?php endif; ?>
    </div>
</div>

{% include 'admin/_layout_foot' %}
