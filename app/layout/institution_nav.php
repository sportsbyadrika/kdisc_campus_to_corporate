<?php
/**
 * Institution workspace header + stepper (mirrors the onboarding design).
 * Expects: $inst (institution row), $m (metrics row), $activeTab (string)
 */
$steps = [
    1 => ['institution-profile',   'Profile',        'profile',  'Institution Profile',              'Affiliation, category, location, logo, photo & history'],
    2 => ['institution-officers',  'Placement Officers', 'officers', 'Placement / Nodal Officers',  'Designated coordinators for Campus to Corporate activities'],
    3 => ['institution-students',  'Student Strength', 'students', 'Course / Department-wise Student Strength', 'Course-wise final-year batch counts for ' . academic_year()],
    4 => ['institution-cohorts',   'Cohorts & Tests', 'cohorts',  'Student Cohorts & Assessments',     'Immediate job-seeker cohort and university assessment progress'],
    5 => ['institution-services',  'DWMS Services',  'services', 'DWMS Services Availed',             'Employability services and modules availed by the institution'],
    6 => ['institution-dashboard', 'Dashboard',      null,       'Institution Dashboard',             'Unified view for Placement Cell, District and State offices'],
];
$current = 1;
foreach ($steps as $n => $s) {
    if ($s[0] === $activeTab) {
        $current = $n;
    }
}
$doneCount = count(array_filter($m['sections']));

// Institution switcher for users mapped to several institutions
$switch = [];
if (user_role() === 'institution') {
    $ids = assigned_institution_ids();
    if (count($ids) > 1) {
        $st = db()->prepare('SELECT id, name FROM institutions WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY name');
        $st->execute($ids);
        $switch = $st->fetchAll();
    }
}
?>
<div class="mb-6 no-print">
  <nav class="text-xs text-slate-500 mb-3 flex items-center gap-1.5 flex-wrap">
    <a class="link" href="<?= e(url('institutions')) ?>">Institutions</a>
    <?php if (user_role() !== 'institution'): ?>
      <?= icon('chevron-right', 'w-3 h-3') ?>
      <a class="link" href="<?= e(url('reports', ['district' => $inst['district_id']])) ?>"><?= e($inst['district_name']) ?></a>
    <?php endif; ?>
    <?= icon('chevron-right', 'w-3 h-3') ?>
    <span class="text-slate-700 font-medium truncate max-w-xs"><?= e($inst['name']) ?></span>
  </nav>

  <div class="bg-white rounded-2xl p-4 sm:p-5 shadow-xs border border-slate-200">
    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4 pb-3 border-b border-slate-100">
      <div class="flex items-center gap-3 min-w-0">
        <?php if ($inst['logo']): ?>
          <img src="<?= e(upload_url($inst['logo'])) ?>" alt="" class="w-11 h-11 rounded-xl object-contain border border-slate-200 bg-white shrink-0">
        <?php else: ?>
          <div class="w-11 h-11 rounded-xl bg-sky-100 text-sky-700 font-bold flex items-center justify-center shrink-0"><?= e(initials($inst['name'])) ?></div>
        <?php endif; ?>
        <div class="min-w-0">
          <h1 class="text-lg font-bold text-slate-900 truncate"><?= e($inst['name']) ?></h1>
          <p class="text-xs text-slate-500">Step <?= $current ?>: <?= e($steps[$current][3]) ?> — <?= e($steps[$current][4]) ?></p>
        </div>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <?php if ($switch): ?>
          <select class="input input-sm w-full sm:w-auto sm:max-w-xs" onchange="location.href=this.value" aria-label="Switch institution">
            <?php foreach ($switch as $s): ?>
              <option value="<?= e(url($activeTab, ['id' => $s['id']])) ?>" <?= (int) $s['id'] === (int) $inst['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
        <?= status_badge($m['status']) ?>
        <span class="text-xs font-semibold text-sky-700 bg-sky-50 px-2.5 py-1 rounded-full border border-sky-100"><?= $m['profile_pct'] ?>% Complete</span>
        <span class="text-xs font-medium text-slate-400"><?= $doneCount ?> of 5 sections</span>
      </div>
    </div>

    <ol class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-2">
      <?php foreach ($steps as $n => [$href, $label, $section]):
          $isActive = $n === $current;
          $isDone = $section ? $m['sections'][$section] : false;
          $tabCls = $isActive ? 'border-sky-600 bg-sky-50 text-sky-800 shadow-xs'
              : ($isDone ? 'border-emerald-300 bg-emerald-50 text-emerald-800' : 'border-slate-200 text-slate-500 hover:bg-slate-50');
          $badgeCls = $isActive ? 'bg-sky-600 text-white step-badge-active' : ($isDone ? 'bg-emerald-600 text-white' : 'bg-slate-200 text-slate-700'); ?>
        <li>
          <a href="<?= e(url($href, ['id' => $inst['id']])) ?>" class="flex items-center p-2 rounded-xl border transition-all <?= $tabCls ?>">
            <span class="w-6 h-6 rounded-lg font-bold text-xs flex items-center justify-center mr-2 shrink-0 <?= $badgeCls ?>">
              <?= $isDone && !$isActive ? icon('check', 'w-3.5 h-3.5') : $n ?>
            </span>
            <span class="text-xs <?= $isActive ? 'font-semibold' : 'font-medium' ?> truncate"><?= e($label) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</div>
<?php if (!can_edit_institution($inst) && $activeTab !== 'institution-dashboard'): ?>
  <div class="mb-6 p-3 rounded-xl bg-slate-100 border border-slate-200 text-xs text-slate-600 flex items-center gap-2 no-print">
    <?= icon('info') ?> You have read-only access to this institution.
  </div>
<?php endif; ?>
<?php
/** Section heading used by each workspace tab. */
function section_heading(string $iconName, string $color, string $title, string $subtitle): void
{
    $colors = [
        'sky' => 'bg-sky-100 text-sky-700', 'indigo' => 'bg-indigo-100 text-indigo-700',
        'emerald' => 'bg-emerald-100 text-emerald-700', 'amber' => 'bg-amber-100 text-amber-700',
        'purple' => 'bg-purple-100 text-purple-700',
    ];
    echo '<div class="flex items-center gap-3 mb-6"><div class="p-2.5 rounded-xl ' . $colors[$color] . '">' . icon($iconName, 'w-6 h-6') . '</div>'
        . '<div><h2 class="text-xl font-bold text-slate-900">' . e($title) . '</h2><p class="text-sm text-slate-500">' . e($subtitle) . '</p></div></div>';
}
