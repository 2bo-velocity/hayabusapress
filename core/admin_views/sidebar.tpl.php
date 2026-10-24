<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
$pageTitle = '{@ sidebar @}';
$widgetLabels = [
    'search'          => '{@ widget_search @}',
    'recent_comments' => '{@ widget_recent_comments @}',
    'archive'         => '{@ widget_archive @}',
    'categories'      => '{@ widget_categories @}',
    'tags'            => '{@ widget_tags @}',
    'recent_posts'    => '{@ widget_recent_posts @}',
    'posts_pick'      => '{@ widget_posts_pick @}',
    'text'            => '{@ widget_text @}',
    'image'           => '{@ widget_image @}',
    'html'            => '{@ widget_html @}',
    'menu'            => '{@ widget_menu @}',
];
$areaLabels = [
    'sidebar_right'  => '{@ sidebar_right @}',
    'sidebar_left'   => '{@ sidebar_left @}',
    'header_below'   => '{@ area_header_below @}',
    'content_before' => '{@ area_content_before @}',
    'content_after'  => '{@ area_content_after @}',
    'footer'         => '{@ area_footer @}',
];
$menuLabels = [
    'pc_header'        => '{@ menu_pc_header @}',
    'pc_sidebar_left'  => '{@ menu_pc_sidebar_left @}',
    'pc_sidebar_right' => '{@ menu_pc_sidebar_right @}',
    'pc_footer'        => '{@ menu_pc_footer @}',
    'sp_header'        => '{@ menu_sp_header @}',
    'sp_footer'        => '{@ menu_sp_footer @}',
];
$inputClass = 'w-full text-sm px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500';
$monoClass  = 'w-full text-xs px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white font-mono focus:outline-none focus:ring-2 focus:border-emerald-500';
// The labels above are baked by {@ @} and therefore already HTML-escaped: print them as is.
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-6">{@ sidebar @}</h2>
{% if $_GET['saved'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ save_success @}</div>{% endif %}
{% if $_GET['added'] %}<div class="mb-4 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600 text-sm">{@ widget_added @}</div>{% endif %}
<?php if (($_GET['error'] ?? '') === 'permission'): ?>
<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ widget_html_no_permission @}</div>
<?php elseif (($_GET['error'] ?? '') === 'too_long'): ?>
<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ widget_too_long @}</div>
<?php elseif (!empty($_GET['error'])): ?>
<div class="mb-4 p-3 rounded-xl bg-rose-50 dark:bg-rose-900/20 text-rose-600 dark:text-rose-400 text-sm">{@ widget_save_failed @}</div>
<?php endif; ?>

<!-- Area tabs -->
<div class="flex flex-wrap gap-2 mb-4">
    <?php foreach ($areas as $a): ?>
    <a href="?area=<?= $e($a) ?>"
       class="px-3 py-1.5 rounded-lg text-sm transition-colors <?= $area === $a ? 'bg-emerald-500 text-white' : 'bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700' ?>">
        <?= $areaLabels[$a] ?? $e($a) ?><?php if (!empty($counts[$a])): ?> (<?= (int)$counts[$a] ?>)<?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>
<p class="text-xs text-slate-500 mb-4">{@ sidebar_widget_hint @}</p>

<form method="post" action="/{{ $adminPath }}/sidebar/save" class="space-y-3 mb-6">
    {{{ Security::csrfField() }}}
    <input type="hidden" name="location" value="<?= $e($area) ?>">

    <?php if (!$widgets): ?>
    <p class="text-sm text-slate-400">{@ widget_none @}</p>
    <?php endif; ?>

    <?php foreach ($widgets as $w):
        $id     = (int)$w['id'];
        $key    = (string)$w['widget_key'];
        $s      = $w['settings'];
        $name   = 'widgets[' . $id . ']';
        $locked = $key === 'html' && !$canRawHtml;
    ?>
    <div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl p-4 space-y-2">
        <div class="flex items-center gap-2">
            <span class="inline-block px-2 py-0.5 text-xs rounded-lg bg-slate-100 dark:bg-slate-800 font-mono"><?= $widgetLabels[$key] ?? $e($key) ?></span>
            <label class="text-xs text-slate-500">{@ widget_order @}
                <input type="number" name="<?= $name ?>[order]" value="<?= (int)$w['sort_order'] ?>" class="w-20 text-sm px-2 py-1 border border-slate-200 dark:border-slate-700 rounded-lg dark:bg-slate-900 dark:text-white">
            </label>
            <?php if (!$locked): ?>
            <span class="flex-1"></span>
            <label class="flex items-center gap-1 text-xs text-rose-500 cursor-pointer">
                <input type="checkbox" name="<?= $name ?>[delete]" value="1"> {@ widget_delete @}
            </label>
            <?php endif; ?>
        </div>

        <?php if ($locked): ?>
        <p class="text-xs text-slate-400">{@ widget_html_no_permission @}</p>
        <?php else: ?>
        <?php if ($key !== 'search'): ?>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">{@ widget_title @}</label>
            <input type="text" name="<?= $name ?>[title]" value="<?= $e($w['title'] ?? '') ?>" maxlength="255" placeholder="{@ widget_title_hint @}" class="<?= $inputClass ?>">
        </div>
        <?php endif; ?>

        <?php if ($key === 'recent_posts'): ?>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">{@ widget_count @}</label>
            <input type="number" min="1" max="<?= SidebarCompiler::LIST_MAX ?>" name="<?= $name ?>[settings][count]" value="<?= (int)($s['count'] ?? 5) ?>" class="<?= $inputClass ?>">
        </div>
        <?php elseif ($key === 'posts_pick'): ?>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">{@ widget_ids @}</label>
            <input type="text" name="<?= $name ?>[settings][ids]" value="<?= $e(implode(', ', array_map('intval', (array)($s['ids'] ?? [])))) ?>" placeholder="12, 34, 56" class="<?= $inputClass ?>">
            <p class="text-xs text-slate-400 mt-1">{@ widget_ids_hint @}</p>
        </div>
        <?php elseif ($key === 'text'): ?>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">{@ widget_body_markdown @}</label>
            <textarea name="<?= $name ?>[settings][body]" rows="5" maxlength="<?= SidebarCompiler::BODY_MAX ?>" class="<?= $monoClass ?>"><?= $e($s['body'] ?? '') ?></textarea>
        </div>
        <?php elseif ($key === 'html'): ?>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">{@ widget_body_html @}</label>
            <textarea name="<?= $name ?>[settings][body]" rows="6" maxlength="<?= SidebarCompiler::BODY_MAX ?>" class="<?= $monoClass ?>"><?= $e($s['body'] ?? '') ?></textarea>
            <p class="text-xs text-slate-400 mt-1">{@ widget_html_hint @}</p>
        </div>
        <?php elseif ($key === 'image'): ?>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">{@ widget_image_src @}</label>
            <input type="text" name="<?= $name ?>[settings][src]" value="<?= $e($s['src'] ?? '') ?>" placeholder="/assets/site/banner.png" class="<?= $inputClass ?>">
            <p class="text-xs text-slate-400 mt-1">{@ widget_image_src_hint @}</p>
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">{@ widget_image_alt @}</label>
            <input type="text" name="<?= $name ?>[settings][alt]" value="<?= $e($s['alt'] ?? '') ?>" maxlength="255" class="<?= $inputClass ?>">
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">{@ widget_image_href @}</label>
            <input type="text" name="<?= $name ?>[settings][href]" value="<?= $e($s['href'] ?? '') ?>" placeholder="https://" class="<?= $inputClass ?>">
        </div>
        <?php elseif ($key === 'menu'): ?>
        <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">{@ widget_menu_location @}</label>
            <select name="<?= $name ?>[settings][location]" class="<?= $inputClass ?>">
                <?php foreach ($menuLocations as $ml): ?>
                <option value="<?= $e($ml) ?>" <?= ($s['location'] ?? '') === $ml ? 'selected' : '' ?>><?= $menuLabels[$ml] ?? $e($ml) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <?php if ($widgets): ?>
    <button type="submit" class="text-sm px-6 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-xl transition-colors">
        {@ save @}
    </button>
    <?php endif; ?>
</form>

<!-- Add a widget to this area -->
<form method="post" action="/{{ $adminPath }}/sidebar/add" class="flex items-center gap-2">
    {{{ Security::csrfField() }}}
    <input type="hidden" name="location" value="<?= $e($area) ?>">
    <select name="widget_key" class="text-sm px-3 py-2 border border-slate-200 dark:border-slate-700 rounded-xl dark:bg-slate-900 dark:text-white focus:outline-none focus:ring-2 focus:border-emerald-500">
        <?php foreach ($allWidgets as $wKey): if ($wKey === 'html' && !$canRawHtml) continue; ?>
        <option value="<?= $e($wKey) ?>"><?= $widgetLabels[$wKey] ?? $e($wKey) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="text-sm px-4 py-2 bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-xl transition-colors">
        {@ widget_add @}
    </button>
</form>

{% include 'admin/_layout_foot' %}
