<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php
$pageTitle  = '{@ collections @}';
$e          = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$inputClass = 'w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500';
$statusLabel = static fn(string $st): string => match ($st) {
    'completed' => '{@ series_status_completed @}',
    'hiatus'    => '{@ series_status_hiatus @}',
    default     => '{@ series_status_ongoing @}',
};
$defaultLang = Env::get('SITE_DEFAULT_LANG', 'en');
$hasPublic   = $editCollection && array_filter($members, static fn($m) => (int)$m['post_count'] > 0);
?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-2">{@ collections @}</h2>
<p class="text-sm text-slate-500 mb-6">{@ collections_hint @}</p>
{% if $_GET['saved'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}
{% if $_GET['error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ collection_invalid @}</div>{% endif %}
{% if $_GET['lang_locked'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ collection_lang_locked @}</div>{% endif %}
{% if $_GET['collection_lang'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ collection_lang_mismatch @}</div>{% endif %}
{% if $_GET['cover_error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ collection_cover_error @}</div>{% endif %}

<div class="grid md:grid-cols-2 gap-6">
    <div>
        <!-- Collection list -->
        <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl overflow-hidden mb-4">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium">{@ title @}</th>
                        <th class="text-left px-4 py-3 font-medium w-16">{@ language @}</th>
                        <th class="text-right px-4 py-3 font-medium w-16">{@ collection_series_count @}</th>
                        <th class="px-4 py-3 w-16"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($collections as $c): ?>
                    <tr>
                        <td class="px-4 py-2">
                            <a href="?edit=<?= (int)$c['id'] ?>" class="hover:text-emerald-700"><?= $e($c['title']) ?></a>
                            <span class="text-slate-400 text-xs">/ <?= $e($c['slug']) ?></span>
                        </td>
                        <td class="px-4 py-2"><span class="inline-block px-2 py-0.5 text-xs rounded-lg bg-slate-100 dark:bg-slate-800 font-mono"><?= $e($c['lang']) ?></span></td>
                        <td class="px-4 py-2 text-right text-slate-500 text-xs"><?= (int)$c['member_count'] ?></td>
                        <td class="px-4 py-2">
                            <form method="post" action="/{{ $adminPath }}/collections/delete" onsubmit="return confirm('<?= str_replace(':count', (string)(int)$c['member_count'], '{@ collection_delete_confirm @}') ?>')">
                                {{{ Security::csrfField() }}}
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" class="text-xs text-rose-400 hover:text-rose-600">{@ delete @}</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$collections): ?>
                    <tr><td colspan="4" class="px-4 py-3 text-center text-slate-400">{@ collections_none_yet @}</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Create / edit -->
        <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
            <h3 class="font-medium text-sm mb-3"><?= $editCollection ? '{@ collection_edit @}' : '{@ collection_new @}' ?></h3>
            <form method="post" action="/{{ $adminPath }}/collections/save" enctype="multipart/form-data" class="space-y-3">
                {{{ Security::csrfField() }}}
                <input type="hidden" name="id" value="<?= (int)($editCollection['id'] ?? 0) ?>">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ title @}</label>
                    <input type="text" name="title" required maxlength="255" value="<?= $e($editCollection['title'] ?? '') ?>" class="<?= $inputClass ?>">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ series_slug @}</label>
                    <input type="text" name="slug" maxlength="200" pattern="[a-z0-9][a-z0-9\-]*" value="<?= $e($editCollection['slug'] ?? '') ?>" class="<?= $inputClass ?> font-mono">
                    <p class="text-xs text-slate-400 mt-1">{@ series_slug_hint @}</p>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ language @}</label>
                    <select name="lang" class="<?= $inputClass ?>">
                        <?php foreach ($contentLocales as $lCode => $lLabel): ?>
                        <option value="<?= $e($lCode) ?>" <?= ($editCollection['lang'] ?? $defaultLang) === $lCode ? 'selected' : '' ?>><?= $e($lLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ series_description @}</label>
                    <textarea name="description" rows="5" class="<?= $inputClass ?>"><?= $e($editCollection['description'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ series_cover @}</label>
                    <?php if (!empty($editCollection['cover_image'])): ?>
                    <img src="<?= $e($editCollection['cover_image']) ?>" alt="" class="w-24 rounded-xl mb-2">
                    <label class="flex items-center gap-2 text-sm cursor-pointer mb-2">
                        <input type="checkbox" name="cover_remove" value="1"> {@ series_cover_remove @}
                    </label>
                    <?php endif; ?>
                    <input type="file" name="cover" accept="image/jpeg,image/png,image/gif,image/webp" class="text-sm">
                </div>
                <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium py-2 rounded-xl transition-colors"><?= $editCollection ? '{@ save @}' : '{@ add @}' ?></button>
                <?php if ($editCollection): ?><a href="?" class="block text-center text-xs text-slate-500 hover:text-slate-600">{@ cancel @}</a><?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($editCollection): ?>
    <!-- Series of the collection being edited -->
    <div>
        <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
            <div class="flex items-center justify-between mb-1">
                <h3 class="font-medium text-sm">{@ collection_order @}</h3>
                <?php if ($hasPublic): ?>
                <a href="<?= $e(Collection::path($editCollection, $defaultLang)) ?>" target="_blank" rel="noopener" class="text-xs text-emerald-600 hover:text-emerald-700">{@ collection_view @}</a>
                <?php endif; ?>
            </div>
            <p class="text-xs text-slate-400 mb-3">{@ collection_order_hint @}</p>
            <?php if (!$members): ?>
            <p class="text-sm text-slate-400">{@ collection_empty @}</p>
            <?php else: ?>
            <form method="post" action="/{{ $adminPath }}/collections/reorder">
                {{{ Security::csrfField() }}}
                <input type="hidden" name="id" value="<?= (int)$editCollection['id'] ?>">
                <ol id="collectionSeries" class="space-y-2">
                    <?php foreach ($members as $m): ?>
                    <li class="border border-slate-100 dark:border-slate-800 rounded-xl p-3">
                        <input type="hidden" name="ids[]" value="<?= (int)$m['id'] ?>">
                        <div class="flex items-center gap-2">
                            <span class="flex-1 text-sm min-w-0">
                                <a href="/{{ $adminPath }}/series?edit=<?= (int)$m['id'] ?>" class="hover:text-emerald-700"><?= $e($m['title']) ?></a>
                                <span class="inline-block px-2 py-0.5 text-xs rounded-lg bg-slate-100 dark:bg-slate-800"><?= $statusLabel((string)$m['status']) ?></span>
                                <span class="text-xs text-slate-400"><?= (int)$m['post_count'] ?></span>
                            </span>
                            <button type="button" data-move="-1" class="text-xs px-2 py-1 rounded-lg border border-slate-200 dark:border-slate-700" aria-label="{@ series_move_up @}">↑</button>
                            <button type="button" data-move="1" class="text-xs px-2 py-1 rounded-lg border border-slate-200 dark:border-slate-700" aria-label="{@ series_move_down @}">↓</button>
                        </div>
                        <label class="mt-2 flex items-center gap-2 text-xs text-slate-500 cursor-pointer">
                            <input type="checkbox" name="remove[]" value="<?= (int)$m['id'] ?>"> {@ collection_remove @}
                        </label>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <button type="submit" class="mt-3 w-full bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium py-2 rounded-xl transition-colors">{@ collection_save_order @}</button>
            </form>
            <?php endif; ?>

            <?php if ($available): ?>
            <form method="post" action="/{{ $adminPath }}/collections/attach" class="mt-4 pt-3 border-t border-slate-100 dark:border-slate-800">
                {{{ Security::csrfField() }}}
                <input type="hidden" name="id" value="<?= (int)$editCollection['id'] ?>">
                <label class="block text-xs font-medium text-slate-500 mb-1">{@ collection_add_series @}</label>
                <div class="flex gap-2">
                    <select name="series_id" class="<?= $inputClass ?>">
                        <?php foreach ($available as $a): ?>
                        <option value="<?= (int)$a['id'] ?>"><?= $e($a['title']) ?><?= $a['collection_title'] !== null ? ' (' . $e($a['collection_title']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="px-4 bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium rounded-xl transition-colors">{@ collection_add @}</button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// Up / down: move the row and keep keyboard focus on the button that was pressed
document.getElementById('collectionSeries')?.addEventListener('click', function (ev) {
    var btn = ev.target.closest('button[data-move]');
    if (!btn) return;
    var li = btn.closest('li');
    if (btn.dataset.move === '-1' && li.previousElementSibling) {
        li.parentNode.insertBefore(li, li.previousElementSibling);
    } else if (btn.dataset.move === '1' && li.nextElementSibling) {
        li.parentNode.insertBefore(li.nextElementSibling, li);
    }
    btn.focus();
});
</script>

{% include 'admin/_layout_foot' %}
