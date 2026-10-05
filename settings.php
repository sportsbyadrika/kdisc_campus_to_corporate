<?php
require __DIR__ . '/app/bootstrap.php';
require_role('admin');

$fields = [
    'app_name'      => ['Portal name', 'text'],
    'app_tagline'   => ['Tagline', 'text'],
    'academic_year' => ['Current academic year', 'text'],
    'cohort_policy' => ['Cohort policy notice (Step 4)', 'textarea'],
    'footer_left'   => ['Footer text (left)', 'text'],
    'footer_right'  => ['Footer text (right)', 'text'],
];

if (is_post()) {
    verify_csrf();
    $current = [];
    foreach (db()->query('SELECT `key`, `value` FROM settings') as $r) {
        $current[$r['key']] = $r['value'];
    }
    $new = [];
    foreach ($fields as $k => [$label]) {
        $new[$k] = mb_substr((string) input($k), 0, $k === 'cohort_policy' ? 2000 : 200);
    }
    if (!preg_match('/^(\d{4})-(\d{2})$/', $new['academic_year'], $mt) || (int) $mt[2] !== ((int) $mt[1] + 1) % 100) {
        flash('error', 'Academic year must look like 2026-27.');
        redirect('settings');
    }
    if ($new['app_name'] === '') {
        flash('error', 'Portal name is required.');
        redirect('settings');
    }
    $st = db()->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    foreach ($new as $k => $v) {
        $st->execute([$k, $v]);
    }
    $changes = diff_changes($current, $new);
    if ($changes) {
        log_activity('update', 'settings', null, 'Updated portal settings (' . implode(', ', array_keys($changes)) . ')', $changes);
    }
    flash('success', 'Settings saved.' . (isset($changes['academic_year']) ? ' New data entry now uses academic year ' . $new['academic_year'] . '.' : ''));
    redirect('settings');
}

// Data volume overview per academic year
$years = db()->query("SELECT academic_year, COUNT(DISTINCT institution_id) n FROM institution_cohorts GROUP BY academic_year
                      UNION SELECT academic_year, COUNT(DISTINCT institution_id) FROM institution_departments GROUP BY academic_year
                      ORDER BY academic_year DESC")->fetchAll();
$yearMap = [];
foreach ($years as $y) {
    $yearMap[$y['academic_year']] = max($yearMap[$y['academic_year']] ?? 0, (int) $y['n']);
}

$pageTitle = 'Settings';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="mb-6">
  <h1 class="text-xl font-bold text-slate-900">Settings</h1>
  <p class="text-sm text-slate-500">Portal-wide configuration for the Campus to Corporate programme.</p>
</div>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <form method="post" class="card lg:col-span-2 space-y-5">
    <?= csrf_field() ?>
    <?php foreach ($fields as $k => [$label, $type]): ?>
      <div>
        <label class="label" for="<?= $k ?>"><?= e($label) ?></label>
        <?php if ($type === 'textarea'): ?>
          <textarea class="input min-h-24" id="<?= $k ?>" name="<?= $k ?>"><?= e(setting($k)) ?></textarea>
        <?php else: ?>
          <input class="input" id="<?= $k ?>" name="<?= $k ?>" value="<?= e(setting($k)) ?>" <?= $k === 'academic_year' ? 'pattern="\d{4}-\d{2}" placeholder="2026-27"' : '' ?>>
        <?php endif; ?>
        <?php if ($k === 'academic_year'): ?><span class="hint">Student strength, cohorts and assessments are recorded per academic year. Changing this starts a fresh year; earlier years stay in the database.</span><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <div class="pt-4 border-t border-slate-100 flex justify-end"><button class="btn-primary">Save settings</button></div>
  </form>
  <div class="card self-start">
    <h2 class="card-title mb-1">Academic years on record</h2>
    <p class="card-subtitle mb-4">Institutions with cohort or student data</p>
    <ul class="space-y-2 text-sm">
      <?php foreach ($yearMap as $y => $n): ?>
        <li class="flex justify-between"><span class="font-medium <?= $y === academic_year() ? 'text-sky-700' : 'text-slate-700' ?>"><?= e($y) ?><?= $y === academic_year() ? ' (current)' : '' ?></span><span class="font-mono"><?= num($n) ?></span></li>
      <?php endforeach; ?>
      <?php if (!$yearMap): ?><li class="text-xs text-slate-400 italic">No data yet.</li><?php endif; ?>
    </ul>
  </div>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
