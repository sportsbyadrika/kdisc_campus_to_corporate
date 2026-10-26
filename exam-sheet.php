<?php
/** Online exam: one participant's answer sheet; descriptive answers are marked here. */
require __DIR__ . '/app/bootstrap.php';
$exam = load_exam();
$id = (int) $exam['id'];
exam_finalize_overdue($id);
$st = db()->prepare('SELECT p.*, s.name AS series_name FROM exam_participants p LEFT JOIN exam_series s ON s.id = p.series_id WHERE p.id = ? AND p.exam_id = ?');
$st->execute([(int) int_input('pid', 0), $id]);
$p = $st->fetch();
if (!$p || !$p['started_at']) {
    forbidden('This participant has not taken the exam.');
}
$pid = (int) $p['id'];
$order = json_decode((string) $p['question_order'], true) ?: [];
$canGrade = $p['finished_at'] && !$exam['results_published_at'];

if (is_post()) {
    verify_csrf();
    if (!$canGrade) {
        flash('error', $exam['results_published_at'] ? 'Unpublish the results to change marks.' : 'Answers can be marked once the participant has finished.');
        redirect('exam-sheet', ['id' => $id, 'pid' => $pid]);
    }
    $st = db()->prepare("SELECT a.question_id, q.marks FROM exam_answers a JOIN exam_questions q ON q.id = a.question_id WHERE a.participant_id = ? AND q.qtype = 'descriptive'");
    $st->execute([$pid]);
    $upd = db()->prepare('UPDATE exam_answers SET marks_awarded = ?, grader_note = ? WHERE participant_id = ? AND question_id = ?');
    $errors = 0;
    foreach ($st->fetchAll() as $row) {
        $qid = (int) $row['question_id'];
        $raw = trim((string) ($_POST['marks'][$qid] ?? ''));
        if ($raw === '') {
            $upd->execute([null, nullable(mb_substr((string) ($_POST['note'][$qid] ?? ''), 0, 500)), $pid, $qid]);
            continue;
        }
        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > (float) $row['marks']) { $errors++; continue; }
        $upd->execute([round((float) $raw, 2), nullable(mb_substr((string) ($_POST['note'][$qid] ?? ''), 0, 500)), $pid, $qid]);
    }
    exam_score($pid);
    flash($errors ? 'error' : 'success', $errors ? "{$errors} mark(s) were outside 0 – maximum and were not saved." : 'Marks saved.');
    redirect('exam-sheet', ['id' => $id, 'pid' => $pid]);
}

$questions = [];
if ($order) {
    $st = db()->prepare('SELECT * FROM exam_questions WHERE id IN (' . implode(',', array_fill(0, count($order), '?')) . ')');
    $st->execute($order);
    $questions = array_column($st->fetchAll(), null, 'id');
}
$options = exam_options_for(array_keys($questions));
$st = db()->prepare('SELECT * FROM exam_answers WHERE participant_id = ?');
$st->execute([$pid]);
$answers = array_column($st->fetchAll(), null, 'question_id');
$state = participant_state($p, $exam);

$pageTitle = $p['name'] . ' · ' . $exam['title'];
require APP_ROOT . '/app/layout/header.php';
$examTab = 'exam-results';
require APP_ROOT . '/app/layout/exam_nav.php';
?>
<div class="card mb-4 flex flex-col md:flex-row md:items-center justify-between gap-3">
  <div>
    <a href="<?= e(url('exam-results', ['id' => $id])) ?>" class="text-xs link">&larr; All results</a>
    <h2 class="text-lg font-bold text-slate-900 mt-1"><?= e($p['name']) ?></h2>
    <p class="text-xs text-slate-500"><?= e(implode(' · ', array_filter([$p['email'], $p['phone'], $p['details'], $p['series_name']]))) ?></p>
  </div>
  <div class="flex items-center gap-4">
    <div><?= status_badge($state['status']) ?><span class="block text-[11px] text-slate-400 mt-0.5"><?= e($state['remark']) ?></span></div>
    <?php if ($p['finished_at']): ?><div class="text-right"><p class="text-2xl font-extrabold text-slate-900"><?= exam_num($p['score']) ?> <span class="text-sm text-slate-400">/ <?= exam_num($p['max_score']) ?></span></p><p class="text-[11px] text-slate-500"><?= (int) $p['focus_lost'] ?> tab switch(es)</p></div><?php endif; ?>
  </div>
</div>

<form method="post">
  <?= csrf_field() ?>
  <div class="space-y-3">
    <?php foreach ($order as $n => $qid): $q = $questions[$qid] ?? null; if (!$q) continue;
        $a = $answers[$qid] ?? null;
        $picked = array_map('intval', json_decode((string) ($a['selected'] ?? ''), true) ?: []);
        $seen = $n < (int) $p['current_index'] || $a; ?>
      <div class="card p-4">
        <div class="flex items-start justify-between gap-3 mb-2">
          <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Q<?= $n + 1 ?> · <?= e(EXAM_QTYPES[$q['qtype']]) ?> · <?= exam_num($q['marks']) ?> marks</p>
          <?php if ($q['qtype'] !== 'descriptive'): ?>
            <?= !$a ? status_badge($seen ? 'Not answered' : 'Not reached') : ($a['is_correct'] ? '<span class="text-xs font-bold text-emerald-600">Correct · ' . exam_num($a['marks_awarded']) . '</span>' : '<span class="text-xs font-bold text-rose-600">Wrong · 0</span>') ?>
          <?php endif; ?>
        </div>
        <p class="text-sm text-slate-900 whitespace-pre-line mb-3"><?= e($q['question']) ?></p>
        <?php if ($q['qtype'] === 'descriptive'): ?>
          <div class="p-3 rounded-lg bg-slate-50 border border-slate-200 text-sm whitespace-pre-line <?= $a && trim((string) $a['answer_text']) !== '' ? 'text-slate-800' : 'text-slate-400 italic' ?>"><?= $a && trim((string) $a['answer_text']) !== '' ? e($a['answer_text']) : ($seen ? 'No answer written.' : 'Not reached.') ?></div>
          <?php if ($a): ?>
            <div class="mt-3 grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
              <div><label class="label">Marks (max <?= exam_num($q['marks']) ?>)</label><input class="input input-sm" type="number" step="0.25" min="0" max="<?= exam_num($q['marks']) ?>" name="marks[<?= $qid ?>]" value="<?= e($a['marks_awarded'] === null ? '' : exam_num($a['marks_awarded'])) ?>" <?= $canGrade ? '' : 'disabled' ?>></div>
              <div class="md:col-span-3"><label class="label">Note (internal)</label><input class="input input-sm" name="note[<?= $qid ?>]" value="<?= e($a['grader_note'] ?? '') ?>" maxlength="500" <?= $canGrade ? '' : 'disabled' ?>></div>
            </div>
            <?php if ($a['marks_awarded'] === null): ?><p class="text-[11px] text-violet-600 mt-1">Not marked yet.</p><?php endif; ?>
          <?php endif; ?>
        <?php else: ?>
          <ul class="space-y-1">
            <?php foreach ($options[$qid] ?? [] as $o): $chosen = in_array((int) $o['id'], $picked, true); ?>
              <li class="flex items-start gap-2 text-sm px-2 py-1 rounded <?= $chosen ? ($o['is_correct'] ? 'bg-emerald-50' : 'bg-rose-50') : '' ?>">
                <span class="mt-0.5 shrink-0 w-4 text-center"><?= $chosen ? '●' : '○' ?></span>
                <span class="whitespace-pre-line <?= $o['is_correct'] ? 'text-emerald-700 font-semibold' : 'text-slate-700' ?>"><?= e($o['option_text']) ?></span>
                <?php if ($o['is_correct']): ?><span class="text-[10px] font-bold text-emerald-600 uppercase mt-1">correct</span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($canGrade && array_filter($questions, fn($q) => $q['qtype'] === 'descriptive')): ?>
    <div class="sticky bottom-4 mt-4 flex justify-end"><button class="btn-primary shadow-lg"><?= icon('check') ?> Save marks</button></div>
  <?php endif; ?>
</form>
<?php require APP_ROOT . '/app/layout/footer.php';
