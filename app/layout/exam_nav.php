<?php
/**
 * Online exam workspace header + tabs. Expects $exam and $examTab.
 */
$examTabs = [
    'exam-settings'     => ['Exam settings', 'cog'],
    'exam-questions'    => ['Question bank', 'clipboard'],
    'exam-series'       => ['Question series', 'database'],
    'exam-admins'       => ['Co-admins', 'key'],
    'exam-participants' => ['Participants', 'users'],
    'exam-results'      => ['Results', 'chart'],
];
$fmtDt = fn(string $d) => date('d M Y, h:i A', strtotime($d));
?>
<div class="mb-6 no-print">
  <nav class="text-xs text-slate-500 mb-3 flex items-center gap-1.5 flex-wrap">
    <a class="link" href="<?= e(url('activity')) ?>">Activity</a><?= icon('chevron-right', 'w-3 h-3') ?>
    <a class="link" href="<?= e(url('exams')) ?>">Online Exams</a><?= icon('chevron-right', 'w-3 h-3') ?>
    <span class="text-slate-700 font-medium truncate max-w-xs"><?= e($exam['title']) ?></span>
  </nav>
  <div class="bg-white rounded-2xl p-4 sm:p-5 shadow-xs border border-slate-200">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 mb-4 pb-3 border-b border-slate-100">
      <div class="min-w-0">
        <h1 class="text-lg font-bold text-slate-900 truncate"><?= e($exam['title']) ?></h1>
        <p class="text-xs text-slate-500"><?= e($fmtDt($exam['start_at'])) ?> → <?= e($fmtDt($exam['end_at'])) ?> · <?= (int) $exam['duration_minutes'] ?> min · created by <?= e($exam['owner_name'] ?? '—') ?></p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <?= status_badge(EXAM_STATUSES[$exam['status']]) ?>
        <?php if ($exam['results_published_at']): ?><?= status_badge('Published') ?><?php endif; ?>
        <span class="text-xs font-medium text-slate-500"><?= is_exam_owner($exam) ? 'Owner' : 'Co-admin' ?></span>
      </div>
    </div>
    <div class="flex gap-1 overflow-x-auto">
      <?php foreach ($examTabs as $href => [$label, $ico]): $on = $examTab === $href; ?>
        <a href="<?= e(url($href, ['id' => $exam['id']])) ?>" class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium whitespace-nowrap <?= $on ? 'bg-sky-50 text-sky-700' : 'text-slate-600 hover:bg-slate-50' ?>"><?= icon($ico) ?> <?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</div>
