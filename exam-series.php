<?php
/**
 * Online exam: question series - sets of questions drawn from the master question bank.
 * Each participant is given one series (spread evenly) so neighbours get different papers.
 * With no series, every participant gets the whole bank.
 */
require __DIR__ . '/app/bootstrap.php';
$exam = load_exam();
$id = (int) $exam['id'];
$locked = exam_is_locked($id);
$questions = exam_questions($id);
$qById = array_column($questions, null, 'id');

function exam_series_save(int $examId, ?int $sid, string $name, array $qids): int
{
    if ($sid) {
        db()->prepare('UPDATE exam_series SET name = ? WHERE id = ? AND exam_id = ?')->execute([$name, $sid, $examId]);
        db()->prepare('DELETE FROM exam_series_questions WHERE series_id = ?')->execute([$sid]);
    } else {
        db()->prepare('INSERT INTO exam_series (exam_id, name) VALUES (?, ?)')->execute([$examId, $name]);
        $sid = (int) db()->lastInsertId();
    }
    $ins = db()->prepare('INSERT INTO exam_series_questions (series_id, question_id, sort_order) VALUES (?,?,?)');
    foreach (array_values($qids) as $i => $qid) {
        $ins->execute([$sid, $qid, $i + 1]);
    }
    return $sid;
}

if (is_post()) {
    verify_csrf();
    if ($locked) {
        flash('error', 'Question series are locked because participants have started the exam.');
        redirect('exam-series', ['id' => $id]);
    }
    $action = input('action');
    $sid = (int) int_input('sid', 0) ?: null;
    if ($sid) {
        $st = db()->prepare('SELECT id FROM exam_series WHERE id = ? AND exam_id = ?');
        $st->execute([$sid, $id]);
        if (!$st->fetch()) forbidden('Series not found.');
    }
    if ($action === 'delete' && $sid) {
        db()->prepare('DELETE FROM exam_series WHERE id = ?')->execute([$sid]);
        flash('success', 'Series deleted.');
        redirect('exam-series', ['id' => $id]);
    }
    if ($action === 'generate') {
        // Several random series at once: N questions of each type per series
        $count = max(1, min(50, (int) int_input('gen_count', 1)));
        $want = [];
        foreach (array_keys(EXAM_QTYPES) as $t) $want[$t] = max(0, (int) int_input("gen_{$t}", 0));
        if (!array_sum($want)) {
            flash('error', 'Enter how many questions of each type every series should have.');
            redirect('exam-series', ['id' => $id, 'generate' => 1]);
        }
        foreach ($want as $t => $n) {
            $have = count(array_filter($questions, fn($q) => $q['qtype'] === $t));
            if ($n > $have) {
                flash('error', "The bank has only {$have} " . strtolower(EXAM_QTYPES[$t]) . " question(s); {$n} requested.");
                redirect('exam-series', ['id' => $id, 'generate' => 1]);
            }
        }
        $st = db()->prepare('SELECT COUNT(*) FROM exam_series WHERE exam_id = ?');
        $st->execute([$id]);
        $start = (int) $st->fetchColumn();
        db()->beginTransaction();
        for ($s = 1; $s <= $count; $s++) {
            $pick = [];
            foreach ($want as $t => $n) {
                $pool = array_column(array_filter($questions, fn($q) => $q['qtype'] === $t), 'id');
                shuffle($pool);
                $pick = array_merge($pick, array_slice($pool, 0, $n));
            }
            usort($pick, fn($a, $b) => [$qById[$a]['sort_order'], $a] <=> [$qById[$b]['sort_order'], $b]);
            exam_series_save($id, null, 'Series ' . chr(65 + (($start + $s - 1) % 26)) . ($start + $s > 26 ? (string) intdiv($start + $s - 1, 26) : ''), $pick);
        }
        db()->commit();
        flash('success', "{$count} random series generated.");
        redirect('exam-series', ['id' => $id]);
    }

    $name = mb_substr(trim((string) input('name')), 0, 120);
    $qids = array_values(array_filter(array_unique(array_map('intval', (array) ($_POST['questions'] ?? []))), fn($q) => isset($qById[$q])));
    $errors = [];
    if ($name === '') $errors[] = 'Series name is required.';
    if (!$qids) $errors[] = 'Select at least one question from the bank.';
    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        $_SESSION['_old'] = $_POST;
        redirect('exam-series', ['id' => $id] + ($sid ? ['edit' => $sid] : ['new' => 1]));
    }
    usort($qids, fn($a, $b) => [$qById[$a]['sort_order'], $a] <=> [$qById[$b]['sort_order'], $b]);
    db()->beginTransaction();
    exam_series_save($id, $sid, $name, $qids);
    db()->commit();
    flash('success', $sid ? 'Series updated.' : 'Series created.');
    redirect('exam-series', ['id' => $id]);
}

$st = db()->prepare('SELECT s.*, (SELECT COUNT(*) FROM exam_participants p WHERE p.series_id = s.id) AS assigned FROM exam_series s WHERE s.exam_id = ? ORDER BY s.id');
$st->execute([$id]);
$series = $st->fetchAll();
$members = [];
if ($series) {
    $st = db()->prepare('SELECT series_id, question_id FROM exam_series_questions WHERE series_id IN (' . implode(',', array_fill(0, count($series), '?')) . ') ORDER BY sort_order');
    $st->execute(array_column($series, 'id'));
    foreach ($st->fetchAll() as $r) $members[(int) $r['series_id']][] = (int) $r['question_id'];
}
$editing = null;
if ($editId = int_input('edit')) {
    foreach ($series as $s) if ((int) $s['id'] === $editId) $editing = $s;
}
$showForm = !$locked && ($editing || isset($_GET['new']));
$showGen = !$locked && isset($_GET['generate']);
$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
$picked = isset($old['questions']) ? array_map('intval', (array) $old['questions']) : ($editing ? ($members[(int) $editing['id']] ?? []) : []);
$qNo = array_flip(array_column($questions, 'id'));   // question id => position in the bank

$pageTitle = $exam['title'] . ' · Question series';
$extraScripts = ['<script src="' . e(asset('assets/js/exam-admin.js')) . '"></script>'];
require APP_ROOT . '/app/layout/header.php';
$examTab = 'exam-series';
require APP_ROOT . '/app/layout/exam_nav.php';
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-4">
  <div>
    <h2 class="text-lg font-bold text-slate-900">Question series</h2>
    <p class="text-sm text-slate-500">Sets of questions from the master bank. Each participant receives one series when they start, spread evenly across the series. Without any series, everyone gets the whole bank.</p>
  </div>
  <?php if (!$locked && !$showForm && !$showGen && $questions): ?>
    <div class="flex gap-2 shrink-0">
      <a href="<?= e(url('exam-series', ['id' => $id, 'generate' => 1])) ?>" class="btn-secondary"><?= icon('bolt') ?> Generate random series</a>
      <a href="<?= e(url('exam-series', ['id' => $id, 'new' => 1])) ?>" class="btn-primary"><?= icon('plus') ?> New series</a>
    </div>
  <?php endif; ?>
</div>
<?php if ($locked): ?>
  <div class="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900 flex items-center gap-2"><?= icon('info') ?> Locked: participants have started this exam, so the series can no longer be changed.</div>
<?php endif; ?>
<?php if (!$questions): ?>
  <div class="card text-center py-10"><p class="text-slate-500">Add questions to the <a class="link" href="<?= e(url('exam-questions', ['id' => $id])) ?>">question bank</a> first.</p></div>
<?php endif; ?>

<?php if ($showGen): ?>
<form method="post" class="card mb-6">
  <?= csrf_field() ?><input type="hidden" name="action" value="generate">
  <h3 class="card-title mb-1">Generate random series</h3>
  <p class="text-xs text-slate-500 mb-4">Creates several series at once, each with the given number of randomly chosen questions of every type.</p>
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div><label class="label">Number of series</label><input class="input" type="number" name="gen_count" min="1" max="50" value="3"></div>
    <?php foreach (EXAM_QTYPES as $t => $lbl): $have = count(array_filter($questions, fn($q) => $q['qtype'] === $t)); ?>
      <div><label class="label"><?= e($lbl) ?></label><input class="input" type="number" name="gen_<?= $t ?>" min="0" max="<?= $have ?>" value="<?= min($have, $t === 'descriptive' ? 0 : 5) ?>"><span class="hint">of <?= $have ?> in the bank</span></div>
    <?php endforeach; ?>
  </div>
  <div class="mt-5 flex justify-end gap-2">
    <a href="<?= e(url('exam-series', ['id' => $id])) ?>" class="btn-secondary">Cancel</a>
    <button class="btn-primary">Generate</button>
  </div>
</form>
<?php endif; ?>

<?php if ($showForm): ?>
<form method="post" class="card mb-6">
  <?= csrf_field() ?><input type="hidden" name="sid" value="<?= e($editing['id'] ?? '') ?>">
  <h3 class="card-title mb-5"><?= $editing ? 'Edit series' : 'New series' ?></h3>
  <div class="grid grid-cols-1 md:grid-cols-3 gap-5 mb-4">
    <div><label class="label">Series name <span class="req">*</span></label><input class="input" name="name" value="<?= e($old['name'] ?? $editing['name'] ?? 'Series ' . chr(65 + count($series) % 26)) ?>" maxlength="120" required></div>
    <div class="md:col-span-2 flex flex-wrap items-end gap-2">
      <div><label class="label">Pick at random</label><input class="input input-sm w-20" type="number" min="1" id="series-random-n" value="10"></div>
      <select class="input input-sm w-auto" id="series-random-type" aria-label="Question type"><option value="">Any type</option><?php foreach (EXAM_QTYPES as $k => $lbl): ?><option value="<?= $k ?>"><?= e($lbl) ?></option><?php endforeach; ?></select>
      <button type="button" class="btn-secondary btn-xs" data-series-pick="random" data-no-busy>Pick</button>
      <button type="button" class="btn-secondary btn-xs" data-series-pick="all" data-no-busy>Select all</button>
      <button type="button" class="btn-secondary btn-xs" data-series-pick="none" data-no-busy>Clear</button>
    </div>
  </div>
  <p class="text-xs text-slate-500 mb-2"><strong id="series-count">0</strong> questions selected · <strong id="series-marks">0</strong> marks</p>
  <div id="series-picker" class="border border-slate-200 rounded-xl divide-y divide-slate-100 max-h-[28rem] overflow-auto">
    <?php foreach ($questions as $n => $q): ?>
      <label class="flex items-start gap-3 p-3 hover:bg-slate-50 cursor-pointer">
        <input type="checkbox" name="questions[]" value="<?= $q['id'] ?>" data-type="<?= $q['qtype'] ?>" data-marks="<?= exam_num($q['marks']) ?>" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600" <?= in_array((int) $q['id'], $picked, true) ? 'checked' : '' ?>>
        <span class="min-w-0 text-sm"><span class="text-[11px] font-semibold text-slate-500">Q<?= $n + 1 ?> · <?= e(EXAM_QTYPES[$q['qtype']]) ?> · <?= exam_num($q['marks']) ?> marks</span><span class="block text-slate-800 truncate"><?= e(mb_strimwidth($q['question'], 0, 160, '…')) ?></span></span>
      </label>
    <?php endforeach; ?>
  </div>
  <div class="mt-6 pt-5 border-t border-slate-100 flex justify-end gap-2">
    <a href="<?= e(url('exam-series', ['id' => $id])) ?>" class="btn-secondary">Cancel</a>
    <button class="btn-primary"><?= $editing ? 'Save series' : 'Create series' ?></button>
  </div>
</form>
<?php endif; ?>

<?php if (!$series && $questions && !$showForm && !$showGen): ?>
  <div class="card text-center py-8 text-sm text-slate-500">No series defined — every participant gets all <?= count($questions) ?> questions of the bank<?= $exam['shuffle_questions'] ? ' in shuffled order' : '' ?>.</div>
<?php endif; ?>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
  <?php foreach ($series as $s):
      $qs = $members[(int) $s['id']] ?? [];
      $marks = array_sum(array_map(fn($q) => (float) ($qById[$q]['marks'] ?? 0), $qs));
      $types = array_count_values(array_map(fn($q) => $qById[$q]['qtype'] ?? '', $qs)); ?>
    <div class="card p-4">
      <div class="flex items-start justify-between gap-2">
        <div>
          <h3 class="font-bold text-slate-900"><?= e($s['name']) ?></h3>
          <p class="text-xs text-slate-500"><?= count($qs) ?> questions · <?= exam_num($marks) ?> marks · <?= (int) $s['assigned'] ?> participant(s)</p>
        </div>
        <?php if (!$locked): ?>
          <div class="flex shrink-0">
            <a href="<?= e(url('exam-series', ['id' => $id, 'edit' => $s['id']])) ?>" class="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100" title="Edit"><?= icon('pencil') ?></a>
            <form method="post" data-confirm="Delete <?= e($s['name']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="sid" value="<?= $s['id'] ?>"><button class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50" title="Delete"><?= icon('trash') ?></button></form>
          </div>
        <?php endif; ?>
      </div>
      <p class="text-[11px] text-slate-500 mt-2"><?= (int) ($types['single'] ?? 0) ?> single · <?= (int) ($types['multi'] ?? 0) ?> multiple · <?= (int) ($types['descriptive'] ?? 0) ?> descriptive</p>
      <p class="text-[11px] text-slate-400 mt-1 break-words">Questions: <?= e(implode(', ', array_map(fn($q) => 'Q' . (($qNo[$q] ?? 0) + 1), $qs))) ?></p>
    </div>
  <?php endforeach; ?>
</div>
<?php if ($series && count(array_unique(array_map(fn($s) => (string) array_sum(array_map(fn($q) => (float) ($qById[$q]['marks'] ?? 0), $members[(int) $s['id']] ?? [])), $series))) > 1): ?>
  <p class="mt-4 text-xs text-amber-700 flex items-center gap-1.5"><?= icon('warning', 'w-3.5 h-3.5') ?> The series carry different total marks; results also show percentages for a fair comparison.</p>
<?php endif; ?>
<?php require APP_ROOT . '/app/layout/footer.php';
