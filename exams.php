<?php
/** Online Exams: the exams the current user created or co-administers. Administrators create new ones. */
require __DIR__ . '/app/bootstrap.php';
$me = require_login();
if (!has_activity_access()) {
    forbidden();
}

if (is_post() && input('action') === 'create') {
    verify_csrf();
    if (!can_create_exams()) {
        forbidden();
    }
    [$d, $errors] = exam_settings_input();
    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        $_SESSION['_old'] = $_POST;
        redirect('exams', ['new' => 1]);
    }
    db()->prepare('INSERT INTO exams (title, instructions, start_at, end_at, duration_minutes, created_by) VALUES (?,?,?,?,?,?)')
        ->execute([$d['title'], $d['instructions'], $d['start_at'], $d['end_at'], $d['duration_minutes'], $me['id']]);
    $id = (int) db()->lastInsertId();
    flash('success', 'Exam created as a draft. Add questions to the question bank next.');
    redirect('exam-questions', ['id' => $id]);
}

$ids = exam_accessible_ids();
$exams = [];
if ($ids) {
    $st = db()->prepare('SELECT x.*, u.name AS owner_name,
                           (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = x.id) AS questions,
                           (SELECT COUNT(*) FROM exam_participants p WHERE p.exam_id = x.id) AS participants,
                           (SELECT COUNT(*) FROM exam_participants p WHERE p.exam_id = x.id AND p.finished_at IS NOT NULL) AS completed
                         FROM exams x LEFT JOIN users u ON u.id = x.created_by
                         WHERE x.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY x.start_at DESC, x.id DESC');
    $st->execute($ids);
    $exams = $st->fetchAll();
}
$showForm = isset($_GET['new']) && can_create_exams();
$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);

$pageTitle = 'Online Exams';
require APP_ROOT . '/app/layout/header.php';
?>
<nav class="text-xs text-slate-500 mb-2 flex items-center gap-1.5"><a class="link" href="<?= e(url('activity')) ?>">Activity</a><?= icon('chevron-right', 'w-3 h-3') ?><span class="text-slate-700 font-medium">Online Exams</span></nav>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">
  <div>
    <h1 class="text-xl font-bold text-slate-900">Online Exams</h1>
    <p class="text-sm text-slate-500">Each exam is visible only to the administrator who created it and the co-admins they add.</p>
  </div>
  <?php if (can_create_exams() && !$showForm): ?><a href="<?= e(url('exams', ['new' => 1])) ?>" class="btn-primary"><?= icon('plus') ?> New exam</a><?php endif; ?>
</div>

<?php if ($showForm): ?>
<form method="post" class="card mb-6">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <h2 class="card-title mb-5">Create an online exam</h2>
  <div class="grid grid-cols-1 md:grid-cols-4 gap-5">
    <div class="md:col-span-4"><label class="label">Name of exam <span class="req">*</span></label><input class="input" name="title" value="<?= e($old['title'] ?? '') ?>" maxlength="200" required></div>
    <div><label class="label">Starting period <span class="req">*</span></label><input class="input" type="datetime-local" name="start_at" value="<?= e($old['start_at'] ?? '') ?>" required></div>
    <div><label class="label">Ending period (cut-off) <span class="req">*</span></label><input class="input" type="datetime-local" name="end_at" value="<?= e($old['end_at'] ?? '') ?>" required></div>
    <div><label class="label">Duration (minutes) <span class="req">*</span></label><input class="input" type="number" min="1" max="1440" name="duration_minutes" value="<?= e($old['duration_minutes'] ?? '60') ?>" required></div>
    <div class="md:col-span-4"><label class="label">Instructions for participants</label><textarea class="input" name="instructions" rows="4"><?= e($old['instructions'] ?? '') ?></textarea></div>
  </div>
  <div class="mt-6 pt-5 border-t border-slate-100 flex justify-end gap-2">
    <a href="<?= e(url('exams')) ?>" class="btn-secondary">Cancel</a>
    <button class="btn-primary">Create exam</button>
  </div>
</form>
<?php endif; ?>

<div class="card p-0 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><th>Exam</th><th>Window</th><th class="text-right">Questions</th><th class="text-right">Participants</th><th>Status</th><th>Access</th></tr></thead>
      <tbody>
      <?php foreach ($exams as $x): $href = url('exam-results', ['id' => $x['id']]); ?>
        <tr data-href="<?= e($href) ?>">
          <td><a href="<?= e($href) ?>" class="font-semibold text-slate-900 hover:text-sky-700"><?= e($x['title']) ?></a><span class="block text-[11px] text-slate-400"><?= (int) $x['duration_minutes'] ?> min · by <?= e($x['owner_name'] ?? '—') ?></span></td>
          <td class="text-xs whitespace-nowrap"><?= e(date('d M Y, h:i A', strtotime($x['start_at']))) ?><span class="block text-slate-400">to <?= e(date('d M Y, h:i A', strtotime($x['end_at']))) ?></span></td>
          <td class="text-right font-mono"><?= num((int) $x['questions']) ?></td>
          <td class="text-right font-mono"><?= num((int) $x['participants']) ?><span class="block text-[11px] text-slate-400"><?= num((int) $x['completed']) ?> completed</span></td>
          <td><?= status_badge(EXAM_STATUSES[$x['status']]) ?><?= $x['results_published_at'] ? ' ' . status_badge('Published') : '' ?></td>
          <td class="text-xs"><?= is_exam_owner($x) ? 'Owner' : 'Co-admin' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$exams): ?><tr><td colspan="6" class="text-center text-slate-400 py-10">No exams yet.<?= can_create_exams() ? ' Create one with “New exam”.' : '' ?></td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
