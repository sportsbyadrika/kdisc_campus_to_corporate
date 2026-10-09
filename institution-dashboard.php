<?php
require __DIR__ . '/app/bootstrap.php';
[$inst, $m, $canEdit] = institution_context();
$activeTab = 'institution-dashboard';
$instId = (int) $inst['id'];
$ay = academic_year();

$district = null;
foreach (districts() as $d) {
    if ((int) $d['id'] === (int) $inst['district_id']) $district = $d;
}

$st = db()->prepare('SELECT * FROM institution_officers WHERE institution_id = ? ORDER BY is_primary DESC, name LIMIT 1');
$st->execute([$instId]);
$nodal = $st->fetch();

$rank = statewide_ranks();
$myRank = $rank['ranks'][$instId] ?? null;

$st = db()->prepare('SELECT s.name, s.tag, s.tag_color, x.status, x.beneficiaries, x.availed_on FROM institution_services x
                     JOIN dwms_services s ON s.id = x.service_id WHERE x.institution_id = ? ORDER BY s.sort_order');
$st->execute([$instId]);
$services = $st->fetchAll();

$st = db()->prepare('SELECT t.name, t.is_mandatory, a.students_completed, a.status, a.drive_date, v.tests_conducted AS vendor_count
                     FROM institution_assessments a
                     JOIN assessment_tests t ON t.id = a.assessment_test_id
                     LEFT JOIN assessment_vendor_counts v ON v.institution_id = a.institution_id AND v.academic_year = a.academic_year
                          AND v.assessment_test_id = a.assessment_test_id
                     WHERE a.institution_id = ? AND a.academic_year = ? ORDER BY t.is_mandatory DESC, t.name');
$st->execute([$instId, $ay]);
$assessments = $st->fetchAll();

$st = db()->prepare('SELECT COALESCE(c.name, \'Other\') course, SUM(d.final_year_count) n FROM institution_departments d
                     LEFT JOIN courses c ON c.id = d.course_id WHERE d.institution_id = ? AND d.academic_year = ?
                     GROUP BY course ORDER BY n DESC');
$st->execute([$instId, $ay]);
$courses = $st->fetchAll();
$maxCourse = $courses ? max(array_map('intval', array_column($courses, 'n'))) : 0;

// Trend from snapshots: keep the last snapshot of each day
$st = db()->prepare('SELECT DATE(recorded_at) d, job_seekers, dwms_registered, gateway_completed FROM cohort_snapshots
                     WHERE institution_id = ? AND academic_year = ? ORDER BY recorded_at');
$st->execute([$instId, $ay]);
$byDay = [];
foreach ($st->fetchAll() as $r) {
    $byDay[$r['d']] = ['label' => date('d M', strtotime($r['d'])), 'values' => [
        'dwms' => (int) $r['dwms_registered'], 'js' => (int) $r['job_seekers'], 'gw' => (int) $r['gateway_completed'],
    ]];
}
$trend = array_slice(array_values($byDay), -20);

$st = db()->prepare('SELECT l.*, ' . user_name_sql('u') . ' AS user_name FROM activity_log l LEFT JOIN users u ON u.id = l.user_id
                     WHERE l.institution_id = ? ORDER BY l.id DESC LIMIT 8');
$st->execute([$instId]);
$activity = $st->fetchAll();

// Milestones derived from live data
$gatewayStatus = $m['gateway_pct'] >= 80 ? 'Completed' : ($m['gateway_done'] > 0 ? 'In Progress' : 'Pending');
$milestones = [
    ['Institution Account & Workspace Creation', 'District / State Office', 'Completed', date('d M Y', strtotime($inst['created_at']))],
    ['Institution Profile, Affiliation & Location', 'Placement Cell', $m['sections']['profile'] ? 'Completed' : 'Pending', 'Step 1'],
    ['Placement Officer Profile & Nodal Assignment', 'Placement Cell', $m['sections']['officers'] ? 'Completed' : 'Pending', 'Step 2'],
    ['Department & Student Baseline Ingestion', 'Placement Cell & TCE', $m['sections']['students'] ? 'Completed' : 'Pending', 'Step 3'],
    ['Immediate Job-Seeker Cohort Identified', 'Placement Officer', $m['job_seekers'] > 0 ? 'Completed' : 'Pending', 'Step 4'],
    ['Mandatory Gateway Assessment (≥ 80% of job seekers)', 'Students / TCE', $gatewayStatus, $m['gateway_pct'] . '% done'],
    ['DWMS Registration Coverage (≥ 80% of batch)', 'TCE & DWMS Team', $m['dwms_pct'] >= 80 ? 'Completed' : ($m['dwms_registered'] > 0 ? 'In Progress' : 'Pending'), $m['dwms_pct'] . '% registered'],
    ['DWMS Services Availed & Delivered', 'Corporate Connect', $m['services_done'] > 0 ? 'Completed' : ($m['services'] > 0 ? 'In Progress' : 'Pending'), $m['services_done'] . ' of ' . $m['services'] . ' completed'],
];
$milestonesDone = count(array_filter($milestones, fn($x) => $x[2] === 'Completed'));

// Next tasks
$tasks = [];
$links = ['profile' => 'institution-profile', 'officers' => 'institution-officers', 'students' => 'institution-students', 'cohorts' => 'institution-cohorts', 'services' => 'institution-services'];
$labels = ['profile' => 'Complete the institution profile (university, category, address & map location).',
    'officers' => 'Add the placement / nodal officer details.', 'students' => 'Record course / department-wise student strength.',
    'cohorts' => 'Enter the cohort figures and assessment progress.', 'services' => 'Select the DWMS services availed.'];
foreach ($m['sections'] as $k => $ok) {
    if (!$ok) $tasks[] = [$labels[$k], url($links[$k], ['id' => $instId])];
}
$remaining = max(0, $m['gateway_base'] - $m['gateway_done']);
if ($m['job_seekers'] > 0 && $remaining > 0) {
    $tasks[] = ['Complete the gateway assessment for the remaining <span class="font-bold underline">' . num($remaining) . '</span> job seekers.', url('institution-cohorts', ['id' => $instId]), true];
}
if ($m['final_year'] > 0 && $m['dwms_pct'] < 100) {
    $tasks[] = ['Register the remaining <span class="font-bold underline">' . num(max(0, $m['final_year'] - $m['dwms_registered'])) . '</span> final-year students on DWMS.', url('institution-cohorts', ['id' => $instId]), true];
}

$instCode = 'C2C-' . ($district['code'] ?? 'KER') . '-' . str_pad((string) $instId, 4, '0', STR_PAD_LEFT);
$pageTitle = $inst['name'] . ' · Dashboard';
require APP_ROOT . '/app/layout/header.php';
require APP_ROOT . '/app/layout/institution_nav.php';
?>
<div class="space-y-6">

  <?php if ($m['status'] === 'Onboarded'): ?>
  <div class="p-4 bg-emerald-50 border border-emerald-200 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-2xs">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-xl bg-emerald-500 text-white flex items-center justify-center"><?= icon('check', 'w-5 h-5') ?></div>
      <div>
        <h3 class="text-sm font-bold text-emerald-900">Institution Onboarding Completed</h3>
        <p class="text-xs text-emerald-700">Profile verified, nodal officer attached, baseline mapped to the assigned TCE team.</p>
      </div>
    </div>
  <?php else: ?>
  <div class="p-4 bg-amber-50 border border-amber-200 rounded-2xl flex flex-col sm:flex-row sm:items-center justify-between gap-3 shadow-2xs">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-xl bg-amber-500 text-white flex items-center justify-center"><?= icon('warning', 'w-5 h-5') ?></div>
      <div>
        <h3 class="text-sm font-bold text-amber-900">Onboarding <?= $m['profile_pct'] ?>% complete</h3>
        <p class="text-xs text-amber-800"><?= 5 - count(array_filter($m['sections'])) ?> section(s) still need data before this institution is fully onboarded.</p>
      </div>
    </div>
  <?php endif; ?>
    <div class="flex gap-2 no-print">
      <button onclick="window.print()" class="px-3 py-1.5 bg-white border border-slate-300 text-slate-700 hover:bg-slate-50 text-xs font-semibold rounded-lg shadow-2xs flex items-center gap-1.5"><?= icon('printer') ?> Print / Export PDF</button>
      <?php if ($canEdit): ?><a href="<?= e(url('institution-profile', ['id' => $instId])) ?>" class="px-3 py-1.5 bg-sky-600 text-white hover:bg-sky-700 text-xs font-semibold rounded-lg shadow-2xs">Edit Data</a><?php endif; ?>
    </div>
  </div>

  <!-- Identity banner -->
  <div class="bg-linear-to-r from-slate-900 via-sky-950 to-indigo-950 rounded-2xl p-6 text-white shadow-md relative overflow-hidden">
    <?php if ($inst['photo']): ?>
      <img src="<?= e(upload_url($inst['photo'])) ?>" alt="" class="absolute inset-0 w-full h-full object-cover opacity-15">
    <?php endif; ?>
    <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-6">
      <div class="flex items-start gap-4 max-w-2xl">
        <?php if ($inst['logo']): ?>
          <img src="<?= e(upload_url($inst['logo'])) ?>" alt="Logo" class="w-16 h-16 rounded-xl bg-white p-1 object-contain shrink-0">
        <?php endif; ?>
        <div class="space-y-1.5">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wider <?= $inst['is_active'] ? 'bg-emerald-400/20 text-emerald-300 border border-emerald-400/30' : 'bg-rose-400/20 text-rose-200 border border-rose-400/30' ?>"><?= $inst['is_active'] ? 'Active Institution' : 'Inactive' ?></span>
            <span class="text-xs text-slate-300 font-mono">ID: <?= e($instCode) ?><?= $inst['dwms_id'] ? ' · DWMS ' . e($inst['dwms_id']) : '' ?><?= $inst['code'] ? ' · ' . e($inst['code']) : '' ?></span>
          </div>
          <h2 class="text-2xl font-black text-white break-words"><?= e($inst['name']) ?></h2>
          <p class="text-xs text-slate-300 flex items-center gap-2 flex-wrap">
            <span><?= e($inst['district_name']) ?></span> &bull;
            <span><?= e($inst['university_short'] ?: ($inst['university_name'] ?: 'University not set')) ?></span> &bull;
            <span><?= e($inst['category_name'] ?: 'Category not set') ?></span> &bull;
            <span>Nodal Officer: <strong class="text-white"><?= e($nodal['name'] ?? 'Not assigned') ?></strong></span>
          </p>
        </div>
      </div>
      <div class="bg-white/10 backdrop-blur-md border border-white/20 rounded-2xl p-4 flex items-center gap-6 divide-x divide-white/20 shrink-0">
        <div class="text-center px-2">
          <span class="text-[10px] font-semibold uppercase tracking-wider text-sky-200 block">Statewide Position</span>
          <span class="text-2xl sm:text-3xl font-extrabold text-amber-400"><?= $myRank ? 'Rank #' . $myRank : '—' ?></span>
          <span class="text-[10px] text-slate-300 block">Out of <?= num($rank['total']) ?> Registered</span>
        </div>
        <div class="text-center pl-6 pr-2">
          <span class="text-[10px] font-semibold uppercase tracking-wider text-sky-200 block">Readiness Score</span>
          <span class="text-2xl sm:text-3xl font-extrabold text-white"><?= $m['score'] ?><span class="text-xs font-normal text-slate-300">/100</span></span>
          <span class="text-[10px] text-emerald-300 font-semibold block">Gateway: <?= $m['gateway_pct'] ?>%</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Support hierarchy -->
  <?php $team = array_values(array_filter([
        ['TC', 'sky', 'Assigned TCE (Talent Connect Executive)', 'tce', $district['tce_name'] ?? null, trim(($district['tce_email'] ?? '') . (($district['tce_phone'] ?? '') ? ' • ' . $district['tce_phone'] : ''))],
        ['RP', 'indigo', 'Regional Programme Manager (RPM)', 'rpm', $district['rpm_name'] ?? null, $district['rpm_email'] ?? ''],
        ['RH', 'purple', 'Regional Head', 'rh', $district['rh_name'] ?? null, $district['rh_email'] ?? '']
    ], fn($t) => support_role_visible($t[3]))); ?>
  <div class="grid grid-cols-1 <?= grid_cols_class(count($team), 'md:') ?> gap-4">
    <?php foreach ($team as [$ab, $col, $role, $roleKey, $name, $contact]):
        $cls = ['sky' => 'bg-sky-100 text-sky-700', 'indigo' => 'bg-indigo-100 text-indigo-700', 'purple' => 'bg-purple-100 text-purple-700'][$col];
        $txt = ['sky' => 'text-sky-700', 'indigo' => 'text-indigo-700', 'purple' => 'text-purple-700'][$col]; ?>
      <div class="bg-white rounded-2xl p-4 border border-slate-200 shadow-xs flex items-center gap-3.5">
        <div class="w-12 h-12 rounded-xl <?= $cls ?> flex items-center justify-center font-bold text-lg shrink-0"><?= $ab ?></div>
        <div class="min-w-0">
          <span class="text-[10px] font-bold uppercase tracking-wider <?= $txt ?> flex items-center gap-1"><?= e($role) ?> <?= role_tooltip($roleKey, $roleKey === 'rh' && count($team) === 3 ? 'right' : 'left') ?></span>
          <p class="text-sm font-bold text-slate-900"><?= e($name ?: 'To be assigned') ?></p>
          <p class="text-xs text-slate-500 truncate"><?= e($contact ?: 'Contact via district office') ?></p>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Cohort KPIs -->
  <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
    <div class="kpi">
      <span class="kpi-label">Campus Placed</span>
      <span class="kpi-value text-teal-700"><?= $m['campus_placed'] === null ? '—' : num($m['campus_placed']) ?></span>
      <span class="kpi-note">Last year (<?= e(previous_academic_year($ay)) ?>): <?= $m['campus_placed_prev'] === null ? '—' : num($m['campus_placed_prev']) ?></span>
    </div>
    <div class="kpi">
      <span class="kpi-label">Final-Year Students</span>
      <span class="kpi-value"><?= num($m['final_year']) ?></span>
      <span class="kpi-note">Total eligible strength · <?= num($m['dept_count']) ?> departments</span>
    </div>
    <div class="kpi">
      <span class="kpi-label">Registered on DWMS</span>
      <span class="kpi-value text-sky-600"><?= num($m['dwms_registered']) ?></span>
      <span class="text-[11px] text-sky-600 font-semibold block mt-1"><?= $m['dwms_pct'] ?>% Onboarded</span>
    </div>
    <div class="kpi">
      <span class="kpi-label">Immediate Job Seekers</span>
      <span class="kpi-value text-emerald-600"><?= num($m['job_seekers']) ?></span>
      <span class="text-[11px] text-emerald-600 font-bold block mt-1">Primary Target Cohort · <?= $m['jobseeker_pct'] ?>%</span>
    </div>
    <div class="kpi">
      <span class="kpi-label">Gateway Assessment Completed</span>
      <span class="kpi-value text-purple-600"><?= num($m['gateway_done']) ?></span>
      <span class="text-[11px] text-purple-600 font-semibold block mt-1"><?= $m['gateway_pct'] ?>% of target</span>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Milestones -->
    <div class="lg:col-span-2 card">
      <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-100">
        <div>
          <h3 class="card-title">Campus to Corporate Milestone Tracker</h3>
          <p class="card-subtitle">Live status synced between Placement Cell, TCE, District & State offices</p>
        </div>
        <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-sky-100 text-sky-800 shrink-0"><?= $milestonesDone ?> of <?= count($milestones) ?> Complete</span>
      </div>
      <div class="space-y-3">
        <?php foreach ($milestones as [$name, $owner, $status, $target]):
            [$badge, $ico] = match ($status) {
                'Completed'   => ['bg-emerald-100 text-emerald-700', icon('check', 'w-4 h-4 text-emerald-600')],
                'In Progress' => ['bg-amber-100 text-amber-800', icon('clock', 'w-4 h-4 text-amber-600')],
                default       => ['bg-slate-100 text-slate-600', '<span class="w-2 h-2 rounded-full bg-slate-400"></span>'],
            }; ?>
          <div class="flex items-center justify-between gap-3 p-3 rounded-xl border border-slate-100 hover:bg-slate-50 transition-colors text-xs">
            <div class="flex items-center gap-3">
              <div class="w-7 h-7 rounded-lg bg-slate-50 flex items-center justify-center border border-slate-200 shrink-0"><?= $ico ?></div>
              <div>
                <p class="font-bold text-slate-800"><?= e($name) ?></p>
                <p class="text-[11px] text-slate-500">Responsible: <?= e($owner) ?> &bull; <?= e($target) ?></p>
              </div>
            </div>
            <span class="px-2.5 py-1 rounded-full font-semibold text-[10px] shrink-0 <?= $badge ?>"><?= e($status) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="space-y-6">
      <div class="card">
        <h3 class="card-title mb-1">DWMS Services Availed</h3>
        <p class="card-subtitle mb-4">Services selected for rollout during the institutional drive.</p>
        <ul class="space-y-2 text-xs">
          <?php foreach ($services as $s): ?>
            <li class="flex items-center justify-between gap-2 text-slate-700 bg-slate-50 p-2 rounded-lg border border-slate-100">
              <span class="flex items-center gap-2 min-w-0"><?= icon('check', 'w-3.5 h-3.5 text-emerald-600 shrink-0') ?><span class="truncate"><?= e($s['name']) ?></span></span>
              <span class="flex items-center gap-1.5 shrink-0"><?= $s['beneficiaries'] ? '<span class="font-mono text-slate-500">' . num($s['beneficiaries']) . '</span>' : '' ?><?= status_badge($s['status']) ?></span>
            </li>
          <?php endforeach; ?>
          <?php if (!$services): ?><li class="text-slate-400 italic">No services selected yet.</li><?php endif; ?>
        </ul>
      </div>

      <?php if ($tasks): ?>
      <div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 shadow-xs">
        <h4 class="text-xs font-bold uppercase tracking-wider text-amber-900 mb-2 flex items-center gap-1.5"><?= icon('warning', 'w-4 h-4 text-amber-600') ?> Immediate Next Tasks</h4>
        <ul class="text-xs text-amber-900 space-y-2 font-medium">
          <?php foreach ($tasks as $t): ?>
            <li class="flex items-start gap-1.5"><span class="text-amber-600">&bull;</span>
              <span><?= !empty($t[2]) ? $t[0] : e($t[0]) ?> <?php if ($canEdit): ?><a class="text-amber-700 underline no-print" href="<?= e($t[1]) ?>">Update</a><?php endif; ?></span></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 card">
      <h3 class="card-title">Cohort progress over time</h3>
      <p class="card-subtitle mb-4"><?= e($ay) ?> · recorded each time figures are updated</p>
      <?= line_chart($trend, ['dwms' => 'DWMS registered', 'js' => 'Immediate job seekers', 'gw' => 'Gateway completed'], 'trend') ?>
    </div>
    <div class="card space-y-4">
      <div>
        <h3 class="card-title">Assessment progress</h3>
        <p class="card-subtitle">Against <?= num($m['gateway_base']) ?> immediate job seekers</p>
      </div>
      <?php foreach ($assessments as $a): ?>
        <?= meter($a['name'] . ($a['is_mandatory'] ? ' ★' : ''), pct($a['students_completed'], $m['gateway_base']), num($a['students_completed']) . ' · ' . pct($a['students_completed'], $m['gateway_base']) . '%') ?>
        <p class="text-[11px] text-slate-400 -mt-2"><?= e($a['status']) ?><?= $a['drive_date'] ? ' · ' . e(date('d M Y', strtotime($a['drive_date']))) : '' ?><?= $a['vendor_count'] !== null ? ' · <span class="text-violet-600">vendor: ' . num($a['vendor_count']) . '</span>' : '' ?></p>
      <?php endforeach; ?>
      <?php if (!$assessments): ?><p class="text-xs text-slate-400 italic">No assessment data recorded.</p><?php endif; ?>
      <div class="pt-3 border-t border-slate-100 space-y-4">
        <?= meter('DWMS coverage', $m['dwms_pct']) ?>
        <?= meter('Profile completion', $m['profile_pct'], '', 'bg-emerald-600') ?>
      </div>
    </div>
  </div>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="card">
      <h3 class="card-title">Final-year students by course</h3>
      <p class="card-subtitle mb-4"><?= e($ay) ?></p>
      <ul class="space-y-3">
        <?php foreach ($courses as $c): ?>
          <li title="<?= e($c['course']) ?>: <?= num($c['n']) ?>">
            <div class="flex justify-between text-xs mb-1"><span class="font-medium text-slate-700"><?= e($c['course']) ?></span><span class="font-mono font-semibold text-slate-900"><?= num($c['n']) ?></span></div>
            <div class="bar-track"><div class="bar-fill" style="width: <?= pct($c['n'], $maxCourse) ?>%"></div></div>
          </li>
        <?php endforeach; ?>
        <?php if (!$courses): ?><li class="text-xs text-slate-400 italic">No departments recorded.</li><?php endif; ?>
      </ul>
    </div>

    <div class="card lg:col-span-2">
      <h3 class="card-title">About the institution</h3>
      <p class="card-subtitle mb-4"><?= e($inst['address'] ?: 'Address not recorded') ?><?= $inst['established_year'] ? ' · Est. ' . (int) $inst['established_year'] : '' ?></p>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="text-sm text-slate-600 whitespace-pre-line max-h-56 overflow-auto"><?= $inst['history'] ? e($inst['history']) : '<span class="text-xs text-slate-400 italic">No history added.</span>' ?></div>
        <?php if ($inst['latitude'] !== null): ?>
          <a class="block rounded-xl overflow-hidden border border-slate-200 relative group" target="_blank" rel="noopener"
             href="https://www.openstreetmap.org/?mlat=<?= e($inst['latitude']) ?>&mlon=<?= e($inst['longitude']) ?>#map=16/<?= e($inst['latitude']) ?>/<?= e($inst['longitude']) ?>">
            <div id="mini-map" class="h-48" data-lat="<?= e($inst['latitude']) ?>" data-lng="<?= e($inst['longitude']) ?>"></div>
            <span class="absolute bottom-2 right-2 bg-white/90 text-[11px] px-2 py-1 rounded-lg text-slate-700 shadow-xs z-[500]"><?= e(number_format((float) $inst['latitude'], 5)) ?>, <?= e(number_format((float) $inst['longitude'], 5)) ?> ↗</span>
          </a>
        <?php else: ?>
          <div class="h-48 rounded-xl border border-dashed border-slate-300 flex items-center justify-center text-xs text-slate-400">Location not set</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="flex items-center justify-between mb-3">
      <h3 class="card-title">Recent changes</h3>
      <?php if (has_role('admin', 'state', 'district')): ?><a class="text-xs link" href="<?= e(url('activity', ['institution' => $instId])) ?>">View all</a><?php endif; ?>
    </div>
    <ul class="divide-y divide-slate-100 text-xs">
      <?php foreach ($activity as $a): ?>
        <li class="py-2.5 flex items-start justify-between gap-3">
          <span><span class="font-semibold text-slate-800"><?= e($a['user_name'] ?? 'System') ?></span> <span class="text-slate-600"><?= e($a['summary']) ?></span></span>
          <span class="text-slate-400 shrink-0"><?= e(time_ago($a['created_at'])) ?></span>
        </li>
      <?php endforeach; ?>
      <?php if (!$activity): ?><li class="py-2 text-slate-400 italic">No changes recorded yet.</li><?php endif; ?>
    </ul>
  </div>
</div>

<?php if ($inst['latitude'] !== null): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
<script>
  (function () {
    const el = document.getElementById('mini-map');
    if (!el || typeof L === 'undefined') return;
    const p = [parseFloat(el.dataset.lat), parseFloat(el.dataset.lng)];
    const map = L.map(el, { zoomControl: false, dragging: false, scrollWheelZoom: false, doubleClickZoom: false, boxZoom: false, keyboard: false, touchZoom: false, attributionControl: true }).setView(p, 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
    L.marker(p).addTo(map);
  })();
</script>
<?php endif; ?>
<?php require APP_ROOT . '/app/layout/footer.php';
