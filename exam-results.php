<?php
/**
 * Online exam: results. Participants who never signed in before the cut-off are Absent; the
 * others are Completed with a remark (normal completion, time out, logout). Results can be
 * published once every participant is Completed or Absent and all descriptive answers are marked.
 */
require __DIR__ . '/app/bootstrap.php';
$exam = load_exam();
$id = (int) $exam['id'];
exam_finalize_overdue($id);

$load = function () use ($id): array {
    $st = db()->prepare('SELECT p.*, s.name AS series_name,
                           (SELECT COUNT(*) FROM exam_answers a JOIN exam_questions q ON q.id = a.question_id
                             WHERE a.participant_id = p.id AND q.qtype = \'descriptive\' AND a.marks_awarded IS NULL) AS ungraded,
                           (SELECT COUNT(*) FROM exam_answers a WHERE a.participant_id = p.id) AS answered
                         FROM exam_participants p LEFT JOIN exam_series s ON s.id = p.series_id
                         WHERE p.exam_id = ? ORDER BY p.score IS NULL, p.score DESC, p.name');
    $st->execute([$id]);
    return $st->fetchAll();
};
$participants = $load();
foreach ($participants as &$p) $p['state'] = participant_state($p, $exam);
unset($p);
$pending = array_filter($participants, fn($p) => !in_array($p['state']['status'], ['Completed', 'Absent'], true));
$ungraded = array_sum(array_column($participants, 'ungraded'));
$canPublish = $participants && !$pending && !$ungraded;

if (is_post()) {
    verify_csrf();
    if (input('action') === 'unpublish') {
        db()->prepare('UPDATE exams SET results_published_at = NULL WHERE id = ?')->execute([$id]);
        flash('success', 'Results unpublished. Participants no longer see their scores.');
    } elseif (input('action') === 'publish') {
        if (!$canPublish) {
            flash('error', $pending ? count($pending) . ' participant(s) have not completed yet (in progress, or the exam window is still open).' : "{$ungraded} descriptive answer(s) still need marks.");
        } else {
            db()->prepare('UPDATE exams SET results_published_at = ? WHERE id = ?')->execute([now_sql(), $id]);
            $mailed = 0;
            if (input('email_results') === '1') {
                set_time_limit(0);
                foreach ($participants as $p) {
                    if ($p['state']['status'] === 'Completed' && exam_mail_result($p, $exam)) $mailed++;
                }
            }
            flash('success', 'Results published. Participants see their score when they sign in to the exam page' . ($mailed ? "; {$mailed} result email(s) sent." : '.'));
        }
    }
    redirect('exam-results', ['id' => $id]);
}

$duration = function (array $p): string {
    if (!$p['started_at'] || !$p['finished_at']) return '—';
    $s = max(0, strtotime($p['finished_at']) - strtotime($p['started_at']));
    return sprintf('%d:%02d', intdiv($s, 60), $s % 60);
};
$pctOf = fn(array $p) => (float) $p['max_score'] > 0 ? round((float) $p['score'] / (float) $p['max_score'] * 100, 1) : 0;

if (input('export') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="exam-' . $id . '-results.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Name', 'Email', 'Mobile', 'Details', 'Series', 'Status', 'Remark', 'Started', 'Finished', 'Time taken (min:sec)', 'Tab switches', 'Answered', 'Score', 'Max score', 'Percent']);
    foreach ($participants as $p) {
        $done = $p['state']['status'] === 'Completed';
        fputcsv($out, [$p['name'], $p['email'], $p['phone'], $p['details'], $p['series_name'], $p['state']['status'], $p['state']['remark'], $p['started_at'], $p['finished_at'],
            $duration($p), $p['focus_lost'], $p['answered'], $done ? exam_num($p['score']) : '', $done ? exam_num($p['max_score']) : '', $done ? $pctOf($p) : '']);
    }
    fclose($out);
    exit;
}

$counts = array_count_values(array_map(fn($p) => $p['state']['status'], $participants));
$completed = array_filter($participants, fn($p) => $p['state']['status'] === 'Completed');
$avg = $completed ? round(array_sum(array_map($pctOf, $completed)) / count($completed), 1) : 0;

$pageTitle = $exam['title'] . ' · Results';
require APP_ROOT . '/app/layout/header.php';
$examTab = 'exam-results';
require APP_ROOT . '/app/layout/exam_nav.php';
?>
<div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-6">
  <?php foreach ([['Participants', count($participants), 'text-slate-900'], ['Completed', $counts['Completed'] ?? 0, 'text-emerald-600'], ['In progress', $counts['In Progress'] ?? 0, 'text-amber-600'],
                  ['Absent', $counts['Absent'] ?? 0, 'text-rose-600'], ['To be marked', $ungraded, 'text-violet-600'], ['Average score', $avg . '%', 'text-sky-700']] as [$lbl, $val, $cls]): ?>
    <div class="kpi"><p class="text-xs font-semibold text-slate-500"><?= e($lbl) ?></p><p class="text-2xl font-extrabold <?= $cls ?>"><?= is_int($val) ? num($val) : e($val) ?></p></div>
  <?php endforeach; ?>
</div>

<div class="card p-4 mb-4 flex flex-col lg:flex-row lg:items-center justify-between gap-3">
  <?php if ($exam['results_published_at']): ?>
    <p class="text-sm text-slate-700 flex items-center gap-2"><?= icon('check-circle', 'w-5 h-5 text-emerald-600') ?> Results published on <?= e(date('d M Y, h:i A', strtotime($exam['results_published_at']))) ?>.</p>
    <form method="post" data-confirm="Unpublish the results?"><?= csrf_field() ?><input type="hidden" name="action" value="unpublish"><button class="btn-secondary btn-xs">Unpublish</button></form>
  <?php else: ?>
    <div class="text-sm text-slate-700">
      <?php if ($canPublish): ?><p class="flex items-center gap-2"><?= icon('check-circle', 'w-5 h-5 text-emerald-600') ?> All participants have completed or are absent, and every answer is marked. Results can be published.</p>
      <?php elseif (!$participants): ?><p>No participants yet.</p>
      <?php else: ?>
        <p class="flex items-center gap-2"><?= icon('info', 'w-5 h-5 text-amber-600') ?> Results can be published after every participant completes the exam (or is absent after the cut-off) and all descriptive answers are marked.</p>
        <p class="text-xs text-slate-500 mt-1 ml-7"><?= num(count($pending)) ?> still to complete · <?= num($ungraded) ?> answer(s) to mark</p>
      <?php endif; ?>
    </div>
    <form method="post" class="flex items-center gap-3 shrink-0" data-confirm="Publish the results now?">
      <?= csrf_field() ?><input type="hidden" name="action" value="publish">
      <label class="flex items-center gap-1.5 text-xs text-slate-600"><input type="checkbox" name="email_results" value="1" class="h-4 w-4 rounded border-slate-300 text-sky-600" checked> Email results</label>
      <button class="btn-primary btn-xs" <?= $canPublish ? '' : 'disabled' ?>><?= icon('check') ?> Publish results</button>
    </form>
  <?php endif; ?>
</div>

<div class="card p-0 overflow-hidden">
  <div class="flex items-center justify-between p-4 pb-2">
    <h2 class="card-title">Participant results</h2>
    <a href="<?= e(url('exam-results', ['id' => $id, 'export' => 'csv'])) ?>" class="btn-secondary btn-xs"><?= icon('download', 'w-3.5 h-3.5') ?> CSV</a>
  </div>
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><th>Participant</th><th>Series</th><th>Status</th><th>Started / time taken</th><th class="text-right" title="Times the exam tab lost focus">Tab switches</th><th class="text-right">Score</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($participants as $p): $done = $p['state']['status'] === 'Completed';
          $href = $p['started_at'] ? url('exam-sheet', ['id' => $id, 'pid' => $p['id']]) : null; ?>
        <tr <?= $href ? 'data-href="' . e($href) . '"' : '' ?>>
          <td><p class="font-semibold text-slate-900"><?= e($p['name']) ?></p><p class="text-[11px] text-slate-400"><?= e($p['email']) ?></p></td>
          <td class="text-xs"><?= e($p['series_name'] ?? '—') ?></td>
          <td><?= status_badge($p['state']['status']) ?><span class="block text-[11px] text-slate-400 mt-0.5"><?= e($p['state']['remark']) ?></span></td>
          <td class="text-xs whitespace-nowrap"><?= $p['started_at'] ? e(date('d M, h:i A', strtotime($p['started_at']))) : '—' ?><span class="block text-slate-400"><?= e($duration($p)) ?></span></td>
          <td class="text-right font-mono text-xs <?= (int) $p['focus_lost'] > 3 ? 'text-rose-600 font-bold' : '' ?>"><?= $p['started_at'] ? (int) $p['focus_lost'] : '—' ?></td>
          <td class="text-right whitespace-nowrap">
            <?php if ($done): ?><span class="font-bold text-slate-900"><?= exam_num($p['score']) ?></span> <span class="text-xs text-slate-400">/ <?= exam_num($p['max_score']) ?></span><span class="block text-[11px] text-slate-500"><?= $pctOf($p) ?>%<?= $p['ungraded'] ? ' · <span class="text-violet-600">' . (int) $p['ungraded'] . ' to mark</span>' : '' ?></span>
            <?php else: ?><span class="text-slate-300">—</span><?php endif; ?>
          </td>
          <td class="text-right"><?php if ($href): ?><a href="<?= e($href) ?>" class="text-xs link whitespace-nowrap"><?= $p['ungraded'] ? 'Mark answers' : 'Answer sheet' ?></a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$participants): ?><tr><td colspan="7" class="text-center text-slate-400 py-10">No participants yet. Add them on the <a class="link" href="<?= e(url('exam-participants', ['id' => $id])) ?>">Participants</a> tab.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
