<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();
$canManage = has_role('admin', 'state'); // add institutions / edit registry record

/** Validate the registry fields of an institution form. */
function institution_registry_input(): array
{
    $d = [
        'name'          => mb_substr((string) input('name'), 0, 255),
        'code'          => nullable(input('code')),
        'dwms_id'       => nullable(input('dwms_id')),
        'email'         => nullable(input('email')),
        'address'       => nullable(input('address')),
        'district_id'   => (int) int_input('district_id', 0),
        'university_id' => int_input('university_id'),
        'category_id'   => int_input('category_id'),
    ];
    $errors = [];
    if ($d['name'] === '') $errors[] = 'Institution name is required.';
    if (!in_array($d['district_id'], array_map('intval', array_column(districts(), 'id')), true)) $errors[] = 'Select a valid district.';
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if ($err = dwms_id_error($d['dwms_id'], (int) int_input('id', 0))) $errors[] = $err;
    return [$d, $errors];
}

if (is_post()) {
    verify_csrf();
    if (!$canManage) {
        forbidden();
    }
    $action = input('action');
    $id = int_input('id');

    if ($action === 'toggle' && $id) {
        $inst = load_institution($id);
        $new = $inst['is_active'] ? 0 : 1;
        db()->prepare('UPDATE institutions SET is_active = ? WHERE id = ?')->execute([$new, $id]);
        log_activity('update', 'institution', $id, ($new ? 'Activated ' : 'Deactivated ') . $inst['name'], ['Active' => [$inst['is_active'], $new]], $id);
        flash('success', $inst['name'] . ($new ? ' activated.' : ' deactivated.'));
        redirect('institutions');
    }

    [$d, $errors] = institution_registry_input();
    $dup = db()->prepare('SELECT id FROM institutions WHERE name = ? AND district_id = ? AND id <> ?');
    $dup->execute([$d['name'], $d['district_id'], $id ?? 0]);
    if ($dup->fetch()) $errors[] = 'An institution with this name already exists in the district.';
    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        $_SESSION['_old'] = $_POST;
        redirect('institutions', $id ? ['edit' => $id] : ['new' => 1]);
    }

    if ($id) {
        $inst = load_institution($id);
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($d)));
        db()->prepare("UPDATE institutions SET {$set} WHERE id = ?")->execute([...array_values($d), $id]);
        $changes = diff_changes($inst, $d);
        if ($changes) log_activity('update', 'institution', $id, "Updated registry details of {$d['name']}", $changes, $id);
        flash('success', 'Institution updated.');
    } else {
        $d['created_by'] = $user['id'];
        $cols = implode(', ', array_map(fn($k) => "`$k`", array_keys($d)));
        db()->prepare("INSERT INTO institutions ({$cols}) VALUES (" . implode(',', array_fill(0, count($d), '?')) . ')')
            ->execute(array_values($d));
        $id = (int) db()->lastInsertId();
        log_activity('create', 'institution', $id, "Added institution {$d['name']}", [], $id, $d['district_id']);
        flash('success', 'Institution added. District users can now assign an institution user to it.');
    }
    redirect('institutions');
}

// ---- Listing ---------------------------------------------------------------
[$scopeSql, $scopeParams] = institution_scope('i');
$where = [$scopeSql];
$params = $scopeParams;
$f = ['q' => (string) input('q'), 'district' => int_input('district'), 'university' => int_input('university'), 'status' => (string) input('status')];
if ($f['q'] !== '') { $where[] = '(i.name LIKE ? OR i.code LIKE ? OR i.dwms_id LIKE ?)'; array_push($params, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%'); }
if ($f['district'] && is_state_level()) { $where[] = 'i.district_id = ?'; $params[] = $f['district']; }
if ($f['university']) { $where[] = 'i.university_id = ?'; $params[] = $f['university']; }
$rows = institution_metrics(implode(' AND ', $where), $params);
if ($f['status'] !== '') {
    $rows = array_values(array_filter($rows, fn($r) => $r['status'] === $f['status']));
}

$editId = int_input('edit');
$showForm = $canManage && ($editId || isset($_GET['new']));
$editing = $editId && $canManage ? load_institution($editId) : null;
$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
$v = fn(string $k) => $old[$k] ?? $editing[$k] ?? '';
$universities = lookup('universities');
$categories = lookup('institution_categories');

$pageTitle = 'Institutions';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">
  <div>
    <h1 class="text-xl font-bold text-slate-900"><?= $user['role'] === 'institution' ? 'My Institutions' : 'Institutions' ?></h1>
    <p class="text-sm text-slate-500">
      <?= $user['role'] === 'district' ? e(district_name((int) $user['district_id'])) . ' district · ' : '' ?><?= count($rows) ?> institution<?= count($rows) === 1 ? '' : 's' ?>
    </p>
  </div>
  <div class="flex gap-2">
    <?php if ($canManage && !$showForm): ?><a href="<?= e(url('institutions', ['new' => 1])) ?>" class="btn-primary"><?= icon('plus') ?> Add institution</a><?php endif; ?>
    <?php if ($user['role'] === 'district'): ?><a href="<?= e(url('institution-requests', ['new' => 1])) ?>" class="btn-primary"><?= icon('plus') ?> Request new institution</a><?php endif; ?>
  </div>
</div>

<?php if ($showForm): ?>
<form method="post" class="card mb-6">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
  <h2 class="card-title mb-1"><?= $editing ? 'Edit institution registry details' : 'Add a new institution' ?></h2>
  <p class="card-subtitle mb-5"><?= $editing ? 'Profile details are maintained by the institution / district in the workspace.' : 'Typically added on request from a district office. The institution completes the rest of the profile.' ?></p>
  <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
    <div class="md:col-span-3"><label class="label">Institution Full Legal Name <span class="req">*</span></label><input class="input" name="name" value="<?= e($v('name')) ?>" required></div>
    <div><label class="label">Affiliation Code</label><input class="input" name="code" value="<?= e($v('code')) ?>"></div>
    <div><label class="label flex items-center gap-1">DWMS Institution ID <?= tooltip('DWMS Institution ID', 'Unique ID of this institution in DWMS. Used to match the test counts uploaded from the assessment vendor software.') ?></label><input class="input font-mono" name="dwms_id" value="<?= e($v('dwms_id')) ?>" maxlength="40" placeholder="e.g. DWMS12345"></div>
    <div>
      <label class="label">District <span class="req">*</span></label>
      <select class="input" name="district_id" required>
        <option value="">Select District</option>
        <?php foreach (districts() as $d): ?><option value="<?= $d['id'] ?>" <?= (int) $v('district_id') === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label">Affiliated University</label>
      <select class="input" name="university_id">
        <option value="">Select University</option>
        <?php foreach ($universities as $u): ?><option value="<?= $u['id'] ?>" <?= (int) $v('university_id') === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label">Institution Category</label>
      <select class="input" name="category_id">
        <option value="">Select Category</option>
        <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= (int) $v('category_id') === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div><label class="label">Official Email</label><input class="input" type="email" name="email" value="<?= e($v('email')) ?>"></div>
    <div class="md:col-span-2"><label class="label">Address</label><input class="input" name="address" value="<?= e($v('address')) ?>"></div>
  </div>
  <div class="mt-6 pt-5 border-t border-slate-100 flex justify-end gap-2">
    <a href="<?= e(url('institutions')) ?>" class="btn-secondary">Cancel</a>
    <button class="btn-primary"><?= $editing ? 'Save changes' : 'Add institution' ?></button>
  </div>
</form>
<?php endif; ?>

<?php if ($user['role'] !== 'institution'): ?>
<form method="get" class="card p-4 mb-4 flex flex-wrap items-center gap-2">
  <div class="relative grow min-w-48">
    <span class="absolute left-3 top-2 text-slate-400"><?= icon('search') ?></span>
    <input name="q" value="<?= e($f['q']) ?>" class="input input-sm pl-9" placeholder="Search by name, code or DWMS ID">
  </div>
  <?php if (is_state_level()): ?>
  <select name="district" class="input input-sm w-auto">
    <option value="">All districts</option>
    <?php foreach (districts() as $d): ?><option value="<?= $d['id'] ?>" <?= $f['district'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select name="university" class="input input-sm w-auto">
    <option value="">All universities</option>
    <?php foreach ($universities as $u): ?><option value="<?= $u['id'] ?>" <?= $f['university'] === (int) $u['id'] ? 'selected' : '' ?>><?= e($u['short_name'] ?: $u['name']) ?></option><?php endforeach; ?>
  </select>
  <select name="status" class="input input-sm w-auto">
    <option value="">Any status</option>
    <?php foreach (['Onboarded', 'In Progress', 'Not Started', 'Inactive'] as $s): ?><option <?= $f['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
  </select>
  <button class="btn-secondary btn-xs" data-no-busy>Filter</button>
  <?php if (array_filter($f)): ?><a href="<?= e(url('institutions')) ?>" class="text-xs link">Clear</a><?php endif; ?>
</form>
<?php endif; ?>

<div class="card p-0 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr>
        <th>Institution</th><?php if (is_state_level()): ?><th>District</th><?php endif; ?><th>University / Category</th><th>Status</th><th class="min-w-36">Completion</th><th class="text-right">Final-year</th><th class="text-right">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-href="<?= e(url('institution-dashboard', ['id' => $r['id']])) ?>">
          <td>
            <div class="flex items-center gap-2.5">
              <?php if ($r['logo']): ?><img src="<?= e(upload_url($r['logo'])) ?>" alt="" class="w-8 h-8 rounded-lg object-contain border border-slate-200 bg-white shrink-0">
              <?php else: ?><span class="w-8 h-8 rounded-lg bg-sky-100 text-sky-700 text-[11px] font-bold flex items-center justify-center shrink-0"><?= e(initials($r['name'])) ?></span><?php endif; ?>
              <div class="min-w-0"><p class="font-semibold text-slate-900"><?= e($r['name']) ?></p><p class="text-[11px] text-slate-400 font-mono"><?= $r['dwms_id'] ? 'DWMS ' . e($r['dwms_id']) . ' · ' : '' ?><?= e($r['code'] ?: '—') ?></p></div>
            </div>
          </td>
          <?php if (is_state_level()): ?><td class="text-xs"><?= e($r['district_name']) ?></td><?php endif; ?>
          <td class="text-xs"><?= e($r['university_short'] ?: '—') ?><span class="block text-slate-400"><?= e($r['category_name'] ?: '—') ?></span></td>
          <td><?= status_badge($r['status']) ?></td>
          <td><div class="flex items-center gap-2" title="<?= $r['profile_pct'] ?>% of onboarding sections complete"><div class="bar-track"><div class="h-2 rounded-full bg-emerald-600" style="width: <?= $r['profile_pct'] ?>%"></div></div><span class="text-xs font-semibold w-9 text-right"><?= $r['profile_pct'] ?>%</span></div></td>
          <td class="text-right font-mono"><?= num($r['final_year']) ?></td>
          <td class="text-right whitespace-nowrap">
            <a href="<?= e(url('institution-dashboard', ['id' => $r['id']])) ?>" class="btn-secondary btn-xs">Dashboard</a>
            <?php if (can_edit_institution($r)): ?><a href="<?= e(url('institution-profile', ['id' => $r['id']])) ?>" class="btn-secondary btn-xs">Update</a><?php endif; ?>
            <?php if ($canManage): ?>
              <a href="<?= e(url('institutions', ['edit' => $r['id']])) ?>" class="inline-flex p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 align-middle" title="Edit registry"><?= icon('pencil') ?></a>
              <form method="post" class="inline" data-confirm="<?= $r['is_active'] ? 'Deactivate' : 'Activate' ?> <?= e($r['name']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="p-1.5 rounded-lg align-middle <?= $r['is_active'] ? 'text-rose-500 hover:bg-rose-50' : 'text-emerald-600 hover:bg-emerald-50' ?>" title="<?= $r['is_active'] ? 'Deactivate' : 'Activate' ?>"><?= icon($r['is_active'] ? 'x' : 'check') ?></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-slate-400 py-10">No institutions found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
