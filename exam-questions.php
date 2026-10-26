<?php
/** Online exam: master question bank (single selection, multiple selection, descriptive). */
require __DIR__ . '/app/bootstrap.php';
$exam = load_exam();
$id = (int) $exam['id'];
$locked = exam_is_locked($id);

/** Validate one question; returns [question row, options, errors]. */
function exam_question_validate(string $type, string $text, mixed $marks, array $options): array
{
    $errors = [];
    $type = array_key_exists($type, EXAM_QTYPES) ? $type : '';
    $text = trim(mb_substr($text, 0, 5000));
    $marks = is_numeric($marks) ? round((float) $marks, 2) : 0;
    $clean = [];
    foreach ($options as $o) {
        $t = trim(mb_substr((string) ($o['text'] ?? ''), 0, 2000));
        if ($t !== '') $clean[] = ['text' => $t, 'correct' => !empty($o['correct']) ? 1 : 0];
    }
    if (!$type) $errors[] = 'Choose the question type.';
    if ($text === '') $errors[] = 'Question text is required.';
    if ($marks <= 0 || $marks > 1000) $errors[] = 'Marks must be more than 0 (up to 1000).';
    $correct = count(array_filter($clean, fn($o) => $o['correct']));
    if ($type === 'descriptive') {
        $clean = [];
    } elseif ($type) {
        if (count($clean) < 2) $errors[] = 'An objective question needs at least two options.';
        if ($type === 'single' && $correct !== 1) $errors[] = 'Mark exactly one correct option for a single-selection question.';
        if ($type === 'multi' && $correct < 1) $errors[] = 'Mark at least one correct option for a multiple-selection question.';
    }
    return [['qtype' => $type, 'question' => $text, 'marks' => $marks], $clean, $errors];
}

function exam_question_save(int $examId, ?int $qid, array $q, array $options): void
{
    if ($qid) {
        db()->prepare('UPDATE exam_questions SET qtype = ?, question = ?, marks = ? WHERE id = ? AND exam_id = ?')
            ->execute([$q['qtype'], $q['question'], $q['marks'], $qid, $examId]);
        db()->prepare('DELETE FROM exam_options WHERE question_id = ?')->execute([$qid]);
    } else {
        $st = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM exam_questions WHERE exam_id = ?');
        $st->execute([$examId]);
        db()->prepare('INSERT INTO exam_questions (exam_id, qtype, question, marks, sort_order) VALUES (?,?,?,?,?)')
            ->execute([$examId, $q['qtype'], $q['question'], $q['marks'], (int) $st->fetchColumn()]);
        $qid = (int) db()->lastInsertId();
    }
    $ins = db()->prepare('INSERT INTO exam_options (question_id, option_text, is_correct, sort_order) VALUES (?,?,?,?)');
    foreach ($options as $i => $o) {
        $ins->execute([$qid, $o['text'], $o['correct'], $i + 1]);
    }
}

/**
 * Bulk import. Questions are separated by a blank line:
 *   first line(s) = question, "* text" = correct option, "- text" = other option,
 *   optional "marks: 2" and "type: single|multi|descriptive" lines.
 * Without a type line: no options = descriptive, one correct = single, several = multi.
 */
function exam_parse_bulk(string $raw): array
{
    $blocks = preg_split('/\R\s*\R/', trim(str_replace("\r\n", "\n", $raw))) ?: [];
    $out = [];
    foreach ($blocks as $n => $block) {
        $text = [];
        $opts = [];
        $marks = 1;
        $type = null;
        foreach (preg_split('/\R/', trim($block)) as $line) {
            $line = trim($line);
            if (preg_match('/^marks?\s*:\s*([\d.]+)$/i', $line, $m)) { $marks = $m[1]; continue; }
            if (preg_match('/^type\s*:\s*(single|multi|descriptive)$/i', $line, $m)) { $type = strtolower($m[1]); continue; }
            if (preg_match('/^([*\-])\s*(.+)$/', $line, $m) && ($text || $opts)) { $opts[] = ['text' => $m[2], 'correct' => $m[1] === '*']; continue; }
            if (!$opts) $text[] = preg_replace('/^(Q\d*[.:)]|\d+[.)])\s*/i', '', $line);
        }
        $correct = count(array_filter($opts, fn($o) => $o['correct']));
        $type ??= !$opts ? 'descriptive' : ($correct > 1 ? 'multi' : 'single');
        $out[] = ['n' => $n + 1, 'v' => exam_question_validate($type, implode("\n", $text), $marks, $opts)];
    }
    return $out;
}

if (is_post()) {
    verify_csrf();
    if ($locked) {
        flash('error', 'The question bank is locked because participants have started the exam.');
        redirect('exam-questions', ['id' => $id]);
    }
    $action = input('action');
    if ($action === 'delete') {
        db()->prepare('DELETE FROM exam_questions WHERE id = ? AND exam_id = ?')->execute([(int) int_input('qid', 0), $id]);
        flash('success', 'Question deleted (and removed from any series).');
        redirect('exam-questions', ['id' => $id]);
    }
    if ($action === 'bulk') {
        $parsed = exam_parse_bulk((string) input('bulk'));
        $bad = array_filter($parsed, fn($p) => $p['v'][2]);
        if (!$parsed || $bad) {
            foreach (array_slice($bad, 0, 5) as $p) flash('error', "Question {$p['n']}: " . implode(' ', $p['v'][2]));
            if (!$parsed) flash('error', 'Paste at least one question.');
            $_SESSION['_old'] = ['bulk' => (string) input('bulk')];
            redirect('exam-questions', ['id' => $id, 'bulk' => 1]);
        }
        db()->beginTransaction();
        foreach ($parsed as $p) exam_question_save($id, null, $p['v'][0], $p['v'][1]);
        db()->commit();
        flash('success', count($parsed) . ' question(s) imported.');
        redirect('exam-questions', ['id' => $id]);
    }
    $qid = (int) int_input('qid', 0) ?: null;
    if ($qid) {
        $st = db()->prepare('SELECT id FROM exam_questions WHERE id = ? AND exam_id = ?');
        $st->execute([$qid, $id]);
        if (!$st->fetch()) forbidden('Question not found.');
    }
    [$q, $options, $errors] = exam_question_validate((string) input('qtype'), (string) input('question'), input('marks'), array_values((array) ($_POST['options'] ?? [])));
    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        $_SESSION['_old'] = $_POST;
        redirect('exam-questions', ['id' => $id] + ($qid ? ['edit' => $qid] : ['new' => 1]));
    }
    db()->beginTransaction();
    exam_question_save($id, $qid, $q, $options);
    db()->commit();
    flash('success', $qid ? 'Question updated.' : 'Question added to the bank.');
    redirect('exam-questions', ['id' => $id] + ($qid ? [] : ['new' => 1]));
}

$questions = exam_questions($id);
$editing = null;
if ($editId = int_input('edit')) {
    foreach ($questions as $q) if ((int) $q['id'] === $editId) $editing = $q;
}
$showForm = !$locked && ($editing || isset($_GET['new']));
$showBulk = !$locked && isset($_GET['bulk']);
$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
$formType = $old['qtype'] ?? $editing['qtype'] ?? 'single';
$formOptions = isset($old['options']) ? array_values($old['options'])
    : ($editing ? array_map(fn($o) => ['text' => $o['option_text'], 'correct' => $o['is_correct'] ? '1' : ''], $editing['options']) : []);
while (count($formOptions) < 4 && !$editing && !isset($old['options'])) $formOptions[] = ['text' => '', 'correct' => ''];
$totals = ['count' => count($questions), 'marks' => array_sum(array_map(fn($q) => (float) $q['marks'], $questions))];
foreach (EXAM_QTYPES as $k => $_) $totals[$k] = count(array_filter($questions, fn($q) => $q['qtype'] === $k));

$pageTitle = $exam['title'] . ' · Question bank';
$extraScripts = ['<script src="' . e(asset('assets/js/exam-admin.js')) . '"></script>'];
require APP_ROOT . '/app/layout/header.php';
$examTab = 'exam-questions';
require APP_ROOT . '/app/layout/exam_nav.php';
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-4">
  <div>
    <h2 class="text-lg font-bold text-slate-900">Master question bank</h2>
    <p class="text-sm text-slate-500"><?= num($totals['count']) ?> questions · <?= exam_num($totals['marks']) ?> marks · <?= $totals['single'] ?> single selection · <?= $totals['multi'] ?> multiple selection · <?= $totals['descriptive'] ?> descriptive</p>
  </div>
  <?php if (!$locked && !$showForm && !$showBulk): ?>
    <div class="flex gap-2">
      <a href="<?= e(url('exam-questions', ['id' => $id, 'bulk' => 1])) ?>" class="btn-secondary"><?= icon('clipboard') ?> Bulk import</a>
      <a href="<?= e(url('exam-questions', ['id' => $id, 'new' => 1])) ?>" class="btn-primary"><?= icon('plus') ?> Add question</a>
    </div>
  <?php endif; ?>
</div>
<?php if ($locked): ?>
  <div class="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900 flex items-center gap-2"><?= icon('info') ?> Locked: participants have started this exam, so questions can no longer be changed.</div>
<?php endif; ?>

<?php if ($showForm): ?>
<form method="post" class="card mb-6" id="question-form">
  <?= csrf_field() ?><input type="hidden" name="qid" value="<?= e($editing['id'] ?? '') ?>">
  <h3 class="card-title mb-5"><?= $editing ? 'Edit question' : 'Add a question' ?></h3>
  <div class="grid grid-cols-1 md:grid-cols-4 gap-5">
    <div class="md:col-span-3">
      <label class="label">Question type <span class="req">*</span></label>
      <select class="input" name="qtype" id="q-type">
        <?php foreach (EXAM_QTYPES as $k => $lbl): ?><option value="<?= $k ?>" <?= $formType === $k ? 'selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div><label class="label">Marks <span class="req">*</span></label><input class="input" type="number" name="marks" min="0.25" max="1000" step="0.25" value="<?= e($old['marks'] ?? $editing['marks'] ?? '1') ?>" required></div>
    <div class="md:col-span-4"><label class="label">Question <span class="req">*</span></label><textarea class="input" name="question" rows="3" required><?= e($old['question'] ?? $editing['question'] ?? '') ?></textarea></div>
  </div>
  <div class="mt-5" id="q-options">
    <div class="flex items-center justify-between mb-2">
      <p class="label mb-0">Options <span class="text-slate-400 font-normal normal-case" id="q-options-hint">— tick the correct answer</span></p>
      <button type="button" class="btn-secondary btn-xs" id="q-add-option" data-no-busy><?= icon('plus', 'w-3.5 h-3.5') ?> Add option</button>
    </div>
    <div class="space-y-2" id="q-option-list" data-next="<?= count($formOptions) ?>">
      <?php foreach ($formOptions as $i => $o): ?>
        <div class="flex items-start gap-2" data-option-row>
          <label class="flex items-center gap-1.5 pt-2.5 text-xs text-slate-500 shrink-0 w-20"><input type="checkbox" name="options[<?= $i ?>][correct]" value="1" class="h-4 w-4 rounded border-slate-300 text-emerald-600" data-correct <?= !empty($o['correct']) ? 'checked' : '' ?>> Correct</label>
          <textarea class="input" rows="1" name="options[<?= $i ?>][text]" placeholder="Option text"><?= e($o['text']) ?></textarea>
          <button type="button" class="p-2 mt-0.5 rounded-lg text-rose-500 hover:bg-rose-50" data-remove-option aria-label="Remove option"><?= icon('x') ?></button>
        </div>
      <?php endforeach; ?>
    </div>
    <template id="q-option-template">
      <div class="flex items-start gap-2" data-option-row>
        <label class="flex items-center gap-1.5 pt-2.5 text-xs text-slate-500 shrink-0 w-20"><input type="checkbox" name="options[__i__][correct]" value="1" class="h-4 w-4 rounded border-slate-300 text-emerald-600" data-correct> Correct</label>
        <textarea class="input" rows="1" name="options[__i__][text]" placeholder="Option text"></textarea>
        <button type="button" class="p-2 mt-0.5 rounded-lg text-rose-500 hover:bg-rose-50" data-remove-option aria-label="Remove option"><?= icon('x') ?></button>
      </div>
    </template>
  </div>
  <p class="hint mt-3 hidden" id="q-descriptive-hint">Descriptive answers are marked by the exam admins on the Results tab.</p>
  <div class="mt-6 pt-5 border-t border-slate-100 flex justify-end gap-2">
    <a href="<?= e(url('exam-questions', ['id' => $id])) ?>" class="btn-secondary">Close</a>
    <button class="btn-primary"><?= $editing ? 'Save question' : 'Add question' ?></button>
  </div>
</form>
<?php endif; ?>

<?php if ($showBulk): ?>
<form method="post" class="card mb-6">
  <?= csrf_field() ?><input type="hidden" name="action" value="bulk">
  <h3 class="card-title mb-1">Bulk import questions</h3>
  <p class="text-xs text-slate-500 mb-4">Separate questions with a blank line. Start correct options with <code class="font-mono bg-slate-100 px-1 rounded">*</code> and other options with <code class="font-mono bg-slate-100 px-1 rounded">-</code>. Optional lines: <code class="font-mono bg-slate-100 px-1 rounded">marks: 2</code>, <code class="font-mono bg-slate-100 px-1 rounded">type: multi</code>. A question without options is descriptive; several correct options make it multiple selection.</p>
  <textarea class="input font-mono text-xs" name="bulk" rows="14" placeholder="Which city is the capital of Kerala?
* Thiruvananthapuram
- Kochi
- Kozhikode

Which of these are programming languages?
marks: 2
* Python
* Java
- HTML

Describe your career goals in 100 words.
marks: 5"><?= e($old['bulk'] ?? '') ?></textarea>
  <div class="mt-5 flex justify-end gap-2">
    <a href="<?= e(url('exam-questions', ['id' => $id])) ?>" class="btn-secondary">Cancel</a>
    <button class="btn-primary">Import questions</button>
  </div>
</form>
<?php endif; ?>

<div class="space-y-3">
  <?php foreach ($questions as $n => $q): ?>
    <div class="card p-4">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1">Q<?= $n + 1 ?> · <?= e(EXAM_QTYPES[$q['qtype']]) ?> · <?= exam_num($q['marks']) ?> mark<?= (float) $q['marks'] === 1.0 ? '' : 's' ?></p>
          <p class="text-sm text-slate-900 whitespace-pre-line"><?= e($q['question']) ?></p>
          <?php if ($q['options']): ?>
            <ul class="mt-2 space-y-1">
              <?php foreach ($q['options'] as $o): ?>
                <li class="flex items-start gap-2 text-xs <?= $o['is_correct'] ? 'text-emerald-700 font-semibold' : 'text-slate-600' ?>"><span class="mt-0.5 shrink-0"><?= $o['is_correct'] ? icon('check-circle', 'w-3.5 h-3.5') : '<span class="inline-block w-3.5 h-3.5 rounded-full border border-slate-300"></span>' ?></span><span class="whitespace-pre-line"><?= e($o['option_text']) ?></span></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <?php if (!$locked): ?>
          <div class="flex shrink-0">
            <a href="<?= e(url('exam-questions', ['id' => $id, 'edit' => $q['id']])) ?>" class="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100" title="Edit"><?= icon('pencil') ?></a>
            <form method="post" data-confirm="Delete this question? It is also removed from every series.">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="qid" value="<?= $q['id'] ?>">
              <button class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50" title="Delete"><?= icon('trash') ?></button>
            </form>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$questions): ?><div class="card text-center text-slate-400 py-10">The question bank is empty.</div><?php endif; ?>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
