<?php
/**
 * Activity: hub of programme activity modules. Today: Online Exams. Further modules are
 * added as cards here. (The audit trail moved to Settings → Activity log.)
 */
require __DIR__ . '/app/bootstrap.php';
require_login();
if (!has_activity_access()) {
    forbidden();
}

$examIds = exam_accessible_ids();
$examStats = ['total' => count($examIds), 'active' => 0];
if ($examIds) {
    $st = db()->prepare("SELECT COUNT(*) FROM exams WHERE status = 'active' AND id IN (" . implode(',', array_fill(0, count($examIds), '?')) . ')');
    $st->execute($examIds);
    $examStats['active'] = (int) $st->fetchColumn();
}

$modules = [
    [
        'href' => 'exams', 'icon' => 'academic', 'color' => 'bg-sky-100 text-sky-700', 'title' => 'Online Exams',
        'text' => 'Question banks, question series, participants, timed online exams with emailed passwords, results and publishing.',
        'meta' => num($examStats['total']) . ' exam' . ($examStats['total'] === 1 ? '' : 's') . ' · ' . num($examStats['active']) . ' active',
    ],
];

$pageTitle = 'Activity';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="mb-6">
  <h1 class="text-xl font-bold text-slate-900">Activity</h1>
  <p class="text-sm text-slate-500">Programme activity modules.</p>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
  <?php foreach ($modules as $m): ?>
    <a href="<?= e(url($m['href'])) ?>" class="card hover:border-sky-300 transition-colors block">
      <div class="flex items-center gap-3 mb-3">
        <span class="p-2.5 rounded-xl <?= $m['color'] ?>"><?= icon($m['icon'], 'w-6 h-6') ?></span>
        <div><h2 class="font-bold text-slate-900"><?= e($m['title']) ?></h2><p class="text-xs text-slate-500"><?= e($m['meta']) ?></p></div>
      </div>
      <p class="text-sm text-slate-600"><?= e($m['text']) ?></p>
      <span class="inline-flex items-center gap-1.5 mt-4 text-xs font-semibold text-sky-700">Open <?= icon('arrow-right', 'w-3.5 h-3.5') ?></span>
    </a>
  <?php endforeach; ?>
  <div class="rounded-2xl border-2 border-dashed border-slate-200 p-6 flex flex-col items-center justify-center text-center text-slate-400">
    <?= icon('plus', 'w-6 h-6') ?>
    <p class="text-sm font-medium mt-2">More modules will appear here</p>
  </div>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
