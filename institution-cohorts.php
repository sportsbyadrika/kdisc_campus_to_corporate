<?php
require __DIR__ . '/app/bootstrap.php';
[$inst, $m, $canEdit] = institution_context();
$activeTab = 'institution-cohorts';
$instId = (int) $inst['id'];
$ay = academic_year();

const ASSESSMENT_STATUSES = [
    'Pending Allocation'     => 'Pending Lab Allocation',
    'Drive Scheduled'        => 'Drive Scheduled (In Progress)',
    'Initial Batch Completed'=> 'Initial Batch Completed (>50%)',
    'Completed'              => 'Completed for All Job Seekers',
];

$st = db()->prepare('SELECT * FROM institution_cohorts WHERE institution_id = ? AND academic_year = ?');
$st->execute([$instId, $ay]);
$cohort = $st->fetch() ?: null;

// Campus-placed students are also captured for the previous academic year (for comparison)
$prevAy = previous_academic_year($ay);
$st = db()->prepare('SELECT campus_placed FROM institution_cohorts WHERE institution_id = ? AND academic_year = ?');
$st->execute([$instId, $prevAy]);
$prevCampusRow = $st->fetch() ?: null;
$prevCampus = $prevCampusRow['campus_placed'] ?? null;
$optionalCount = function (string $key): ?int {
    $raw = $_POST[$key] ?? '';
    return is_string($raw) && trim($raw) !== '' && is_numeric($raw) ? max(0, (int) $raw) : null;
};

$tests = assessment_tests_for($inst['university_id'] ? (int) $inst['university_id'] : null);

// Tests conducted as reported by the vendor software (bulk-uploaded by Admin / District; read-only here)
$st = db()->prepare('SELECT * FROM assessment_vendor_counts WHERE institution_id = ? AND academic_year = ?');
$st->execute([$instId, $ay]);
$vendor = array_column($st->fetchAll(), null, 'assessment_test_id');
$st = db()->prepare('SELECT * FROM institution_assessments WHERE institution_id = ? AND academic_year = ?');
$st->execute([$instId, $ay]);
$progress = [];
foreach ($st->fetchAll() as $r) {
    $progress[(int) $r['assessment_test_id']] = $r;
}

if (is_post()) {
    verify_csrf();
    if (!$canEdit) {
        forbidden('You cannot edit this institution.');
    }
    $data = [
        'total_final_year' => max(0, (int) int_input('total_final_year', 0)),
        'dwms_registered'  => max(0, (int) int_input('dwms_registered', 0)),
        'job_seekers'      => max(0, (int) int_input('job_seekers', 0)),
        'higher_studies'   => max(0, (int) int_input('higher_studies', 0)),
        'remarks'          => nullable(input('remarks')),
    ];
    $errors = [];
    $campus = $optionalCount('campus_placed');
    $campusPrev = $optionalCount('campus_placed_prev');
    if ($data['total_final_year'] < 1) $errors[] = 'Total final-year students must be at least 1.';
    if ($campus !== null && $campus > $data['total_final_year']) $errors[] = "Campus-placed students ({$ay}) cannot exceed total final-year students.";
    if ($data['dwms_registered'] > $data['total_final_year']) $errors[] = 'DWMS registrations cannot exceed total final-year students.';
    if ($data['job_seekers'] + $data['higher_studies'] > $data['total_final_year']) $errors[] = 'Job seekers + higher-studies aspirants cannot exceed total final-year students.';

    $assess = [];
    $validTests = array_column($tests, null, 'id');
    foreach ((array) ($_POST['assess'] ?? []) as $tid => $a) {
        $tid = (int) $tid;
        if (!isset($validTests[$tid]) || !is_array($a)) {
            continue;
        }
        $done = max(0, (int) ($a['completed'] ?? 0));
        $status = array_key_exists($a['status'] ?? '', ASSESSMENT_STATUSES) ? $a['status'] : 'Pending Allocation';
        $date = !empty($a['drive_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $a['drive_date']) ? $a['drive_date'] : null;
        if ($done > $data['total_final_year']) {
            $errors[] = $validTests[$tid]['name'] . ': completions cannot exceed total final-year students.';
        }
        $assess[$tid] = ['students_completed' => $done, 'status' => $status, 'drive_date' => $date];
    }

    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        $_SESSION['_old'] = $_POST;
        redirect('institution-cohorts', ['id' => $instId]);
    }

    $uid = current_user()['id'];
    db()->beginTransaction();
    db()->prepare('INSERT INTO institution_cohorts (institution_id, academic_year, total_final_year, dwms_registered, job_seekers, higher_studies, remarks, updated_by)
                   VALUES (?,?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE total_final_year = VALUES(total_final_year), dwms_registered = VALUES(dwms_registered),
                     job_seekers = VALUES(job_seekers), higher_studies = VALUES(higher_studies), remarks = VALUES(remarks), updated_by = VALUES(updated_by)')
        ->execute([$instId, $ay, ...array_values($data), $uid]);
    db()->prepare('UPDATE institution_cohorts SET campus_placed = ? WHERE institution_id = ? AND academic_year = ?')->execute([$campus, $instId, $ay]);
    if ($campusPrev !== null || $prevCampusRow) {
        db()->prepare('INSERT INTO institution_cohorts (institution_id, academic_year, campus_placed, updated_by) VALUES (?,?,?,?)
                       ON DUPLICATE KEY UPDATE campus_placed = VALUES(campus_placed)')
            ->execute([$instId, $prevAy, $campusPrev, $uid]);
    }
    $changes = diff_changes(
        ['campus' => $cohort['campus_placed'] ?? null, 'campus_prev' => $prevCampus],
        ['campus' => $campus, 'campus_prev' => $campusPrev],
        ['campus' => "Campus-placed {$ay}", 'campus_prev' => "Campus-placed {$prevAy}"]
    );
    $changes += diff_changes($cohort ?? [], $data, [
        'total_final_year' => 'Final-year students', 'dwms_registered' => 'DWMS registered',
        'job_seekers' => 'Immediate job seekers', 'higher_studies' => 'Higher studies', 'remarks' => 'Remarks',
    ]);

    $up = db()->prepare('INSERT INTO institution_assessments (institution_id, academic_year, assessment_test_id, students_completed, status, drive_date, updated_by)
                         VALUES (?,?,?,?,?,?,?)
                         ON DUPLICATE KEY UPDATE students_completed = VALUES(students_completed), status = VALUES(status),
                           drive_date = VALUES(drive_date), updated_by = VALUES(updated_by)');
    foreach ($assess as $tid => $a) {
        $up->execute([$instId, $ay, $tid, $a['students_completed'], $a['status'], $a['drive_date'], $uid]);
        $prev = $progress[$tid] ?? [];
        foreach (diff_changes($prev, $a, ['students_completed' => 'completed', 'status' => 'status', 'drive_date' => 'drive date']) as $k => $c) {
            $changes[$validTests[$tid]['name'] . ' ' . $k] = $c;
        }
    }
    db()->commit();

    if ($changes || !$cohort) {
        log_activity($cohort ? 'update' : 'create', 'cohort', null, "Updated cohort & assessments for {$ay}: {$data['job_seekers']} job seekers", $changes, $instId);
        snapshot_cohort($instId);
    }
    flash('success', 'Cohort and assessment progress saved.');
    redirect(input('go') === 'next' ? 'institution-services' : 'institution-cohorts', ['id' => $instId]);
}

$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
$defaultTotal = $cohort['total_final_year'] ?? ($m['dept_final_year'] ?: '');
$v = fn(string $k, $d = '') => $old[$k] ?? $cohort[$k] ?? $d;
$ro = $canEdit ? '' : 'disabled';

$pageTitle = $inst['name'] . ' · Cohorts & Assessments';
require APP_ROOT . '/app/layout/header.php';
require APP_ROOT . '/app/layout/institution_nav.php';
?>
<form method="post" id="form-cohort" class="card sm:p-8">
  <?= csrf_field() ?>
  <?php section_heading('users', 'amber', 'Step 4: Student Cohorts & Mandatory Employability Assessment', 'Adopt the Immediate Job-Seeker cohort and track university assessment progress for ' . $ay . '.'); ?>

  <div class="p-4 bg-sky-50 border border-sky-200 rounded-xl mb-6 flex items-start gap-3">
    <div class="text-sky-600 mt-0.5"><?= icon('info', 'w-5 h-5') ?></div>
    <div class="text-xs text-sky-900"><span class="font-bold">Principal Operational Cohort Policy:</span> <?= e(setting('cohort_policy')) ?></div>
  </div>

  <?php
    $campusNow = $old['campus_placed'] ?? $cohort['campus_placed'] ?? '';
    $campusPrevVal = $old['campus_placed_prev'] ?? $prevCampus ?? '';
    $delta = ($campusNow !== '' && $campusPrevVal !== '') ? (int) $campusNow - (int) $campusPrevVal : null;
  ?>
  <div class="p-4 rounded-xl border border-teal-200 bg-teal-50/50 mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3">
      <div>
        <span class="text-xs font-semibold uppercase text-teal-800 flex items-center gap-1">Campus-Placed Students
          <?= tooltip('Campus-placed students', 'Students who received job offers through campus recruitment (on-campus or pooled campus drives). Recorded for the current and the previous academic year so the change can be tracked.') ?></span>
        <span class="text-[11px] text-teal-700">Current academic year compared with last academic year.</span>
      </div>
      <?php if ($delta !== null): ?>
        <span class="text-xs font-semibold px-2.5 py-1 rounded-full <?= $delta >= 0 ? 'bg-emerald-100 text-emerald-800' : 'bg-rose-100 text-rose-700' ?>">
          <?= $delta >= 0 ? '▲' : '▼' ?> <?= num(abs($delta)) ?> vs <?= e($prevAy) ?><?= (int) $campusPrevVal > 0 ? ' (' . ($delta >= 0 ? '+' : '−') . abs((int) round($delta / (int) $campusPrevVal * 100)) . '%)' : '' ?>
        </span>
      <?php endif; ?>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div>
        <label class="label text-teal-800" for="campus-placed">This year · <?= e($ay) ?></label>
        <input type="number" id="campus-placed" name="campus_placed" min="0" value="<?= e($campusNow) ?>" placeholder="Not reported" class="w-full text-2xl font-bold text-teal-800 bg-white border border-teal-300 rounded-lg p-2 font-mono" <?= $ro ?>>
      </div>
      <div>
        <label class="label text-teal-800" for="campus-placed-prev">Last year · <?= e($prevAy) ?></label>
        <input type="number" id="campus-placed-prev" name="campus_placed_prev" min="0" value="<?= e($campusPrevVal) ?>" placeholder="Not reported" class="w-full text-2xl font-bold text-slate-600 bg-white border border-teal-200 rounded-lg p-2 font-mono" <?= $ro ?>>
      </div>
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6 mb-8">
    <div class="p-4 rounded-xl border border-slate-200 bg-slate-50">
      <span class="text-xs font-semibold uppercase text-slate-500 block mb-1">Total Final-Year Students <span class="req">*</span></span>
      <input type="number" id="cohort-total-final" name="total_final_year" min="1" required value="<?= e($old['total_final_year'] ?? $defaultTotal) ?>" class="w-full text-2xl font-bold text-slate-800 bg-white border border-slate-300 rounded-lg p-2 font-mono" <?= $ro ?>>
      <span class="hint">Department total: <?= num($m['dept_final_year']) ?></span>
    </div>
    <div class="p-4 rounded-xl border border-indigo-200 bg-indigo-50/50">
      <span class="text-xs font-semibold uppercase text-indigo-700 block mb-1">Registered on DWMS</span>
      <input type="number" id="cohort-dwms-reg" name="dwms_registered" min="0" value="<?= e($v('dwms_registered')) ?>" class="w-full text-2xl font-bold text-indigo-700 bg-white border border-indigo-300 rounded-lg p-2 font-mono" <?= $ro ?>>
      <span class="text-[11px] text-indigo-500 mt-1 block" id="dwms-ratio-note">DWMS Coverage: 0%</span>
    </div>
    <div class="p-4 rounded-xl border border-emerald-200 bg-emerald-50/50">
      <span class="text-xs font-semibold uppercase text-emerald-800 block mb-1">Immediate Job Seekers <span class="req">*</span></span>
      <input type="number" id="cohort-job-seekers" name="job_seekers" min="0" required value="<?= e($v('job_seekers')) ?>" class="w-full text-2xl font-bold text-emerald-800 bg-white border border-emerald-300 rounded-lg p-2 font-mono" <?= $ro ?>>
      <span class="text-[11px] text-emerald-700 mt-1 font-semibold block" id="jobseeker-ratio-note">Primary Target: 0% of batch</span>
    </div>
    <div class="p-4 rounded-xl border border-purple-200 bg-purple-50/40">
      <span class="text-xs font-semibold uppercase text-purple-700 block mb-1">Higher Studies Aspirants</span>
      <input type="number" name="higher_studies" min="0" value="<?= e($v('higher_studies')) ?>" class="w-full text-2xl font-bold text-purple-700 bg-white border border-purple-300 rounded-lg p-2 font-mono" <?= $ro ?>>
      <span class="text-[11px] text-purple-600 mt-1 block">Not counted in the job-seeker target.</span>
    </div>
  </div>

  <h3 class="text-sm font-bold text-slate-900 mb-1">Assessment Tests — <?= e($inst['university_short'] ?: $inst['university_name'] ?: 'University not set') ?></h3>
  <p class="text-xs text-slate-500 mb-4">Tests are defined by the State office for the institution's affiliated university. Progress is measured against immediate job seekers.</p>

  <?php if (!$inst['university_id']): ?>
    <div class="p-3 mb-4 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900">Set the affiliated university in the <a class="link" href="<?= e(url('institution-profile', ['id' => $instId])) ?>">profile</a> to see university-specific tests.</div>
  <?php endif; ?>

  <div class="space-y-4">
  <?php foreach ($tests as $t):
      $tid = (int) $t['id'];
      $p = $old['assess'][$tid] ?? $progress[$tid] ?? [];
      $done = $p['completed'] ?? $p['students_completed'] ?? '';
      $status = $p['status'] ?? 'Pending Allocation'; ?>
    <div class="border border-slate-200 rounded-2xl p-5 bg-white" data-assess-row>
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
        <div>
          <h4 class="text-sm font-bold text-slate-900 flex items-center gap-2">
            <?php if ($t['is_mandatory']): ?><span class="w-2 h-2 rounded-full bg-emerald-500 animate-ping"></span><?php endif; ?>
            <?= e($t['name']) ?>
            <?php if ($t['is_mandatory']): ?><span class="text-[10px] uppercase font-semibold px-2 py-0.5 rounded bg-emerald-100 text-emerald-700">Mandatory Gateway</span><?php endif; ?>
            <?php if ($t['university_short']): ?><span class="text-[10px] uppercase font-semibold px-2 py-0.5 rounded bg-indigo-100 text-indigo-700"><?= e($t['university_short']) ?></span><?php endif; ?>
          </h4>
          <p class="text-xs text-slate-500"><?= e($t['description']) ?></p>
        </div>
        <div class="text-right shrink-0">
          <span class="text-xs font-semibold text-slate-500">Completion Target:</span>
          <span class="text-sm font-bold text-emerald-600 block" data-badge>0 / 0 Done</span>
        </div>
      </div>
      <?php $vc = $vendor[$tid] ?? null; ?>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-start">
        <div>
          <label class="label">Students Completed</label>
          <input type="number" min="0" name="assess[<?= $tid ?>][completed]" value="<?= e($done) ?>" data-done class="input font-mono" <?= $ro ?>>
        </div>
        <div>
          <label class="label">Assessment Testing Status</label>
          <select name="assess[<?= $tid ?>][status]" class="input" <?= $ro ?>>
            <?php foreach (ASSESSMENT_STATUSES as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="label">Drive Date</label>
          <input type="date" name="assess[<?= $tid ?>][drive_date]" value="<?= e($p['drive_date'] ?? '') ?>" class="input" <?= $ro ?>>
        </div>
        <div>
          <span class="label flex items-center gap-1">Tests Conducted (Vendor)
            <?= tooltip('Tests conducted — vendor software', 'Count reported by the assessment vendor\'s dashboard for this institution\'s DWMS Institution ID. Uploaded in bulk by the Administrator or District office; it cannot be edited here. Use it to cross-check the field figure you enter in Students Completed.', 'right') ?></span>
          <div class="h-[42px] px-4 rounded-xl border border-dashed border-violet-300 bg-violet-50/60 flex items-center justify-between gap-2"
               <?= $vc ? 'data-vendor="' . (int) $vc['tests_conducted'] . '"' : '' ?>>
            <span class="font-mono font-bold <?= $vc ? 'text-violet-800' : 'text-slate-400' ?>"><?= $vc ? num($vc['tests_conducted']) : '—' ?></span>
            <span class="text-[10px] text-violet-600 text-right leading-tight"><?= $vc ? 'Uploaded ' . e(date('d M Y', strtotime($vc['uploaded_at']))) : 'Not uploaded yet' ?></span>
          </div>
          <span class="hint" data-vendor-note><?= $inst['dwms_id'] ? '' : 'Needs a DWMS Institution ID to match uploads.' ?></span>
        </div>
      </div>
      <div class="mt-4 pt-3 border-t border-slate-100">
        <div class="flex justify-between text-xs mb-1 font-medium">
          <span class="text-slate-500">Assessment compliance (of immediate job seekers)</span>
          <span class="text-sky-700 font-bold" data-pct>0%</span>
        </div>
        <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
          <div data-bar class="bg-linear-to-r from-sky-500 to-emerald-500 h-2.5 rounded-full transition-all duration-300" style="width: 0%"></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$tests): ?>
    <p class="text-sm text-slate-400 italic">No assessment tests are configured yet.</p>
  <?php endif; ?>
  </div>

  <div class="mt-6">
    <label class="label">Remarks</label>
    <textarea name="remarks" class="input min-h-20" placeholder="Notes on cohort identification, lab allocation, etc." <?= $ro ?>><?= e($v('remarks')) ?></textarea>
  </div>

  <?php if ($canEdit): ?>
  <div class="mt-8 pt-5 border-t border-slate-100 flex items-center justify-between gap-3">
    <a href="<?= e(url('institution-students', ['id' => $instId])) ?>" class="btn-secondary">&larr; Back</a>
    <div class="flex gap-2">
      <button name="go" value="stay" class="btn-secondary">Save</button>
      <button name="go" value="next" class="btn-primary">Save &amp; Browse DWMS Services <?= icon('arrow-right') ?></button>
    </div>
  </div>
  <?php endif; ?>
</form>
<?php require APP_ROOT . '/app/layout/footer.php';
