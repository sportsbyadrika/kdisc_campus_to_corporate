<?php
/**
 * Online assessment: printable question paper, with or without the answer key, for one question
 * series (?sid=) or the whole question bank. Opens as an A4 document; "Print / Save as PDF" gives
 * the PDF for verification and filing.
 */
require __DIR__ . '/app/bootstrap.php';
$exam = load_exam();
$id = (int) $exam['id'];
$me = current_user();
$withKey = input('key', '1') !== '0';

// The questions in paper order: a series' own order, or the bank's
$bank = exam_questions($id);
$byId = array_column($bank, null, 'id');
$series = null;
if ($sid = (int) int_input('sid', 0)) {
    $st = db()->prepare('SELECT * FROM exam_series WHERE id = ? AND exam_id = ?');
    $st->execute([$sid, $id]);
    $series = $st->fetch() ?: forbidden('Series not found.');
    $st = db()->prepare('SELECT question_id FROM exam_series_questions WHERE series_id = ? ORDER BY sort_order, question_id');
    $st->execute([$sid]);
    $paper = array_values(array_filter(array_map(fn($q) => $byId[(int) $q] ?? null, $st->fetchAll(PDO::FETCH_COLUMN))));
} else {
    $paper = $bank;
}

$letters = fn(int $i) => $i < 26 ? chr(65 + $i) : (string) ($i + 1);
$answerable = 0;
$total = 0.0;
$key = [];   // [no, type, answer, marks] for the summary table
foreach ($paper as $n => $q) {
    $items = $q['qtype'] === EXAM_CASE ? $q['subs'] : [$q];
    foreach ($items as $i => $item) {
        $no = 'Q' . ($n + 1) . ($q['qtype'] === EXAM_CASE ? '(' . chr(97 + $i) . ')' : '');
        $correct = [];
        foreach ($item['options'] as $k => $o) if ($o['is_correct']) $correct[] = $letters($k);
        $key[] = [$no, exam_qtype_label($item['qtype']), $item['qtype'] === 'descriptive' ? 'Descriptive (examiner)' : implode(', ', $correct), $item['marks']];
        $answerable++;
        $total += (float) $item['marks'];
    }
}
$fmt = fn(?string $d) => $d ? date('d M Y, h:i A', strtotime($d)) : '—';
$docTitle = $withKey ? 'Question Paper with Answer Key' : 'Question Paper';
log_activity('export', 'settings', null, "Printed {$docTitle}: {$exam['title']}" . ($series ? " · {$series['name']}" : ' · whole bank'));
$self = fn(array $q) => url('exam-print', array_filter(['id' => $id, 'sid' => $series['id'] ?? null] + $q, fn($v) => $v !== null));
$logo = setting('app_logo');
// Text placed inside a CSS string (page footer): no quotes, backslashes, tags or line breaks
$cssText = fn(string $t) => mb_substr(preg_replace('/["\\\\<>\r\n]+/', ' ', $t), 0, 90);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($docTitle . ' - ' . $exam['title'] . ($series ? ' - ' . $series['name'] : '')) ?></title>
<style>
  @page { size: A4; margin: 16mm 14mm 18mm; @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 9pt Arial, sans-serif; color: #555; } @bottom-left { content: "<?= $cssText($exam['title'] . ($series ? ' · ' . $series['name'] : '')) ?>"; font: 9pt Arial, sans-serif; color: #555; } }
  * { box-sizing: border-box; }
  body { margin: 0; background: #e5e7eb; color: #111; font: 10.5pt/1.45 Arial, "Noto Sans", "Noto Sans Malayalam", sans-serif; }
  .sheet { background: #fff; width: 210mm; min-height: 297mm; margin: 12px auto; padding: 16mm 14mm 18mm; box-shadow: 0 1px 6px rgba(0,0,0,.15); }
  .bar { position: sticky; top: 0; z-index: 5; background: #0b3a5c; color: #fff; padding: 10px 16px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; font: 13px Arial, sans-serif; }
  .bar a, .bar button { color: #fff; text-decoration: none; border: 1px solid rgba(255,255,255,.4); background: transparent; padding: 6px 12px; border-radius: 8px; font: inherit; cursor: pointer; }
  .bar .on { background: #fff; color: #0b3a5c; font-weight: bold; }
  .bar .primary { background: #0f9a92; border-color: #0f9a92; font-weight: bold; }
  header.doc { display: flex; align-items: center; gap: 12px; border-bottom: 2px solid #0b3a5c; padding-bottom: 8px; }
  header.doc img { width: 48px; height: 48px; object-fit: contain; }
  header.doc .org { font-size: 13pt; font-weight: bold; color: #0b3a5c; }
  header.doc .tag { font-size: 9pt; color: #555; }
  h1 { font-size: 15pt; margin: 12px 0 2px; text-align: center; letter-spacing: .3px; }
  .conf { text-align: center; font-size: 9pt; font-weight: bold; color: #b91c1c; letter-spacing: 1px; }
  table.meta { width: 100%; border-collapse: collapse; margin: 10px 0 12px; font-size: 9.5pt; }
  table.meta td { border: 1px solid #bbb; padding: 4px 7px; vertical-align: top; }
  table.meta td.k { background: #f1f5f9; font-weight: bold; width: 18%; }
  .instructions { border: 1px solid #ccc; background: #fafafa; padding: 6px 9px; font-size: 9.5pt; margin-bottom: 10px; white-space: pre-line; }
  .q { break-inside: avoid; page-break-inside: avoid; padding: 7px 0 8px; border-bottom: 1px dashed #ccc; }
  .qhead { display: flex; justify-content: space-between; gap: 10px; }
  .qno { font-weight: bold; min-width: 52px; }
  .qtext { flex: 1; white-space: pre-line; }
  .marks { white-space: nowrap; font-size: 9pt; color: #333; }
  .type { font-size: 8.5pt; color: #555; margin-left: 52px; }
  ol.opts { list-style: none; margin: 4px 0 0 52px; padding: 0; }
  ol.opts li { display: flex; gap: 6px; padding: 1px 0; }
  ol.opts .l { font-weight: bold; min-width: 22px; }
  ol.opts li.ok { font-weight: bold; }
  ol.opts li.ok .tick { color: #047857; }
  .ans { margin: 4px 0 0 52px; font-size: 9.5pt; color: #047857; font-weight: bold; }
  .ans.desc { color: #6d28d9; }
  .case { break-inside: avoid; border: 1px solid #94a3b8; background: #f8fafc; padding: 7px 10px; margin: 8px 0 2px; }
  .case .head { font-weight: bold; margin-bottom: 4px; display: flex; justify-content: space-between; }
  .rich p { margin: 4px 0; } .rich ul, .rich ol { margin: 4px 0; padding-left: 22px; } .rich strong { font-weight: bold; } .rich em { font-style: italic; }
  .sub { margin-left: 18px; }
  h2 { font-size: 12pt; margin: 18px 0 6px; border-bottom: 1px solid #0b3a5c; padding-bottom: 3px; color: #0b3a5c; break-after: avoid; }
  table.key { width: 100%; border-collapse: collapse; font-size: 9.5pt; }
  table.key th, table.key td { border: 1px solid #bbb; padding: 3px 6px; text-align: left; }
  table.key th { background: #f1f5f9; }
  table.key td.r, table.key th.r { text-align: right; }
  .sign { display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; margin-top: 26px; break-inside: avoid; font-size: 9.5pt; }
  .sign div { border-top: 1px solid #333; padding-top: 4px; }
  .sign span { display: block; color: #555; font-size: 8.5pt; margin-top: 10px; }
  .muted { color: #666; }
  @media print {
    body { background: #fff; }
    .bar { display: none; }
    .sheet { width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none; }
  }
</style>
</head>
<body>
<div class="bar">
  <a href="<?= e(url($series ? 'exam-series' : 'exam-questions', ['id' => $id])) ?>">&larr; Back</a>
  <span>
    <a href="<?= e($self(['key' => null])) ?>" class="<?= $withKey ? 'on' : '' ?>">With answer key</a>
    <a href="<?= e($self(['key' => '0'])) ?>" class="<?= $withKey ? '' : 'on' ?>">Question paper only</a>
  </span>
  <button type="button" class="primary" onclick="window.print()">Print / Save as PDF</button>
</div>

<main class="sheet">
  <header class="doc">
    <?php if ($logo !== '' && is_file(APP_ROOT . '/uploads/' . $logo)): ?><img src="<?= e(upload_url($logo)) ?>" alt=""><?php endif; ?>
    <div>
      <div class="org"><?= e(setting('app_name', 'Campus to Corporate')) ?></div>
      <div class="tag"><?= e(setting('app_tagline')) ?></div>
    </div>
  </header>

  <h1><?= e($docTitle) ?></h1>
  <?php if ($withKey): ?><div class="conf">CONFIDENTIAL — FOR VERIFICATION AND RECORDS ONLY</div><?php endif; ?>

  <table class="meta">
    <tr><td class="k">Assessment</td><td colspan="3"><strong><?= e($exam['title']) ?></strong></td></tr>
    <tr><td class="k">Question series</td><td><?= e($series['name'] ?? 'Whole question bank') ?></td><td class="k">Status</td><td><?= e(EXAM_STATUSES[$exam['status']] ?? $exam['status']) ?></td></tr>
    <tr><td class="k">Assessment window</td><td><?= e($fmt($exam['start_at'])) ?> to <?= e($fmt($exam['end_at'])) ?></td><td class="k">Duration</td><td><?= (int) $exam['duration_minutes'] ?> minutes</td></tr>
    <tr><td class="k">Questions</td><td><?= num($answerable) ?><?php $cases = array_filter($paper, fn($q) => $q['qtype'] === EXAM_CASE); $inCases = array_sum(array_map(fn($q) => count($q['subs']), $cases)); ?><?= $cases ? ' <span class="muted">(' . $inCases . ' of them in ' . count($cases) . ' case stud' . (count($cases) === 1 ? 'y' : 'ies') . ')</span>' : '' ?></td><td class="k">Total marks</td><td><strong><?= exam_num($total) ?></strong></td></tr>
    <tr><td class="k">Generated</td><td><?= e(date('d M Y, h:i A')) ?></td><td class="k">Generated by</td><td><?= e($me['name']) ?></td></tr>
  </table>

  <?php if (trim((string) $exam['instructions']) !== ''): ?>
    <div class="instructions"><strong>Instructions:</strong> <?= e($exam['instructions']) ?></div>
  <?php endif; ?>

  <?php
  /** One answerable question with its options and (in key mode) the answer. */
  $renderQ = function (array $q, string $no, string $extra = '') use ($withKey, $letters) {
      $correct = [];
      ?>
      <div class="q <?= $extra ?>">
        <div class="qhead"><span class="qno"><?= e($no) ?></span><span class="qtext"><?= e($q['question']) ?></span><span class="marks">[<?= exam_num($q['marks']) ?> mark<?= (float) $q['marks'] === 1.0 ? '' : 's' ?>]</span></div>
        <div class="type"><?= e(exam_qtype_label($q['qtype'])) ?><?= $q['qtype'] === 'multi' ? ' — select all that apply' : '' ?></div>
        <?php if ($q['options']): ?>
          <ol class="opts">
            <?php foreach ($q['options'] as $k => $o): $ok = $withKey && $o['is_correct']; if ($o['is_correct']) $correct[] = $letters($k); ?>
              <li class="<?= $ok ? 'ok' : '' ?>"><span class="l"><?= $letters($k) ?>.</span><span><?= e($o['option_text']) ?><?= $ok ? ' <span class="tick">✔</span>' : '' ?></span></li>
            <?php endforeach; ?>
          </ol>
        <?php endif; ?>
        <?php if ($withKey): ?>
          <?php if ($q['qtype'] === 'descriptive'): ?><div class="ans desc">Answer: descriptive — evaluated by the examiner (maximum <?= exam_num($q['marks']) ?> marks)</div>
          <?php else: ?><div class="ans">Answer: <?= e(implode(', ', $correct) ?: '—') ?></div><?php endif; ?>
        <?php endif; ?>
      </div>
      <?php
  };
  ?>

  <?php foreach ($paper as $n => $q): ?>
    <?php if ($q['qtype'] === EXAM_CASE): ?>
      <div class="case">
        <div class="head"><span>Q<?= $n + 1 ?>. <?= EXAM_CASE_LABEL ?></span><span class="marks">[<?= exam_num($q['marks']) ?> marks · <?= count($q['subs']) ?> question<?= count($q['subs']) === 1 ? '' : 's' ?>]</span></div>
        <div class="rich"><?= exam_rich_text($q['question']) ?></div>
      </div>
      <?php foreach ($q['subs'] as $i => $sq) $renderQ($sq, 'Q' . ($n + 1) . '(' . chr(97 + $i) . ')', 'sub'); ?>
    <?php else: ?>
      <?php $renderQ($q, 'Q' . ($n + 1)); ?>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if (!$paper): ?><p class="muted">There are no questions in this <?= $series ? 'series' : 'question bank' ?> yet.</p><?php endif; ?>

  <?php if ($withKey && $key): ?>
    <h2>Answer key summary</h2>
    <table class="key">
      <thead><tr><th>Question</th><th>Type</th><th>Correct answer</th><th class="r">Marks</th></tr></thead>
      <tbody>
        <?php foreach ($key as [$no, $type, $answer, $marks]): ?>
          <tr><td><?= e($no) ?></td><td><?= e($type) ?></td><td><strong><?= e($answer ?: '—') ?></strong></td><td class="r"><?= exam_num($marks) ?></td></tr>
        <?php endforeach; ?>
        <tr><td colspan="3"><strong>Total</strong></td><td class="r"><strong><?= exam_num($total) ?></strong></td></tr>
      </tbody>
    </table>
  <?php endif; ?>

  <div class="sign">
    <div>Prepared by<span>Name, designation, date</span></div>
    <div>Verified by<span>Name, designation, date</span></div>
    <div>Approved by<span>Name, designation, date</span></div>
  </div>
</main>
</body>
</html>
