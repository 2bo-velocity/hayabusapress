<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php
$pageTitle  = '{@ series @}';
$e          = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$inputClass = 'w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500';
$statusLabel = static fn(string $st): string => match ($st) {
    'completed' => '{@ series_status_completed @}',
    'hiatus'    => '{@ series_status_hiatus @}',
    default     => '{@ series_status_ongoing @}',
};
$defaultLang = Env::get('SITE_DEFAULT_LANG', 'en');
?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-2">{@ series @}</h2>
<p class="text-sm text-slate-500 mb-6">{@ series_hint @}</p>
{% if $_GET['saved'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}
{% if $_GET['error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ series_invalid @}</div>{% endif %}
{% if $_GET['lang_locked'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ series_lang_locked @}</div>{% endif %}
{% if $_GET['cover_error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ series_cover_error @}</div>{% endif %}
{% if $_GET['collection_lang'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ collection_lang_mismatch @}</div>{% endif %}

<div class="grid md:grid-cols-2 gap-6">
    <div>
        <!-- Series list -->
        <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl overflow-hidden mb-4">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium">{@ title @}</th>
                        <th class="text-left px-4 py-3 font-medium w-16">{@ language @}</th>
                        <th class="text-right px-4 py-3 font-medium w-20">{@ series_published @}</th>
                        <th class="px-4 py-3 w-16"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <?php foreach ($series as $s): ?>
                    <tr>
                        <td class="px-4 py-2">
                            <a href="?edit=<?= (int)$s['id'] ?>" class="hover:text-emerald-700"><?= $e($s['title']) ?></a>
                            <span class="text-slate-400 text-xs">/ <?= $e($s['slug']) ?></span>
                            <span class="inline-block px-2 py-0.5 text-xs rounded-lg bg-slate-100 dark:bg-slate-800"><?= $statusLabel((string)$s['status']) ?></span>
                        </td>
                        <td class="px-4 py-2"><span class="inline-block px-2 py-0.5 text-xs rounded-lg bg-slate-100 dark:bg-slate-800 font-mono"><?= $e($s['lang']) ?></span></td>
                        <td class="px-4 py-2 text-right text-slate-500 text-xs"><?= (int)$s['post_count'] ?> / <?= number_format((int)$s['char_count']) ?></td>
                        <td class="px-4 py-2">
                            <form method="post" action="/{{ $adminPath }}/series/delete" onsubmit="return confirm('<?= str_replace(':count', (string)(int)$s['member_count'], '{@ series_delete_confirm @}') ?>')">
                                {{{ Security::csrfField() }}}
                                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button type="submit" class="text-xs text-rose-400 hover:text-rose-600">{@ delete @}</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$series): ?>
                    <tr><td colspan="4" class="px-4 py-3 text-center text-slate-400">{@ series_none_yet @}</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Create / edit -->
        <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
            <h3 class="font-medium text-sm mb-3"><?= $editSeries ? '{@ series_edit @}' : '{@ series_new @}' ?></h3>
            <form method="post" action="/{{ $adminPath }}/series/save" enctype="multipart/form-data" class="space-y-3">
                {{{ Security::csrfField() }}}
                <input type="hidden" name="id" value="<?= (int)($editSeries['id'] ?? 0) ?>">
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ title @}</label>
                    <input type="text" name="title" required maxlength="255" value="<?= $e($editSeries['title'] ?? '') ?>" class="<?= $inputClass ?>">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ series_slug @}</label>
                    <input type="text" name="slug" maxlength="200" pattern="[a-z0-9][a-z0-9\-]*" value="<?= $e($editSeries['slug'] ?? '') ?>" class="<?= $inputClass ?> font-mono">
                    <p class="text-xs text-slate-400 mt-1">{@ series_slug_hint @}</p>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">{@ language @}</label>
                        <select name="lang" class="<?= $inputClass ?>">
                            <?php foreach ($contentLocales as $lCode => $lLabel): ?>
                            <option value="<?= $e($lCode) ?>" <?= ($editSeries['lang'] ?? $defaultLang) === $lCode ? 'selected' : '' ?>><?= $e($lLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-500 mb-1">{@ status @}</label>
                        <select name="status" class="<?= $inputClass ?>">
                            <?php foreach (['ongoing', 'completed', 'hiatus'] as $st): ?>
                            <option value="<?= $st ?>" <?= ($editSeries['status'] ?? 'ongoing') === $st ? 'selected' : '' ?>><?= $statusLabel($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ series_description @}</label>
                    <textarea name="description" rows="5" class="<?= $inputClass ?>"><?= $e($editSeries['description'] ?? '') ?></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ series_cover @}</label>
                    <?php if (!empty($editSeries['cover_image'])): ?>
                    <img src="<?= $e($editSeries['cover_image']) ?>" alt="" class="w-24 rounded-xl mb-2">
                    <label class="flex items-center gap-2 text-sm cursor-pointer mb-2">
                        <input type="checkbox" name="cover_remove" value="1"> {@ series_cover_remove @}
                    </label>
                    <?php endif; ?>
                    <input type="file" name="cover" accept="image/jpeg,image/png,image/gif,image/webp" class="text-sm">
                </div>
                <?php if ($collections): ?>
                <div>
                    <label class="block text-xs font-medium text-slate-500 mb-1">{@ collection_field @}</label>
                    <select name="collection_id" class="<?= $inputClass ?>">
                        <option value="0">{@ collection_none @}</option>
                        <?php foreach ($collections as $col): ?>
                        <option value="<?= (int)$col['id'] ?>" <?= (int)($editSeries['collection_id'] ?? 0) === (int)$col['id'] ? 'selected' : '' ?>><?= $e($col['title']) ?> (<?= $e($col['lang']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-slate-400 mt-1">{@ collection_field_hint @}</p>
                </div>
                <?php endif; ?>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" name="feed_enabled" value="1" <?= !empty($editSeries['feed_enabled']) ? 'checked' : '' ?>> {@ series_feed_enabled @}
                </label>
                <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium py-2 rounded-xl transition-colors"><?= $editSeries ? '{@ save @}' : '{@ add @}' ?></button>
                <?php if ($editSeries): ?><a href="?" class="block text-center text-xs text-slate-500 hover:text-slate-600">{@ cancel @}</a><?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($editSeries): ?>
    <!-- Order and chapter headings of the series being edited -->
    <div>
        <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
            <div class="flex items-center justify-between mb-1">
                <h3 class="font-medium text-sm">{@ series_order @}</h3>
                <?php if ((int)$editSeries['post_count'] > 0): ?>
                <a href="<?= $e(Series::path($editSeries, $defaultLang)) ?>" target="_blank" rel="noopener" class="text-xs text-emerald-600 hover:text-emerald-700">{@ series_view @}</a>
                <?php endif; ?>
            </div>
            <p class="text-xs text-slate-400 mb-3">{@ series_order_hint @}</p>
            <?php if (!$episodes): ?>
            <p class="text-sm text-slate-400">{@ series_empty @}</p>
            <?php else: ?>
            <form method="post" action="/{{ $adminPath }}/series/reorder">
                {{{ Security::csrfField() }}}
                <input type="hidden" name="id" value="<?= (int)$editSeries['id'] ?>">
                <ol id="seriesEpisodes" class="space-y-2">
                    <?php foreach ($episodes as $ep): ?>
                    <li class="border border-slate-100 dark:border-slate-800 rounded-xl p-3">
                        <input type="hidden" name="ids[]" value="<?= (int)$ep['id'] ?>">
                        <div class="flex items-center gap-2">
                            <span class="flex-1 text-sm min-w-0">
                                <a href="/{{ $adminPath }}/post/edit/<?= (int)$ep['id'] ?>" class="hover:text-emerald-700"><?= $e($ep['title']) ?></a>
                                <?php if ($ep['status'] !== 'published'): ?><span class="text-xs text-slate-400">(<?= $ep['status'] === 'scheduled' ? '{@ scheduled @}' : '{@ draft @}' ?>)</span><?php endif; ?>
                            </span>
                            <button type="button" data-move="-1" class="text-xs px-2 py-1 rounded-lg border border-slate-200 dark:border-slate-700" aria-label="{@ series_move_up @}">↑</button>
                            <button type="button" data-move="1" class="text-xs px-2 py-1 rounded-lg border border-slate-200 dark:border-slate-700" aria-label="{@ series_move_down @}">↓</button>
                        </div>
                        <input type="text" name="part[<?= (int)$ep['id'] ?>]" value="<?= $e($ep['series_part'] ?? '') ?>" maxlength="100" placeholder="{@ series_part @}"
                               class="mt-2 w-full px-3 py-1 text-xs border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
                    </li>
                    <?php endforeach; ?>
                </ol>
                <button type="submit" class="mt-3 w-full bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium py-2 rounded-xl transition-colors">{@ series_save_order @}</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// Up / down: move the row and keep keyboard focus on the button that was pressed
document.getElementById('seriesEpisodes')?.addEventListener('click', function (ev) {
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
