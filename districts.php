<?php
/**
 * District master: the 14 districts are fixed; State / Admin maintain each district's
 * support team (TCE, Regional Programme Manager, Regional Head) shown on institution dashboards.
 */
require __DIR__ . '/app/bootstrap.php';
require_role('admin', 'state');

$fields = [
    'tce_name' => 'TCE name', 'tce_email' => 'TCE email', 'tce_phone' => 'TCE phone',
    'rpm_name' => 'RPM name', 'rpm_email' => 'RPM email', 'rh_name' => 'Regional Head name', 'rh_email' => 'Regional Head email',
];

if (is_post()) {
    verify_csrf();
    $id = (int) int_input('id', 0);
    $district = null;
    foreach (districts() as $d) {
        if ((int) $d['id'] === $id) $district = $d;
    }
    if (!$district) {
        forbidden('District not found.');
    }
    $data = [];
    foreach ($fields as $k => $label) {
        $data[$k] = nullable(mb_substr((string) input($k), 0, 160));
        if ($data[$k] && str_ends_with($k, '_email') && !filter_var($data[$k], FILTER_VALIDATE_EMAIL)) {
            flash('error', "$label is not a valid email.");
            redirect('districts', ['edit' => $id]);
        }
    }
    $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($data)));
    db()->prepare("UPDATE districts SET {$set} WHERE id = ?")->execute([...array_values($data), $id]);
    $changes = diff_changes($district, $data, $fields);
    if ($changes) {
        log_activity('update', 'district', $id, "Updated support team for {$district['name']}", $changes, null, $id);
    }
    flash('success', "{$district['name']} support team saved.");
    redirect('districts');
}

$editId = int_input('edit');
$pageTitle = 'Districts & Support Team';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="mb-6">
  <nav class="text-xs text-slate-500 mb-2"><a class="link" href="<?= e(url('masters')) ?>">Masters</a> › Districts</nav>
  <h1 class="text-xl font-bold text-slate-900">Districts &amp; Support Team</h1>
  <p class="text-sm text-slate-500">Talent Connect Executive, Regional Programme Manager and Regional Head shown on each institution's dashboard.</p>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
  <?php foreach (districts() as $d): $editing = $editId === (int) $d['id']; ?>
    <div class="card <?= $editing ? 'border-sky-300 ring-2 ring-sky-100' : '' ?>">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-bold text-slate-900 flex items-center gap-2"><span class="text-[10px] font-mono bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded"><?= e($d['code']) ?></span> <?= e($d['name']) ?></h2>
        <?php if (!$editing): ?><a href="<?= e(url('districts', ['edit' => $d['id']])) ?>#d<?= $d['id'] ?>" class="btn-secondary btn-xs"><?= icon('pencil', 'w-3.5 h-3.5') ?> Edit</a><?php endif; ?>
      </div>
      <?php if ($editing): ?>
        <form method="post" id="d<?= $d['id'] ?>" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= $d['id'] ?>">
          <?php foreach ($fields as $k => $label): ?>
            <div><label class="label"><?= e($label) ?></label><input class="input input-sm" name="<?= $k ?>" value="<?= e($d[$k]) ?>" type="<?= str_ends_with($k, '_email') ? 'email' : 'text' ?>"></div>
          <?php endforeach; ?>
          <div class="sm:col-span-2 flex justify-end gap-2 pt-2">
            <a href="<?= e(url('districts')) ?>" class="btn-secondary btn-xs">Cancel</a>
            <button class="btn-primary btn-xs">Save</button>
          </div>
        </form>
      <?php else: ?>
        <dl class="grid grid-cols-3 gap-3 text-xs">
          <?php foreach ([['TCE', $d['tce_name'], $d['tce_email']], ['RPM', $d['rpm_name'], $d['rpm_email']], ['Regional Head', $d['rh_name'], $d['rh_email']]] as [$role, $n, $em]): ?>
            <div><dt class="text-[10px] uppercase font-bold tracking-wider text-slate-400"><?= $role ?></dt><dd class="font-semibold text-slate-800"><?= e($n ?: '—') ?></dd><dd class="text-slate-500 truncate"><?= e($em ?: '') ?></dd></div>
          <?php endforeach; ?>
        </dl>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
