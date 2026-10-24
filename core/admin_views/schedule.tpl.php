<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// AGPLv3 with the HayabusaPress Plugin & Theme Exception; see LICENSE
$pageTitle = '{@ schedule @}';
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$cellItem = static function (array $p) use ($e, $adminPath): string {
    $badge = $p['status'] === 'published' ? 'bg-emerald-50 dark:bg-emerald-900/20 text-emerald-600' : 'bg-slate-100 dark:bg-slate-800';
    return '<a href="/' . $e($adminPath) . '/post/edit/' . (int)$p['id'] . '" class="block rounded-lg px-2 py-1 text-xs ' . $badge . '">'
        . '<span class="font-medium">' . $e($p['title'] !== '' ? $p['title'] : '#' . $p['id']) . '</span>'
        . ($p['category'] !== '' ? '<span class="block text-slate-400">' . $e($p['category']) . '</span>' : '')
        . '</a>';
};
?>
{% include 'admin/_layout_head' %}

<h2 class="text-xl font-bold mb-2">{@ schedule @}</h2>
<p class="text-xs text-slate-500 mb-4">{@ schedule_hint @}</p>

<div class="flex items-center gap-2 mb-4">
    <a href="?week=<?= $e($prevWeek) ?>" class="text-sm px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700">← {@ schedule_prev @}</a>
    <a href="?" class="text-sm px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700">{@ schedule_today @}</a>
    <a href="?week=<?= $e($nextWeek) ?>" class="text-sm px-3 py-1.5 rounded-lg bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700">{@ schedule_next @} →</a>
</div>

<?php if (!$week['slots']): ?>
<div class="mb-4 p-3 rounded-xl bg-slate-50 dark:bg-slate-900 text-slate-600 text-sm">{@ schedule_no_slots @}</div>
<?php endif; ?>

<div class="bg-white dark:bg-slate-950 border border-slate-100 dark:border-slate-800 rounded-2xl overflow-x-auto">
    <table class="w-full text-sm" style="vertical-align:top">
        <thead class="bg-slate-50 dark:bg-slate-900 text-slate-500">
            <tr>
                <th class="text-left px-3 py-2 font-medium w-16"></th>
                <?php foreach ($week['days'] as $d): ?>
                <th class="text-left px-3 py-2 font-medium"><?= $e($dayLabels[$d]) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            <?php foreach ($week['slots'] as $slot): ?>
            <tr>
                <td class="px-3 py-2 font-mono text-xs text-slate-500" style="vertical-align:top"><?= $e($slot) ?></td>
                <?php foreach ($week['days'] as $d): $items = $week['cells'][$slot][$d]; ?>
                <td class="px-2 py-2 space-y-1<?= $items ? '' : ' bg-rose-50 dark:bg-rose-900/20' ?>">
                    <?php if ($items): foreach ($items as $p) echo $cellItem($p); else: ?>
                    <span class="text-xs text-rose-600">{@ schedule_free @}</span>
                    <?php endif; ?>
                </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            <tr>
                <td class="px-3 py-2 text-xs text-slate-500">{@ schedule_off_slot @}</td>
                <?php foreach ($week['days'] as $d): ?>
                <td class="px-2 py-2 space-y-1">
                    <?php foreach ($week['off'][$d] as $p): ?>
                    <span class="block font-mono text-xs text-slate-400"><?= $e($p['time']) ?></span><?= $cellItem($p) ?>
                    <?php endforeach; ?>
                </td>
                <?php endforeach; ?>
            </tr>
        </tbody>
    </table>
</div>

{% include 'admin/_layout_foot' %}
