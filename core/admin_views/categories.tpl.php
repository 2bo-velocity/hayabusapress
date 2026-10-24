<?php // SPDX-License-Identifier: AGPL-3.0-or-later | AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE ?>
<?php
$pageTitle = '{@ categories_tags @}';
// ?edit=ID turns the add form into an edit form for that category
$editCat = null;
foreach ($categories as $c) {
    if ((int)$c['id'] === (int)($_GET['edit'] ?? 0)) { $editCat = $c; }
}
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-6">{@ category_management @}</h2>
{% if $_GET['saved'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}
{% if $_GET['error'] %}<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ save_error @}</div>{% endif %}

<div class="grid md:grid-cols-2 gap-6">
    <!-- Category list -->
    <div>
        <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl overflow-hidden mb-4">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500">
                    <tr>
                        <th class="text-left px-4 py-3 font-medium">{@ name @}</th>
                        <th class="text-left px-4 py-3 font-medium w-16">{@ language @}</th>
                        <th class="text-left px-4 py-3 font-medium w-20">{@ post_type @}</th>
                        <th class="px-4 py-3 w-16"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    {% foreach $categories as $cat %}
                    <tr>
                        <td class="px-4 py-2"><a href="?edit={{ $cat['id'] }}" class="hover:text-emerald-700">{{ $cat['name'] }}</a> <span class="text-slate-400 text-xs">/ {{ $cat['slug'] }}</span>
                            <?php if (!empty($cat['feed_enabled'])): ?><span class="inline-block px-2 py-0.5 text-xs rounded-lg bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600">{@ category_feed_badge @}</span><?php endif; ?></td>
                        <td class="px-4 py-2">
                            <span class="inline-block px-2 py-0.5 text-xs rounded-lg bg-slate-100 dark:bg-slate-800 font-mono">{{ $cat['lang'] }}</span>
                        </td>
                        <td class="px-4 py-2 text-slate-500 text-xs">{{ $cat['type'] }}</td>
                        <td class="px-4 py-2">
                            <form method="post" action="/{{ $adminPath }}/category/delete" onsubmit="return confirm('{@ confirm_delete @}')">
    {{{ Security::csrfField() }}}
                                <input type="hidden" name="id" value="{{ $cat['id'] }}">
                                <button type="submit" class="text-xs text-rose-400 hover:text-rose-600">{@ delete @}</button>
                            </form>
                        </td>
                    </tr>
                    {% endforeach %}
                </tbody>
            </table>
        </div>

        <!-- Add form -->
        <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4">
            <h3 class="font-medium text-sm mb-3"><?= $editCat ? '{@ edit_category @}' : '{@ add_category @}' ?></h3>
            <form method="post" action="/{{ $adminPath }}/category/save" class="space-y-3">
    {{{ Security::csrfField() }}}
                <input type="hidden" name="id" value="<?= (int)($editCat['id'] ?? 0) ?>">
                <input type="text" name="name" placeholder="{@ name @}" required value="<?= $e($editCat['name'] ?? '') ?>"
                       class="w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
                <input type="text" name="slug" placeholder="{@ slug_optional @}" value="<?= $e($editCat['slug'] ?? '') ?>"
                       class="w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
                <input type="text" name="hashtags" placeholder="{@ category_hashtags @}" maxlength="255" value="<?= $e($editCat['hashtags'] ?? '') ?>"
                       class="w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
                <p class="text-xs text-slate-400">{@ category_hashtags_hint @}</p>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" name="feed_enabled" value="1" <?= !empty($editCat['feed_enabled']) ? 'checked' : '' ?>> {@ category_feed_enabled @}
                </label>
                <div class="grid grid-cols-2 gap-2">
                    <select name="type" class="w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
                        <option value="post">{@ type_for_post @}</option>
                        <option value="page" <?= ($editCat['type'] ?? '') === 'page' ? 'selected' : '' ?>>{@ type_for_page @}</option>
                    </select>
                    <select name="lang" class="w-full px-3 py-2 text-sm border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
                        {% foreach $contentLocales as $lCode => $lLabel %}
                        <option value="{{ $lCode }}" <?= ($editCat['lang'] ?? '') === $lCode ? 'selected' : '' ?>>{{ $lLabel }}</option>
                        {% endforeach %}
                    </select>
                </div>
                <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white text-sm font-medium py-2 rounded-xl transition-colors"><?= $editCat ? '{@ save @}' : '{@ add @}' ?></button>
                <?php if ($editCat): ?><a href="?" class="block text-center text-xs text-slate-500 hover:text-slate-600">{@ cancel @}</a><?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Tags (links) -->
    <div>
        <a href="/{{ $adminPath }}/tags"
           class="flex items-center justify-between p-4 bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl hover:bg-slate-50 dark:hover:bg-slate-900 transition-colors">
            <span class="font-medium">{@ go_to_tags @}</span>
        </a>
    </div>
</div>

{% include 'admin/_layout_foot' %}
