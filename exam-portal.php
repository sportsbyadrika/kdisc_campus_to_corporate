<?php
/**
 * Online exam for participants: instructions → start → one question at a time → finish.
 *  - No going back: only the answer to the current question is accepted.
 *  - The deadline is fixed when the exam starts (duration, capped at the exam's cut-off) and
 *    enforced on the server; the on-screen clock auto-submits at zero.
 *  - "Sign out" ends the attempt. A dropped connection or closed browser does not: signing in
 *    again continues from the same question until the deadline.
 */
require __DIR__ . '/app/bootstrap.php';

$p = current_participant();
if (!$p) {
    redirect('exam-login');
}
$pid = (int) $p['id'];
$st = db()->prepare('SELECT * FROM exams WHERE id = ?');
$st->execute([$p['exam_id']]);
$exam = $st->fetch();

$reload = function () use ($pid): array {
    $st = db()->prepare('SELECT * FROM exam_participants WHERE id = ?');
    $st->execute([$pid]);
    return $st->fetch();
};
$inProgress = fn(array $p) => $p['started_at'] && !$p['finished_at'];

// An attempt whose time is up is closed, whatever the request
if ($inProgress($p) && time() > strtotime($p['deadline_at']) + EXAM_GRACE_SECONDS) {
    exam_finish($pid, 'timeout', $p['deadline_at']);
    $p = $reload();
}

if (is_post()) {
    $action = input('action');

    // Tab / window lost focus (sendBeacon) - counted for the examiners
    if ($action === 'focus') {
        if (hash_equals(csrf_token(), (string) input('_csrf')) && $inProgress($p)) {
            db()->prepare('UPDATE exam_participants SET focus_lost = focus_lost + 1 WHERE id = ? AND finished_at IS NULL')->execute([$pid]);
        }
        http_response_code(204);
        exit;
    }

    verify_csrf();

    if ($action === 'logout') {
        if ($inProgress($p)) {
            exam_finish($pid, 'logout');
        }
        exam_logout_participant();
        flash('success', $inProgress($p) ? 'You have signed out. Your exam has been submitted.' : 'You have signed out.');
        redirect('exam-login');
    }

    if ($action === 'start' && !$p['started_at']) {
        if ($exam['status'] !== 'active' || exam_window($exam) !== 'open') {
            flash('error', 'The exam is not open right now.');
            redirect('exam-portal');
        }
        [$seriesId, $order] = exam_assign_questions($exam);
        if (!$order) {
            flash('error', 'This exam has no questions yet. Please contact the exam coordinator.');
            redirect('exam-portal');
        }
        $deadline = min(time() + (int) $exam['duration_minutes'] * 60, strtotime($exam['end_at']));
        db()->prepare('UPDATE exam_participants SET series_id = ?, question_order = ?, current_index = 0, started_at = ?, deadline_at = ?, last_ip = ?
                       WHERE id = ? AND started_at IS NULL')
            ->execute([$seriesId, json_encode($order), now_sql(), date('Y-m-d H:i:s', $deadline), client_ip(), $pid]);
        redirect('exam-portal');
    }

    if ($action === 'answer' && $inProgress($p)) {
        db()->beginTransaction();
        $st = db()->prepare('SELECT * FROM exam_participants WHERE id = ? FOR UPDATE');
        $st->execute([$pid]);
        $p = $st->fetch();
        $order = json_decode((string) $p['question_order'], true) ?: [];
        $idx = (int) $p['current_index'];
        $qid = (int) ($order[$idx] ?? 0);
        // Accept only the answer to the current question (a stale or replayed form is ignored)
        if (!$p['finished_at'] && (int) int_input('idx', -1) === $idx && (int) int_input('qid', 0) === $qid && $qid) {
            $st = db()->prepare('SELECT qtype FROM exam_questions WHERE id = ?');
            $st->execute([$qid]);
            $qtype = (string) $st->fetchColumn();
            $selected = null;
            $text = null;
            $marks = null;
            if ($qtype === 'descriptive') {
                $text = mb_substr(trim((string) input('answer_text')), 0, 20000);
                if ($text === '') $marks = 0;   // nothing to mark
            } else {
                $valid = array_map(fn($o) => (int) $o['id'], exam_options_for([$qid])[$qid] ?? []);
                $picked = array_values(array_intersect(array_map('intval', (array) ($_POST['choice'] ?? [])), $valid));
                if ($qtype === 'single') $picked = array_slice($picked, 0, 1);
                $selected = json_encode($picked);
            }
            db()->prepare('INSERT IGNORE INTO exam_answers (participant_id, question_id, selected, answer_text, marks_awarded, answered_at) VALUES (?,?,?,?,?,?)')
                ->execute([$pid, $qid, $selected, $text, $marks, now_sql()]);
            db()->prepare('UPDATE exam_participants SET current_index = current_index + 1 WHERE id = ?')->execute([$pid]);
            db()->commit();
            if (input('auto') === '1' || time() > strtotime($p['deadline_at'])) {
                exam_finish($pid, 'timeout', $p['deadline_at']);   // the clock ran out on this question
            } elseif ($idx + 1 >= count($order)) {
                exam_finish($pid, 'completed');
            }
        } else {
            db()->commit();
        }
        redirect('exam-portal');
    }
    redirect('exam-portal');
}

// ---- Views ------------------------------------------------------------------
$order = json_decode((string) $p['question_order'], true) ?: [];
$question = null;
$options = [];
if ($inProgress($p)) {
    $qid = (int) ($order[(int) $p['current_index']] ?? 0);
    $st = db()->prepare('SELECT * FROM exam_questions WHERE id = ?');
    $st->execute([$qid]);
    $question = $st->fetch() ?: null;
    if (!$question) {   // defensive: nothing left to show
        exam_finish($pid, 'completed');
        redirect('exam-portal');
    }
    $options = exam_option_order(exam_options_for([$qid])[$qid] ?? [], $pid, $qid, (bool) $exam['shuffle_options']);
}
$fmt = fn(string $d) => date('d M Y, h:i A', strtotime($d));
$window = exam_window($exam);
$st = db()->prepare('SELECT COUNT(*) FROM exam_answers WHERE participant_id = ?');
$st->execute([$pid]);
$answered = (int) $st->fetchColumn();

$pageTitle = $exam['title'];
$publicScripts = ['assets/js/exam-portal.js'];
$headerRight = '<form method="post" action="' . e(url('exam-portal')) . '" class="flex items-center gap-3"' . ($inProgress($p) ? ' data-exam-confirm="Signing out ends your exam and submits your answers. You cannot continue afterwards. Sign out?"' : '') . '>'
    . csrf_field() . '<input type="hidden" name="action" value="logout">'
    . '<span class="hidden sm:block text-right leading-tight"><span class="block text-sm font-semibold text-slate-800">' . e($p['name']) . '</span><span class="block text-[11px] text-slate-500">' . e($p['email']) . '</span></span>'
    . '<button class="btn-secondary btn-xs">' . icon('logout', 'w-3.5 h-3.5') . ' Sign out</button></form>';
require APP_ROOT . '/app/layout/public_header.php';
?>
<?php if ($p['finished_at']): ?>
  <div class="card max-w-2xl mx-auto text-center py-10">
    <div class="mx-auto w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center mb-4"><?= icon('check-circle', 'w-6 h-6') ?></div>
    <h1 class="text-xl font-bold text-slate-900">Your exam has been submitted</h1>
    <p class="text-sm text-slate-500 mt-1"><?= e($exam['title']) ?> · <?= e(EXAM_FINISH_REMARKS[$p['finish_reason']] ?? '') ?> · <?= e($fmt($p['finished_at'])) ?></p>
    <p class="text-sm text-slate-600 mt-4">You answered <?= num($answered) ?> of <?= num(count($order)) ?> question(s).</p>
    <?php if ($exam['results_published_at']): $pct = (float) $p['max_score'] > 0 ? round((float) $p['score'] / (float) $p['max_score'] * 100, 1) : 0; ?>
      <div class="mt-6 inline-block px-8 py-5 rounded-2xl bg-sky-50 border border-sky-100">
        <p class="text-xs font-semibold uppercase tracking-wider text-sky-700">Your result</p>
        <p class="text-3xl font-extrabold text-slate-900 mt-1"><?= exam_num($p['score']) ?> <span class="text-base text-slate-400">/ <?= exam_num($p['max_score']) ?></span></p>
        <p class="text-sm text-slate-600"><?= $pct ?>%</p>
      </div>
    <?php else: ?>
      <p class="text-sm text-slate-500 mt-2">Results will be available here once they are published.</p>
    <?php endif; ?>
  </div>

<?php elseif (!$p['started_at']): ?>
  <div class="card max-w-3xl mx-auto">
    <h1 class="text-xl font-bold text-slate-900"><?= e($exam['title']) ?></h1>
    <p class="text-sm text-slate-500 mt-1">Exam window: <?= e($fmt($exam['start_at'])) ?> – <?= e($fmt($exam['end_at'])) ?> · Duration: <?= (int) $exam['duration_minutes'] ?> minutes</p>
    <?php if (trim((string) $exam['instructions']) !== ''): ?>
      <div class="mt-5 p-4 rounded-xl bg-slate-50 border border-slate-200 text-sm text-slate-700 whitespace-pre-line"><?= e($exam['instructions']) ?></div>
    <?php endif; ?>
    <h2 class="text-sm font-bold text-slate-800 mt-6 mb-2">Please read before you start</h2>
    <ul class="text-sm text-slate-600 space-y-1.5 list-disc pl-5">
      <li>The clock starts when you press <strong>Start exam</strong> and runs for <?= (int) $exam['duration_minutes'] ?> minutes<?= $exam['end_at'] ? ' (and never past ' . e($fmt($exam['end_at'])) . ')' : '' ?>. When time is up, your answers are submitted automatically.</li>
      <li>Questions are shown one at a time. After you press <strong>Next</strong> you <strong>cannot go back</strong> to a previous question.</li>
      <li>Copying, cutting and pasting are disabled. Switching to other tabs or windows is recorded.</li>
      <li>Pressing <strong>Sign out</strong> ends the exam and submits your answers.</li>
      <li>If your connection drops or the browser closes, sign in again with the same password to continue — the clock keeps running.</li>
    </ul>
    <div class="mt-6 pt-5 border-t border-slate-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <?php if ($exam['status'] !== 'active' || $window === 'over'): ?>
        <p class="text-sm text-rose-600 font-medium">The exam window has closed.</p>
      <?php elseif ($window === 'upcoming'): ?>
        <p class="text-sm text-amber-700 font-medium">The exam opens at <?= e($fmt($exam['start_at'])) ?>. Please refresh this page then.</p>
      <?php else: ?>
        <p class="text-xs text-slate-500">By starting, you agree to follow the rules above.</p>
        <form method="post" data-exam-confirm="Start the exam now? The clock starts immediately.">
          <?= csrf_field() ?><input type="hidden" name="action" value="start">
          <button class="btn-primary"><?= icon('clock') ?> Start exam</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

<?php else:
    $idx = (int) $p['current_index'];
    $total = count($order);
    $last = $idx + 1 >= $total; ?>
  <div class="max-w-3xl mx-auto select-none" id="exam-area" data-remaining="<?= max(0, strtotime($p['deadline_at']) - time()) ?>" data-focus-url="<?= e(url('exam-portal')) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <div class="sticky top-0 z-10 bg-slate-50/95 backdrop-blur py-3 mb-3 flex items-center justify-between gap-3">
      <div class="min-w-0">
        <p class="text-xs text-slate-500 truncate"><?= e($exam['title']) ?></p>
        <p class="text-sm font-bold text-slate-900">Question <?= $idx + 1 ?> of <?= $total ?></p>
      </div>
      <div class="flex items-center gap-2 px-3 py-1.5 rounded-xl bg-white border border-slate-200 shadow-xs" id="exam-clock-box">
        <?= icon('clock', 'w-4 h-4 text-slate-500') ?>
        <span class="font-mono text-lg font-bold text-slate-900 tabular-nums" id="exam-clock">--:--</span>
      </div>
    </div>
    <div class="h-1.5 rounded-full bg-slate-200 mb-5 overflow-hidden"><div class="h-full bg-sky-600" style="width: <?= round($idx / max(1, $total) * 100) ?>%"></div></div>

    <form method="post" class="card" id="exam-answer-form" autocomplete="off" data-last="<?= $last ? '1' : '0' ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="answer"><input type="hidden" name="idx" value="<?= $idx ?>"><input type="hidden" name="qid" value="<?= (int) $question['id'] ?>">
      <input type="hidden" name="auto" value="0" id="exam-auto">
      <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-2"><?= e(EXAM_QTYPES[$question['qtype']]) ?> · <?= exam_num($question['marks']) ?> mark<?= (float) $question['marks'] === 1.0 ? '' : 's' ?><?= $question['qtype'] === 'multi' ? ' · select all that apply' : '' ?></p>
      <p class="text-base text-slate-900 whitespace-pre-line mb-5"><?= e($question['question']) ?></p>
      <?php if ($question['qtype'] === 'descriptive'): ?>
        <textarea class="input" name="answer_text" rows="10" spellcheck="false" placeholder="Type your answer here" data-no-paste></textarea>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($options as $o): ?>
            <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200 hover:bg-sky-50/50 cursor-pointer has-[:checked]:border-sky-500 has-[:checked]:bg-sky-50">
              <input type="<?= $question['qtype'] === 'single' ? 'radio' : 'checkbox' ?>" name="choice[]" value="<?= (int) $o['id'] ?>" class="mt-0.5 h-4 w-4 border-slate-300 text-sky-600">
              <span class="text-sm text-slate-800 whitespace-pre-line"><?= e($o['option_text']) ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="mt-6 pt-5 border-t border-slate-100 flex items-center justify-between gap-3">
        <p class="text-xs text-slate-500">You cannot return to this question after moving on.</p>
        <button class="btn-primary" data-exam-next><?= $last ? 'Submit exam' : 'Save &amp; next' ?> <?= icon('arrow-right') ?></button>
      </div>
    </form>
  </div>
<?php endif; ?>
<?php require APP_ROOT . '/app/layout/public_footer.php';
