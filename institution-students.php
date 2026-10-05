<?php
require __DIR__ . '/app/bootstrap.php';
[$inst, $m, $canEdit] = institution_context();
$activeTab = 'institution-students';
$instId = (int) $inst['id'];
$ay = academic_year();

$loadRows = function (string $year) use ($instId): array {
    $st = db()->prepare('SELECT d.*, c.name AS course_name FROM institution_departments d
                         LEFT JOIN courses c ON c.id = d.course_id
                         WHERE d.institution_id = ? AND d.academic_year = ? ORDER BY c.name, d.department');
    $st->execute([$instId, $year]);
    return $st->fetchAll();
};

if (is_post()) {
    verify_csrf();
    if (!$canEdit) {
        forbidden('You cannot edit this institution.');
    }
    $before = $loadRows($ay);

    if (input('action') === 'copy') {
        $prev = (string) input('from_year');
        $src = $loadRows($prev);
        if ($before || !$src) {
            flash('error', 'Nothing to copy.');
        } else {
            $ins = db()->prepare('INSERT INTO institution_departments (institution_id, academic_year, course_id, department, total_students, final_year_count) VALUES (?,?,?,?,?,?)');
            foreach ($src as $r) {
                $ins->execute([$instId, $ay, $r['course_id'], $r['department'], $r['total_students'], $r['final_year_count']]);
            }
            log_activity('create', 'departments', null, "Copied " . count($src) . " departments from {$prev} to {$ay}", [], $instId);
            flash('success', 'Departments copied from ' . $prev . '. Review the counts and save.');
        }
        redirect('institution-students', ['id' => $instId]);
    }

    $courses = (array) ($_POST['course_id'] ?? []);
    $depts = (array) ($_POST['department'] ?? []);
    $totals = (array) ($_POST['total_students'] ?? []);
    $finals = (array) ($_POST['final_year_count'] ?? []);
    $validCourses = array_map('intval', array_column(lookup('courses', false), 'id'));

    $rows = [];
    $errors = [];
    foreach ($depts as $i => $dept) {
        $dept = trim((string) $dept);
        $course = (int) ($courses[$i] ?? 0);
        $total = max(0, (int) ($totals[$i] ?? 0));
        $final = max(0, (int) ($finals[$i] ?? 0));
        if ($dept === '' && !$course && !$total && !$final) {
            continue; // blank row
        }
        if ($dept === '') {
            $errors[] = 'Row ' . ($i + 1) . ': department / stream name is required.';
        }
        if ($course && !in_array($course, $validCourses, true)) {
            $course = 0;
        }
        if ($total && $final > $total) {
            $errors[] = 'Row ' . ($i + 1) . ": final-year count cannot exceed the total strength.";
        }
        $rows[] = [$course ?: null, mb_substr($dept, 0, 160), $total, $final];
    }
    if (!$rows) {
        $errors[] = 'Add at least one course / department.';
    }
    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        redirect('institution-students', ['id' => $instId]);
    }

    db()->beginTransaction();
    db()->prepare('DELETE FROM institution_departments WHERE institution_id = ? AND academic_year = ?')->execute([$instId, $ay]);
    $ins = db()->prepare('INSERT INTO institution_departments (institution_id, academic_year, course_id, department, total_students, final_year_count) VALUES (?,?,?,?,?,?)');
    foreach ($rows as $r) {
        $ins->execute([$instId, $ay, ...$r]);
    }
    db()->commit();

    $oldFinal = array_sum(array_column($before, 'final_year_count'));
    $newFinal = array_sum(array_column($rows, 3));
    $oldTotal = array_sum(array_column($before, 'total_students'));
    $newTotal = array_sum(array_column($rows, 2));
    $changes = diff_changes(
        ['Departments' => count($before), 'Final-year students' => $oldFinal, 'Total students' => $oldTotal],
        ['Departments' => count($rows), 'Final-year students' => $newFinal, 'Total students' => $newTotal]
    );
    log_activity('update', 'departments', null, "Updated student strength for {$ay}: " . count($rows) . " departments, {$newFinal} final-year students", $changes, $instId);
    snapshot_cohort($instId);
    flash('success', 'Course / department-wise student strength saved.');
    redirect(input('go') === 'next' ? 'institution-cohorts' : 'institution-students', ['id' => $instId]);
}

$rows = $loadRows($ay);
$courses = lookup('courses');

// Previous year with data, for the copy helper
$st = db()->prepare('SELECT academic_year, COUNT(*) n FROM institution_departments WHERE institution_id = ? AND academic_year <> ? GROUP BY academic_year ORDER BY academic_year DESC LIMIT 1');
$st->execute([$instId, $ay]);
$prevYear = $st->fetch();

// Course-wise summary
$byCourse = [];
foreach ($rows as $r) {
    $k = $r['course_name'] ?: 'Other';
    $byCourse[$k] = ($byCourse[$k] ?? 0) + (int) $r['final_year_count'];
}
arsort($byCourse);
$maxCourse = $byCourse ? max($byCourse) : 0;

$courseOptions = function (?int $selected) use ($courses): string {
    $h = '<option value="">— Course —</option>';
    foreach ($courses as $c) {
        $h .= '<option value="' . $c['id'] . '"' . ((int) $selected === (int) $c['id'] ? ' selected' : '') . '>' . e($c['name']) . '</option>';
    }
    return $h;
};

$pageTitle = $inst['name'] . ' · Student Strength';
require APP_ROOT . '/app/layout/header.php';
require APP_ROOT . '/app/layout/institution_nav.php';
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
<form method="post" class="card sm:p-8 lg:col-span-2">
  <?= csrf_field() ?>
  <?php section_heading('academic', 'emerald', 'Step 3: Course / Department-wise Student Strength', 'Record total and final-year batch counts across participating branches for ' . $ay . '.'); ?>

  <?php if (!$rows && $prevYear && $canEdit): ?>
    <div class="p-4 bg-sky-50 border border-sky-200 rounded-xl mb-6 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs text-sky-900">
      <span><?= icon('info', 'w-4 h-4 inline') ?> No data yet for <?= e($ay) ?>. <?= (int) $prevYear['n'] ?> departments were recorded for <?= e($prevYear['academic_year']) ?>.</span>
      <button form="copy-form" class="btn-secondary btn-xs">Copy from <?= e($prevYear['academic_year']) ?></button>
    </div>
  <?php endif; ?>

  <div class="bg-slate-50 p-4 rounded-xl border border-slate-200">
    <div class="flex items-center justify-between mb-3">
      <div>
        <h3 class="text-sm font-bold text-slate-800">Course / Department-wise Student Strength</h3>
        <p class="text-xs text-slate-500">Academic year <?= e($ay) ?></p>
      </div>
      <?php if ($canEdit): ?>
        <button type="button" id="btn-add-dept" class="btn-soft"><?= icon('plus', 'w-3.5 h-3.5') ?> Add Course/Dept</button>
      <?php endif; ?>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full text-left text-xs">
        <thead>
          <tr class="text-slate-500 border-b border-slate-200">
            <th class="py-2 font-semibold w-36">Course / Stream</th>
            <th class="py-2 font-semibold">Department</th>
            <th class="py-2 font-semibold w-28">Total Strength</th>
            <th class="py-2 font-semibold w-28">Final-Year Count</th>
            <?php if ($canEdit): ?><th class="py-2 font-semibold w-12 text-center">Action</th><?php endif; ?>
          </tr>
        </thead>
        <tbody id="dept-table-body">
          <?php
          $renderRow = function (?array $r) use ($courseOptions, $canEdit): string {
              $dis = $canEdit ? '' : ' disabled';
              return '<tr class="border-b border-slate-100 hover:bg-white">'
                . '<td class="py-2 pr-2"><select name="course_id[]" class="w-full bg-white border border-slate-200 rounded px-2 py-1 text-slate-800 font-medium"' . $dis . '>' . $courseOptions($r['course_id'] ?? null) . '</select></td>'
                . '<td class="py-2 pr-2"><input type="text" name="department[]" value="' . e($r['department'] ?? '') . '" placeholder="e.g. Computer Science & Engineering" class="w-full bg-white border border-slate-200 rounded px-2 py-1 text-slate-800"' . $dis . '></td>'
                . '<td class="py-2 pr-2"><input type="number" min="0" name="total_students[]" data-total value="' . e($r['total_students'] ?? '') . '" class="w-24 border border-slate-200 rounded px-2 py-1 text-slate-800 font-mono"' . $dis . '></td>'
                . '<td class="py-2 pr-2"><input type="number" min="0" name="final_year_count[]" data-final value="' . e($r['final_year_count'] ?? '') . '" class="w-24 border border-slate-200 rounded px-2 py-1 text-slate-800 font-mono"' . $dis . '></td>'
                . ($canEdit ? '<td class="py-2 text-center"><button type="button" data-remove-row class="text-rose-500 hover:text-rose-700 p-1" title="Delete">' . icon('trash') . '</button></td>' : '')
                . '</tr>';
          };
          foreach ($rows ?: [null] as $r) {
              echo $renderRow($r);
          }
          ?>
        </tbody>
        <tfoot>
          <tr class="font-bold text-slate-800 border-t border-slate-300">
            <td colspan="2" class="pt-3 text-right pr-4">Total Institutional Strength:</td>
            <td class="pt-3 text-slate-700 font-mono text-sm" id="calc-total-all">0</td>
            <td class="pt-3 text-sky-700 font-mono text-sm" id="calc-total-final">0</td>
            <?php if ($canEdit): ?><td></td><?php endif; ?>
          </tr>
        </tfoot>
      </table>
      <template id="dept-row-template"><?= $renderRow(null) ?></template>
    </div>
  </div>

  <?php if ($canEdit): ?>
  <div class="mt-8 pt-5 border-t border-slate-100 flex items-center justify-between gap-3">
    <a href="<?= e(url('institution-officers', ['id' => $instId])) ?>" class="btn-secondary">&larr; Back</a>
    <div class="flex gap-2">
      <button name="go" value="stay" class="btn-secondary">Save</button>
      <button name="go" value="next" class="btn-primary">Save &amp; Setup Cohorts &amp; Assessments <?= icon('arrow-right') ?></button>
    </div>
  </div>
  <?php endif; ?>
</form>
<?php if (!$rows && $prevYear && $canEdit): ?>
<form method="post" id="copy-form" class="hidden"><?= csrf_field() ?><input type="hidden" name="action" value="copy"><input type="hidden" name="from_year" value="<?= e($prevYear['academic_year']) ?>"></form>
<?php endif; ?>

<div class="space-y-6">
  <div class="card">
    <h3 class="card-title">Course-wise final-year students</h3>
    <p class="card-subtitle mb-4"><?= e($ay) ?> · saved figures</p>
    <?php if (!$byCourse): ?>
      <p class="text-xs text-slate-400 italic">No departments saved yet.</p>
    <?php else: ?>
      <ul class="space-y-3">
        <?php foreach ($byCourse as $course => $n): ?>
          <li title="<?= e($course) ?>: <?= num($n) ?> final-year students">
            <div class="flex justify-between text-xs mb-1"><span class="font-medium text-slate-700"><?= e($course) ?></span><span class="font-mono text-slate-900 font-semibold"><?= num($n) ?></span></div>
            <div class="bar-track"><div class="bar-fill" style="width: <?= pct($n, $maxCourse) ?>%"></div></div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
  <div class="p-4 bg-amber-50 border border-amber-200 rounded-2xl text-xs text-amber-900">
    <p class="font-bold uppercase tracking-wider mb-1">Tip</p>
    The final-year total pre-fills the cohort pool in Step 4. Course list is maintained by the State office.
  </div>
</div>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
