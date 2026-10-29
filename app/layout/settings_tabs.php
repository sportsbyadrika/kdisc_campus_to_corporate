<?php
/** Tabs across the Settings area (administrators). The activity log lives here. Expects $settingsTab. */
if (!has_role('admin')) {
    return;
}
$settingsTabs = ['settings' => ['General settings', 'cog'], 'settings-home' => ['Home page', 'home'], 'activity-log' => ['Activity log', 'clock']];
?>
<div class="flex gap-1 border-b border-slate-200 mb-6 overflow-x-auto no-print">
  <?php foreach ($settingsTabs as $href => [$label, $ico]): $on = ($settingsTab ?? '') === $href; ?>
    <a href="<?= e(url($href)) ?>" class="flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px whitespace-nowrap <?= $on ? 'border-sky-600 text-sky-700' : 'border-transparent text-slate-500 hover:text-slate-800' ?>"><?= icon($ico) ?> <?= e($label) ?></a>
  <?php endforeach; ?>
</div>
