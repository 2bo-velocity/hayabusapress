<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
$pageTitle  = '{@ blocks @}';
$inputClass = 'w-full text-sm px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500';
$monoClass  = 'w-full text-xs px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white font-mono focus:outline-none focus:ring-2 focus:border-emerald-500';
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$cur = $editing ?? ['id' => 0, 'slug' => '', 'lang' => array_key_first($contentLocales), 'title' => '', 'body' => ''];
?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-2">{@ blocks @}</h2>
<p class="text-xs text-slate-500 mb-6">{@ blocks_hint @}</p>
{% if $_GET['saved'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}
{% if $_GET['deleted'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ block_deleted @}</div>{% endif %}
<?php if (($_GET['error'] ?? '') === 'duplicate'): ?>
<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ block_duplicate @}</div>
<?php elseif (!empty($_GET['error'])): ?>
<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ block_invalid @}</div>
<?php endif; ?>

<div class="grid md:grid-cols-2 gap-6">
    <!-- List -->
    <div class="space-y-2">
        <a href="/{{ $adminPath }}/blocks" class="inline-block text-sm px-4 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-xl transition-colors">{@ block_new @}</a>
        <?php if (!$blocks): ?>
        <p class="text-sm text-slate-400">{@ block_none @}</p>
        <?php endif; ?>
        <?php foreach ($blocks as $b): ?>
        <a href="/{{ $adminPath }}/blocks?id=<?= (int)$b['id'] ?>"
           class="block bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-xl px-3 py-2 hover:bg-slate-100 dark:hover:bg-slate-900 transition-colors<?= (int)$cur['id'] === (int)$b['id'] ? ' bg-emerald-50 dark:bg-emerald-900/20' : '' ?>">
            <span class="text-sm font-medium"><?= $e($b['title']) ?></span>
            <span class="inline-block px-2 py-0.5 text-xs rounded-lg bg-slate-100 dark:bg-slate-800 font-mono"><?php /* braces split so the template compiler leaves them alone */ ?><?= '{' . '{ block:' . $e($b['slug']) . ' }' . '}' ?></span>
            <span class="text-xs text-slate-400"><?= $e(strtoupper((string)$b['lang'])) ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Editor -->
    <div class="space-y-3">
        <form method="post" action="/{{ $adminPath }}/blocks/save" class="space-y-3">
            {{{ Security::csrfField() }}}
            <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
            <div>
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ block_title @}</label>
                <input type="text" name="title" value="<?= $e($cur['title']) ?>" maxlength="255" required class="<?= $inputClass ?>">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ block_slug @}</label>
                    <input type="text" name="slug" value="<?= $e($cur['slug']) ?>" maxlength="64" pattern="[a-z0-9][a-z0-9\-]*" required placeholder="cta-main" class="<?= $inputClass ?>">
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
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ block_body @}</label>
                <textarea name="body" rows="10" maxlength="<?= BlockCompiler::BODY_MAX ?>" class="<?= $monoClass ?>"><?= $e($cur['body']) ?></textarea>
                <p class="text-xs text-slate-400 mt-1">{@ block_body_hint @}</p>
            </div>
            <button type="submit" class="text-sm px-6 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl transition-colors">{@ save @}</button>
        </form>

        <?php if ($editing): ?>
        <form method="post" action="/{{ $adminPath }}/blocks/delete">
            {{{ Security::csrfField() }}}
            <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
            <button type="submit" class="text-xs text-rose-500 hover:text-rose-700 font-medium transition-colors">{@ block_delete @}</button>
        </form>
        <div>
            <p class="text-xs font-medium text-slate-500 mb-1">{@ block_preview @}</p>
            <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 text-sm">{{{ $preview }}}</div>
        </div>
        <?php endif; ?>
    </div>
</div>

{% include 'admin/_layout_foot' %}
