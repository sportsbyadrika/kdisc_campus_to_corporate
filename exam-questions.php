<?php
/** Online assessment: master question bank (single selection, multiple selection, descriptive, case study). */
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

function exam_question_save(int $examId, ?int $qid, array $q, array $options, ?int $parentId = null): void
{
    if ($qid) {
        db()->prepare('UPDATE exam_questions SET qtype = ?, question = ?, marks = ? WHERE id = ? AND exam_id = ?')
            ->execute([$q['qtype'], $q['question'], $q['marks'], $qid, $examId]);
        db()->prepare('DELETE FROM exam_options WHERE question_id = ?')->execute([$qid]);
    } else {
        // new questions go last: in the bank, or in their case study
        $st = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM exam_questions WHERE exam_id = ? AND parent_id ' . ($parentId ? '= ?' : 'IS NULL'));
        $st->execute($parentId ? [$examId, $parentId] : [$examId]);
        db()->prepare('INSERT INTO exam_questions (exam_id, parent_id, qtype, question, marks, sort_order) VALUES (?,?,?,?,?,?)')
            ->execute([$examId, $parentId, $q['qtype'], $q['question'], $q['marks'], (int) $st->fetchColumn()]);
        $qid = (int) db()->lastInsertId();
    }
    $ins = db()->prepare('INSERT INTO exam_options (question_id, option_text, is_correct, sort_order) VALUES (?,?,?,?)');
    foreach ($options as $i => $o) {
        $ins->execute([$qid, $o['text'], $o['correct'], $i + 1]);
    }
    if ($parentId) {
        exam_case_sync_marks($parentId);
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
        flash('error', 'The question bank is locked because participants have started the assessment.');
        redirect('exam-questions', ['id' => $id]);
    }
    $action = input('action');
    $find = function (int $qid) use ($id): ?array {
        $st = db()->prepare('SELECT * FROM exam_questions WHERE id = ? AND exam_id = ?');
        $st->execute([$qid, $id]);
        return $st->fetch() ?: null;
    };
    if ($action === 'delete') {
        $q = $find((int) int_input('qid', 0));
        if ($q) {
            db()->prepare('DELETE FROM exam_questions WHERE id = ?')->execute([$q['id']]);
            if ($q['parent_id']) exam_case_sync_marks((int) $q['parent_id']);
        }
        flash('success', !$q ? 'Question not found.' : ($q['qtype'] === EXAM_CASE ? 'Case study and its questions deleted (and removed from any series).' : 'Question deleted' . ($q['parent_id'] ? ' from the case study.' : ' (and removed from any series).')));
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
    if ($action === 'case') {
        // Case study scenario (its marks are the total of its questions)
        $qid = (int) int_input('qid', 0) ?: null;
        $case = $qid ? $find($qid) : null;
        if ($qid && (!$case || $case['qtype'] !== EXAM_CASE)) forbidden('Case study not found.');
        $text = trim(mb_substr((string) input('question'), 0, 20000));
        if ($text === '') {
            flash('error', 'Write the case scenario.');
            $_SESSION['_old'] = $_POST;
            redirect('exam-questions', ['id' => $id] + ($qid ? ['edit' => $qid] : ['case' => 'new']));
        }
        if ($case) {
            db()->prepare('UPDATE exam_questions SET question = ? WHERE id = ?')->execute([$text, $qid]);
            flash('success', 'Case scenario updated.');
            redirect('exam-questions', ['id' => $id]);
        }
        exam_question_save($id, null, ['qtype' => EXAM_CASE, 'question' => $text, 'marks' => 0], []);
        $newId = (int) db()->lastInsertId();
        flash('success', 'Case study added. Now add its questions.');
        redirect('exam-questions', ['id' => $id, 'sub' => $newId]);
    }
    $qid = (int) int_input('qid', 0) ?: null;
    $current = $qid ? $find($qid) : null;
    if ($qid && (!$current || $current['qtype'] === EXAM_CASE)) forbidden('Question not found.');
    // A question inside a case study: its case comes from the stored row, or from the form when new
    $parentId = $current ? ((int) $current['parent_id'] ?: null) : ((int) int_input('parent_id', 0) ?: null);
    if ($parentId && !(($p = $find($parentId)) && $p['qtype'] === EXAM_CASE)) forbidden('Case study not found.');
    [$q, $options, $errors] = exam_question_validate((string) input('qtype'), (string) input('question'), input('marks'), array_values((array) ($_POST['options'] ?? [])));
    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        $_SESSION['_old'] = $_POST;
        redirect('exam-questions', ['id' => $id] + ($qid ? ['edit' => $qid] : ($parentId ? ['sub' => $parentId] : ['new' => 1])));
    }
    db()->beginTransaction();
    exam_question_save($id, $qid, $q, $options, $parentId);
    db()->commit();
    flash('success', $qid ? 'Question updated.' : ($parentId ? 'Question added to the case study.' : 'Question added to the bank.'));
    redirect('exam-questions', ['id' => $id] + ($qid ? [] : ($parentId ? ['sub' => $parentId] : ['new' => 1])));
}

$questions = exam_questions($id);
$byId = [];
foreach ($questions as $q) {
    $byId[(int) $q['id']] = $q;
    foreach ($q['subs'] as $sq) $byId[(int) $sq['id']] = $sq;
}
$editing = ($editId = int_input('edit')) ? ($byId[$editId] ?? null) : null;
// Which form is open: a question (top level or inside a case), or a case scenario
$caseFor = ($subId = int_input('sub')) && isset($byId[$subId]) && $byId[$subId]['qtype'] === EXAM_CASE ? $byId[$subId] : null;
if ($editing && $editing['parent_id']) $caseFor = $byId[(int) $editing['parent_id']] ?? null;
$showCaseForm = !$locked && (($editing && $editing['qtype'] === EXAM_CASE) || input('case') === 'new');
$showForm = !$locked && !$showCaseForm && ($editing || $caseFor || isset($_GET['new']));
$showBulk = !$locked && isset($_GET['bulk']);
$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
$formType = $old['qtype'] ?? $editing['qtype'] ?? 'single';
$formOptions = isset($old['options']) ? array_values($old['options'])
    : ($editing ? array_map(fn($o) => ['text' => $o['option_text'], 'correct' => $o['is_correct'] ? '1' : ''], $editing['options']) : []);
while (count($formOptions) < 4 && !$editing && !isset($old['options'])) $formOptions[] = ['text' => '', 'correct' => ''];
$totals = ['count' => count($questions), 'marks' => array_sum(array_map(fn($q) => (float) $q['marks'], $questions))];
foreach ([...array_keys(EXAM_QTYPES), EXAM_CASE] as $k) $totals[$k] = count(array_filter($questions, fn($q) => $q['qtype'] === $k));
$letter = fn(int $i) => '(' . chr(97 + $i % 26) . ')';
$caseNo = $caseFor ? array_search((int) $caseFor['id'], array_map(fn($q) => (int) $q['id'], $questions), true) + 1 : 0;

$pageTitle = $exam['title'] . ' · Question bank';
$extraScripts = ['<script src="' . e(asset('assets/js/exam-admin.js')) . '"></script>'];
require APP_ROOT . '/app/layout/header.php';
$examTab = 'exam-questions';
require APP_ROOT . '/app/layout/exam_nav.php';
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-4">
  <div>
    <h2 class="text-lg font-bold text-slate-900">Master question bank</h2>
    <p class="text-sm text-slate-500"><?= num($totals['count']) ?> questions · <?= exam_num($totals['marks']) ?> marks · <?= $totals['single'] ?> single selection · <?= $totals['multi'] ?> multiple selection · <?= $totals['descriptive'] ?> descriptive · <?= $totals[EXAM_CASE] ?> case stud<?= $totals[EXAM_CASE] === 1 ? 'y' : 'ies' ?></p>
  </div>
  <?php if (!$showForm && !$showBulk && !$showCaseForm): ?>
    <div class="flex flex-wrap gap-2">
      <?php if ($questions): ?><a href="<?= e(url('exam-print', ['id' => $id])) ?>" target="_blank" rel="noopener" class="btn-secondary" title="Whole bank with the answer key (print / PDF)"><?= icon('printer') ?> Print with answer key</a><?php endif; ?>
      <?php if (!$locked): ?>
      <a href="<?= e(url('exam-questions', ['id' => $id, 'bulk' => 1])) ?>" class="btn-secondary"><?= icon('clipboard') ?> Bulk import</a>
      <a href="<?= e(url('exam-questions', ['id' => $id, 'case' => 'new'])) ?>" class="btn-secondary"><?= icon('document') ?> Add case study</a>
      <a href="<?= e(url('exam-questions', ['id' => $id, 'new' => 1])) ?>" class="btn-primary"><?= icon('plus') ?> Add question</a>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php if ($locked): ?>
  <div class="mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900 flex items-center gap-2"><?= icon('info') ?> Locked: participants have started this assessment, so questions can no longer be changed.</div>
<?php endif; ?>

<?php if ($showForm): ?>
<form method="post" class="card mb-6" id="question-form">
  <?= csrf_field() ?><input type="hidden" name="qid" value="<?= e($editing['id'] ?? '') ?>"><input type="hidden" name="parent_id" value="<?= e($caseFor['id'] ?? '') ?>">
  <h3 class="card-title mb-1"><?= $editing ? 'Edit question' : ($caseFor ? 'Add a question to case study Q' . $caseNo : 'Add a question') ?></h3>
  <?php if ($caseFor): ?>
    <details class="mb-5 mt-2 rounded-xl border border-indigo-100 bg-indigo-50/50 px-4 py-2">
      <summary class="cursor-pointer text-xs font-semibold text-indigo-800">Case study Q<?= $caseNo ?> · <?= count($caseFor['subs']) ?> question(s) so far · <?= exam_num($caseFor['marks']) ?> marks — show scenario</summary>
      <div class="rich text-sm text-slate-700 mt-2"><?= exam_rich_text($caseFor['question']) ?></div>
    </details>
  <?php else: ?><div class="mb-5"></div><?php endif; ?>
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
  <p class="hint mt-3 hidden" id="q-descriptive-hint">Descriptive answers are marked by the assessment admins on the Results tab.</p>
  <div class="mt-6 pt-5 border-t border-slate-100 flex justify-end gap-2">
    <a href="<?= e(url('exam-questions', ['id' => $id])) ?>" class="btn-secondary">Close</a>
    <button class="btn-primary"><?= $editing ? 'Save question' : 'Add question' ?></button>
  </div>
</form>
<?php endif; ?>

<?php if ($showCaseForm): $caseText = $old['question'] ?? ($editing['question'] ?? ''); ?>
<form method="post" class="card mb-6">
  <?= csrf_field() ?><input type="hidden" name="action" value="case"><input type="hidden" name="qid" value="<?= e($editing['id'] ?? '') ?>">
  <h3 class="card-title mb-1"><?= $editing ? 'Edit case scenario' : 'Add a case study' ?></h3>
  <p class="text-xs text-slate-500 mb-4">Write the scenario here; add its questions (single selection, multiple selection or descriptive, each with its own marks) on the next step. The case study's marks are the total of its questions.</p>
  <div data-rich-editor>
    <div class="flex flex-wrap items-center gap-1 mb-2">
      <button type="button" class="btn-secondary btn-xs font-bold" data-rich="bold" title="Bold (Ctrl+B)" data-no-busy>B</button>
      <button type="button" class="btn-secondary btn-xs italic" data-rich="italic" title="Italic (Ctrl+I)" data-no-busy>I</button>
      <button type="button" class="btn-secondary btn-xs" data-rich="bullets" title="Bulleted list" data-no-busy>• Bullets</button>
      <button type="button" class="btn-secondary btn-xs" data-rich="numbers" title="Numbered list" data-no-busy>1. Numbering</button>
      <span class="text-[11px] text-slate-400 ml-2">**bold** · *italic* · lines starting with “- ” or “1. ” · blank line = new paragraph</span>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <textarea class="input font-mono text-[13px] leading-relaxed" name="question" rows="16" required data-rich-input placeholder="You must deliver a **Digital and AI Skill Readiness** session for 30 jobseekers…

- 10 participants are comfortable using computers
- 12 participants can do basic digital tasks
- 8 participants have limited computer experience"><?= e($caseText) ?></textarea>
      <div>
        <p class="label">Preview</p>
        <div class="rich text-sm text-slate-800 rounded-xl border border-slate-200 bg-slate-50 p-4 min-h-40" data-rich-preview><?= exam_rich_text($caseText) ?></div>
      </div>
    </div>
  </div>
  <div class="mt-6 pt-5 border-t border-slate-100 flex justify-end gap-2">
    <a href="<?= e(url('exam-questions', ['id' => $id])) ?>" class="btn-secondary">Cancel</a>
    <button class="btn-primary"><?= $editing ? 'Save scenario' : 'Save and add questions' ?></button>
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

<?php
/** One question card body: label line, text, options. */
$renderQuestion = function (array $q, string $label) {
    ?>
    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1"><?= e($label) ?> · <?= e(exam_qtype_label($q['qtype'])) ?> · <?= exam_num($q['marks']) ?> mark<?= (float) $q['marks'] === 1.0 ? '' : 's' ?></p>
    <p class="text-sm text-slate-900 whitespace-pre-line"><?= e($q['question']) ?></p>
    <?php if ($q['options']): ?>
      <ul class="mt-2 space-y-1">
        <?php foreach ($q['options'] as $o): ?>
          <li class="flex items-start gap-2 text-xs <?= $o['is_correct'] ? 'text-emerald-700 font-semibold' : 'text-slate-600' ?>"><span class="mt-0.5 shrink-0"><?= $o['is_correct'] ? icon('check-circle', 'w-3.5 h-3.5') : '<span class="inline-block w-3.5 h-3.5 rounded-full border border-slate-300"></span>' ?></span><span class="whitespace-pre-line"><?= e($o['option_text']) ?></span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif;
};
$actions = function (array $q, string $confirm) use ($locked, $id) {
    if ($locked) return;
    ?>
    <div class="flex shrink-0">
      <a href="<?= e(url('exam-questions', ['id' => $id, 'edit' => $q['id']])) ?>" class="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100" title="Edit"><?= icon('pencil') ?></a>
      <form method="post" data-confirm="<?= e($confirm) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="qid" value="<?= $q['id'] ?>">
        <button class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50" title="Delete"><?= icon('trash') ?></button>
      </form>
    </div>
    <?php
};
?>
<div class="space-y-3">
  <?php foreach ($questions as $n => $q): ?>
    <?php if ($q['qtype'] === EXAM_CASE): ?>
      <div class="card p-0 overflow-hidden border-indigo-200" id="q-<?= $q['id'] ?>">
        <div class="p-4 bg-indigo-50/60 border-b border-indigo-100 flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-indigo-700 mb-1">Q<?= $n + 1 ?> · <?= EXAM_CASE_LABEL ?> · <?= exam_num($q['marks']) ?> marks · <?= count($q['subs']) ?> question<?= count($q['subs']) === 1 ? '' : 's' ?></p>
            <div class="rich text-sm text-slate-800"><?= exam_rich_text($q['question']) ?></div>
          </div>
          <?php $actions($q, 'Delete this case study and all its questions? It is also removed from every series.'); ?>
        </div>
        <div class="divide-y divide-slate-100">
          <?php foreach ($q['subs'] as $i => $sq): ?>
            <div class="p-4 pl-6 flex items-start justify-between gap-3">
              <div class="min-w-0"><?php $renderQuestion($sq, 'Q' . ($n + 1) . $letter($i)); ?></div>
              <?php $actions($sq, 'Delete this question from the case study?'); ?>
            </div>
          <?php endforeach; ?>
          <?php if (!$q['subs']): ?><p class="p-4 pl-6 text-xs text-amber-700"><?= icon('warning', 'w-3.5 h-3.5 inline') ?> No questions yet — participants are not shown a case study until it has at least one question.</p><?php endif; ?>
          <?php if (!$locked): ?>
            <div class="p-3 pl-6"><a href="<?= e(url('exam-questions', ['id' => $id, 'sub' => $q['id']])) ?>" class="btn-secondary btn-xs"><?= icon('plus', 'w-3.5 h-3.5') ?> Add question to this case</a></div>
          <?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="card p-4" id="q-<?= $q['id'] ?>">
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0"><?php $renderQuestion($q, 'Q' . ($n + 1)); ?></div>
          <?php $actions($q, 'Delete this question? It is also removed from every series.'); ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$questions): ?><div class="card text-center text-slate-400 py-10">The question bank is empty.</div><?php endif; ?>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
