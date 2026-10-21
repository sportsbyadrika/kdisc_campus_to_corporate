<?php
/** Online exam: name, period, duration, status, shuffling and instructions. */
require __DIR__ . '/app/bootstrap.php';
$exam = load_exam();
$id = (int) $exam['id'];
$locked = exam_is_locked($id);

if (is_post()) {
    verify_csrf();
    if (input('action') === 'delete') {
        if (!is_exam_owner($exam)) forbidden('Only the creator can delete this exam.');
        if ($locked) {
            flash('error', 'Participants have already taken this exam, so it cannot be deleted. Set its status to Closed instead.');
            redirect('exam-settings', ['id' => $id]);
        }
        db()->prepare('DELETE FROM exams WHERE id = ?')->execute([$id]);
        flash('success', "Exam “{$exam['title']}” deleted.");
        redirect('exams');
    }

    [$d, $errors] = exam_settings_input();
    $d['status'] = array_key_exists((string) input('status'), EXAM_STATUSES) ? (string) input('status') : 'draft';
    $d['shuffle_questions'] = input('shuffle_questions') === '1' ? 1 : 0;
    $d['shuffle_options'] = input('shuffle_options') === '1' ? 1 : 0;
    if ($d['status'] === 'active') {
        $st = db()->prepare('SELECT COUNT(*) FROM exam_questions WHERE exam_id = ?');
        $st->execute([$id]);
        if (!(int) $st->fetchColumn()) $errors[] = 'Add at least one question to the question bank before making the exam active.';
    }
    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        $_SESSION['_old'] = $_POST;
        redirect('exam-settings', ['id' => $id]);
    }
    $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($d)));
    db()->prepare("UPDATE exams SET {$set} WHERE id = ?")->execute([...array_values($d), $id]);
    flash('success', 'Exam settings saved.');
    redirect('exam-settings', ['id' => $id]);
}

$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
$v = fn(string $k) => $old[$k] ?? $exam[$k];
$checked = fn(string $k) => ($old ? ($old[$k] ?? '0') : (string) $exam[$k]) === '1' ? 'checked' : '';

$pageTitle = $exam['title'] . ' · Settings';
require APP_ROOT . '/app/layout/header.php';
$examTab = 'exam-settings';
require APP_ROOT . '/app/layout/exam_nav.php';
?>
<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <h2 class="card-title mb-5">Exam settings</h2>
  <div class="grid grid-cols-1 md:grid-cols-4 gap-5">
    <div class="md:col-span-3"><label class="label">Name of exam <span class="req">*</span></label><input class="input" name="title" value="<?= e($v('title')) ?>" maxlength="200" required></div>
    <div>
      <label class="label">Status <span class="req">*</span></label>
      <select class="input" name="status">
        <?php foreach (EXAM_STATUSES as $k => $lbl): ?><option value="<?= $k ?>" <?= $v('status') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
      </select>
      <span class="hint">Participants can sign in only while the exam is Active.</span>
    </div>
    <div><label class="label">Starting period <span class="req">*</span></label><input class="input" type="datetime-local" name="start_at" value="<?= e(isset($old['start_at']) ? $old['start_at'] : datetime_local($exam['start_at'])) ?>" required></div>
    <div><label class="label">Ending period (cut-off) <span class="req">*</span></label><input class="input" type="datetime-local" name="end_at" value="<?= e(isset($old['end_at']) ? $old['end_at'] : datetime_local($exam['end_at'])) ?>" required>
      <span class="hint">No attempt runs past this time. Participants who have not signed in by then are Absent.</span></div>
    <div><label class="label">Duration (minutes) <span class="req">*</span></label><input class="input" type="number" min="1" max="1440" name="duration_minutes" value="<?= e($v('duration_minutes')) ?>" required>
      <span class="hint">Clock starts when the participant presses “Start exam”.</span></div>
    <div class="space-y-2 pt-6">
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="shuffle_questions" value="1" class="h-4 w-4 rounded border-slate-300 text-sky-600" <?= $checked('shuffle_questions') ?>> Shuffle question order</label>
      <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="shuffle_options" value="1" class="h-4 w-4 rounded border-slate-300 text-sky-600" <?= $checked('shuffle_options') ?>> Shuffle answer options</label>
    </div>
    <div class="md:col-span-4"><label class="label">Instructions for participants</label><textarea class="input" name="instructions" rows="6"><?= e($v('instructions')) ?></textarea>
      <span class="hint">Shown before the “Start exam” button, along with the standard rules (one question at a time, no going back, no copy/paste, signing out ends the exam).</span></div>
  </div>
  <div class="mt-6 pt-5 border-t border-slate-100 flex justify-end"><button class="btn-primary">Save settings</button></div>
</form>

<?php if (is_exam_owner($exam)): ?>
<form method="post" class="card mt-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3" data-confirm="Delete this exam with its question bank, series and participants? This cannot be undone.">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete">
  <div><h2 class="card-title">Delete exam</h2><p class="text-xs text-slate-500"><?= $locked ? 'Not possible: participants have already taken this exam.' : 'Removes the exam, its question bank, series and participants.' ?></p></div>
  <button class="btn-secondary text-rose-600" <?= $locked ? 'disabled' : '' ?>><?= icon('trash') ?> Delete exam</button>
</form>
<?php endif; ?>
<?php require APP_ROOT . '/app/layout/footer.php';
