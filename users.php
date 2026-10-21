<?php
/**
 * User management.
 *  - Super admin: everyone (super admins are hidden from all other users)
 *  - Administrator: admin, state, district, institution and State/District-level Entity users
 *    (entities get a group scope - districts, universities, types, management types - instead of institutions)
 *  - District user: institution users of their district, mapped to one or more of its institutions
 */
require __DIR__ . '/app/bootstrap.php';
$me = require_role('admin', 'district');
$isDistrict = $me['role'] === 'district';

// ---- JSON: institution search for the "Assigned institutions" picker ---------
// Filtered server-side and capped, so the form never loads every institution at once.
if (isset($_GET['inst_search'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $where = ['i.is_active = 1'];
    $params = [];
    $district = $isDistrict ? (int) $me['district_id'] : (int) int_input('district', 0);
    $university = (int) int_input('university', 0);
    $type = (int) int_input('type', 0);
    $category = (int) int_input('category', 0);
    $q = mb_substr(trim((string) input('q')), 0, 100);
    if (!$isDistrict && !$district && !$university && !$type && !$category && mb_strlen($q) < 2) {
        exit(json_encode(['items' => [], 'total' => 0, 'hint' => 'Choose a district, university, type or management type, or type at least 2 letters of the name.']));
    }
    if ($district) { $where[] = 'i.district_id = ?'; $params[] = $district; }
    if ($university) { $where[] = 'i.university_id = ?'; $params[] = $university; }
    if ($type) { $where[] = 'i.type_id = ?'; $params[] = $type; }
    if ($category) { $where[] = 'i.category_id = ?'; $params[] = $category; }
    if ($q !== '') { $where[] = '(i.name LIKE ? OR i.code LIKE ? OR i.dwms_id LIKE ?)'; array_push($params, "%{$q}%", "%{$q}%", "%{$q}%"); }
    $sqlWhere = implode(' AND ', $where);
    $st = db()->prepare("SELECT COUNT(*) FROM institutions i WHERE {$sqlWhere}");
    $st->execute($params);
    $total = (int) $st->fetchColumn();
    $st = db()->prepare("SELECT i.id, i.name, d.name AS district, COALESCE(NULLIF(un.short_name, ''), un.name) AS university, t.name AS type, c.name AS category
                         FROM institutions i JOIN districts d ON d.id = i.district_id LEFT JOIN universities un ON un.id = i.university_id
                         LEFT JOIN institution_types t ON t.id = i.type_id LEFT JOIN institution_categories c ON c.id = i.category_id
                         WHERE {$sqlWhere} ORDER BY d.sort_order, i.name LIMIT 200");
    $st->execute($params);
    exit(json_encode(['items' => $st->fetchAll(), 'total' => $total], JSON_UNESCAPED_UNICODE));
}
$roles = creatable_roles();

// Valid ids for each dimension of an entity's group scope
$scopeOptions = [
    'district'   => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], districts()),
    'university' => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['short_name'] ? "{$r['name']} ({$r['short_name']})" : $r['name']], lookup('universities')),
    'type'       => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], lookup('institution_types')),
    'category'   => array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], lookup('institution_categories')),
];
$scopeLabels = ['district' => 'Districts', 'university' => 'Universities', 'type' => 'Institution types', 'category' => 'Management types'];

$loadUser = function (int $id): ?array {
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
};

if (is_post()) {
    verify_csrf();
    $action = input('action');
    $id = int_input('id');
    $target = $id ? $loadUser($id) : null;
    if ($id && (!$target || !can_manage_user($target))) {
        forbidden('You cannot manage this user.');
    }

    if ($action === 'toggle' && $target) {
        $new = $target['is_active'] ? 0 : 1;
        db()->prepare('UPDATE users SET is_active = ? WHERE id = ?')->execute([$new, $id]);
        log_activity('update', 'user', $id, ($new ? 'Activated' : 'Deactivated') . " user {$target['username']}", [], null, $target['district_id'] ? (int) $target['district_id'] : null);
        flash('success', "User {$target['username']} " . ($new ? 'activated.' : 'deactivated.'));
        redirect('users');
    }

    $d = [
        'name'        => mb_substr((string) input('name'), 0, 160),
        'username'    => strtolower((string) input('username')),
        'email'       => nullable(input('email')),
        'phone'       => nullable(input('phone')),
        'designation' => nullable(mb_substr((string) input('designation'), 0, 160)),
        'institution_name' => nullable(mb_substr((string) input('institution_name'), 0, 200)),
        'role'        => $isDistrict ? 'institution' : (string) input('role'),
        'district_id' => $isDistrict ? (int) $me['district_id'] : int_input('district_id'),
        'is_active'   => input('is_active', '1') === '1' ? 1 : 0,
    ];
    $password = (string) ($_POST['password'] ?? '');
    $instIds = array_values(array_unique(array_map('intval', (array) ($_POST['institutions'] ?? []))));

    $errors = [];
    if ($d['name'] === '') $errors[] = 'Name is required.';
    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $d['username'])) $errors[] = 'Username must be 3–60 characters: letters, numbers, dot, dash or underscore.';
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if (!in_array($d['role'], $roles, true) && !($target && $target['role'] === $d['role'])) $errors[] = 'You cannot assign this role.';
    if (!$target && strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($target && $password !== '' && strlen($password) < 8) $errors[] = 'New password must be at least 8 characters.';
    if ($d['role'] === 'district' && !$d['district_id']) $errors[] = 'Select the district for a district user.';
    if ($d['role'] === 'district_entity' && !$d['district_id']) $errors[] = 'Select the district for a District-level Entity.';
    if (!in_array($d['role'], ['district', 'institution', 'district_entity'], true)) $d['district_id'] = null;

    // Group scope (entities only): ticked ids per dimension; none ticked = all
    $scope = array_fill_keys(array_keys(SCOPE_DIMENSIONS), []);
    if (is_entity($d['role'])) {
        foreach ($scopeOptions as $dim => $opts) {
            if ($dim === 'district' && $d['role'] === 'district_entity') continue; // fixed to its own district
            $picked = array_values(array_unique(array_map('intval', (array) ($_POST["scope_{$dim}"] ?? []))));
            $valid = array_column($opts, 'id');
            if (array_diff($picked, $valid)) $errors[] = "One or more {$scopeLabels[$dim]} are invalid.";
            sort($picked);
            $scope[$dim] = array_values(array_intersect($picked, $valid));
        }
    }

    if ($d['role'] === 'institution') {
        if (!$instIds) $errors[] = 'Assign at least one institution.';
        if ($instIds) {
            $in = implode(',', array_fill(0, count($instIds), '?'));
            $st = db()->prepare("SELECT id, district_id FROM institutions WHERE id IN ($in)");
            $st->execute($instIds);
            $found = $st->fetchAll();
            if (count($found) !== count($instIds)) $errors[] = 'One or more institutions are invalid.';
            if ($isDistrict && array_filter($found, fn($r) => (int) $r['district_id'] !== (int) $me['district_id'])) {
                $errors[] = 'You can only assign institutions of your district.';
            }
            if (!$d['district_id'] && $found) {
                $d['district_id'] = (int) $found[0]['district_id']; // owning district
            }
        }
    } else {
        $instIds = [];
    }

    $st = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
    $st->execute([$d['username'], $id ?? 0]);
    if ($st->fetch()) $errors[] = 'This username is already taken.';

    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        $old = $_POST;
        unset($old['password']);
        $_SESSION['_old'] = $old;
        redirect('users', $id ? ['edit' => $id] : ['new' => 1]);
    }

    db()->beginTransaction();
    if ($target) {
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($d)));
        db()->prepare("UPDATE users SET {$set} WHERE id = ?")->execute([...array_values($d), $id]);
        if ($password !== '') {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
        }
        $changes = diff_changes($target, $d);
        if ($password !== '') $changes['password'] = ['•••', 'reset'];
    } else {
        db()->prepare('INSERT INTO users (name, username, email, phone, designation, institution_name, role, district_id, is_active, password_hash, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([...array_values($d), password_hash($password, PASSWORD_DEFAULT), $me['id']]);
        $id = (int) db()->lastInsertId();
        $changes = [];
    }
    $before = assigned_institution_ids($id);
    if ($before !== $instIds || !$target) {
        db()->prepare('DELETE FROM user_institutions WHERE user_id = ?')->execute([$id]);
        $ins = db()->prepare('INSERT INTO user_institutions (user_id, institution_id, assigned_by) VALUES (?,?,?)');
        foreach ($instIds as $iid) {
            $ins->execute([$id, $iid, $me['id']]);
        }
        sort($before);
        $sorted = $instIds;
        sort($sorted);
        if ($before !== $sorted) $changes['institutions'] = [implode(',', $before), implode(',', $sorted)];
    }
    $st = db()->prepare('SELECT dimension, ref_id FROM user_scopes WHERE user_id = ? ORDER BY dimension, ref_id');
    $st->execute([$id]);
    $scopeBefore = array_fill_keys(array_keys(SCOPE_DIMENSIONS), []);
    foreach ($st->fetchAll() as $r) $scopeBefore[$r['dimension']][] = (int) $r['ref_id'];
    if ($scopeBefore !== $scope) {
        db()->prepare('DELETE FROM user_scopes WHERE user_id = ?')->execute([$id]);
        $ins = db()->prepare('INSERT INTO user_scopes (user_id, dimension, ref_id) VALUES (?,?,?)');
        foreach ($scope as $dim => $ids) {
            foreach ($ids as $rid) $ins->execute([$id, $dim, $rid]);
        }
        $fmt = fn(array $sc) => implode('; ', array_map(fn($k) => $k . ':' . ($sc[$k] ? implode(',', $sc[$k]) : 'all'), array_keys($sc)));
        if (is_entity($d['role']) || array_filter($scopeBefore)) $changes['scope'] = [$fmt($scopeBefore), $fmt($scope)];
    }
    db()->commit();

    if ($d['role'] !== 'superadmin') {
        log_activity($target ? 'update' : 'create', 'user', $id, ($target ? 'Updated' : 'Created') . " {$d['role']} user {$d['username']}", $changes, null, $d['district_id']);
    }
    flash('success', $target ? 'User updated.' : "User {$d['username']} created.");
    redirect('users');
}

// ---- Listing --------------------------------------------------------------
$where = [];
$params = [];
if ($me['role'] !== 'superadmin') $where[] = "u.role <> 'superadmin'";   // super admin stays hidden
if ($isDistrict) { $where[] = "u.role = 'institution' AND u.district_id = ?"; $params[] = (int) $me['district_id']; }
$f = ['q' => (string) input('q'), 'role' => (string) input('role_filter'), 'district' => int_input('district')];
if ($f['q'] !== '') { $where[] = '(u.name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)'; array_push($params, "%{$f['q']}%", "%{$f['q']}%", "%{$f['q']}%"); }
if ($f['role'] !== '' && isset(ROLES[$f['role']])) { $where[] = 'u.role = ?'; $params[] = $f['role']; }
if ($f['district'] && !$isDistrict) { $where[] = 'u.district_id = ?'; $params[] = $f['district']; }
$st = db()->prepare('SELECT u.*, d.name AS district_name,
                            (SELECT GROUP_CONCAT(i.name ORDER BY i.name SEPARATOR \'||\') FROM user_institutions ui JOIN institutions i ON i.id = ui.institution_id WHERE ui.user_id = u.id) AS institutions
                     FROM users u LEFT JOIN districts d ON d.id = u.district_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
                     ORDER BY FIELD(u.role, 'superadmin','admin','state','state_entity','district','district_entity','institution'), d.sort_order, u.name");
$st->execute($params);
$users = $st->fetchAll();

$editId = int_input('edit');
$editing = $editId ? $loadUser($editId) : null;
if ($editing && !can_manage_user($editing)) {
    forbidden('You cannot manage this user.');
}
$showForm = $editing || isset($_GET['new']);
$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
$v = fn(string $k, $def = '') => $old[$k] ?? $editing[$k] ?? $def;
$formScope = isset($old['role']) || isset($old['name'])
    ? array_map(fn($dim) => array_map('intval', (array) ($old["scope_{$dim}"] ?? [])), array_combine(array_keys(SCOPE_DIMENSIONS), array_keys(SCOPE_DIMENSIONS)))
    : ($editing && is_entity($editing['role']) ? user_scope($editing) : array_fill_keys(array_keys(SCOPE_DIMENSIONS), []));
$selectedInst = isset($old['institutions']) ? array_map('intval', (array) $old['institutions']) : ($editing ? assigned_institution_ids((int) $editing['id']) : []);

// Only the already-assigned institutions are loaded with the page; others are found through the search above.
$selectedRows = [];
if ($showForm && $selectedInst) {
    $st = db()->prepare('SELECT i.id, i.name, d.name AS district FROM institutions i JOIN districts d ON d.id = i.district_id
                         WHERE i.id IN (' . implode(',', array_fill(0, count($selectedInst), '?')) . ') ORDER BY i.name');
    $st->execute($selectedInst);
    $selectedRows = $st->fetchAll();
}

$pageTitle = 'Users';
$extraScripts = [$showForm ? '<script src="' . e(asset('assets/js/inst-picker.js')) . '"></script>' : ''];
require APP_ROOT . '/app/layout/header.php';
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">
  <div>
    <h1 class="text-xl font-bold text-slate-900"><?= $isDistrict ? 'Institution Users' : 'Users' ?></h1>
    <p class="text-sm text-slate-500"><?= $isDistrict ? 'Create institution users and assign them to one or more institutions in ' . e(district_name((int) $me['district_id'])) . '.' : 'Manage administrators, state, district, entity and institution users.' ?></p>
  </div>
  <?php if (!$showForm): ?><a href="<?= e(url('users', ['new' => 1])) ?>" class="btn-primary"><?= icon('plus') ?> New user</a><?php endif; ?>
</div>

<?php if ($showForm): ?>
<form method="post" class="card mb-6" autocomplete="off">
  <?= csrf_field() ?>
  <input type="hidden" name="id" value="<?= e($editing['id'] ?? '') ?>">
  <h2 class="card-title mb-5"><?= $editing ? 'Edit user — ' . e($editing['username']) : 'Create a user' ?></h2>
  <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
    <div><label class="label">Full name <span class="req">*</span></label><input class="input" name="name" value="<?= e($v('name')) ?>" required></div>
    <div><label class="label">Username <span class="req">*</span></label><input class="input font-mono" name="username" value="<?= e($v('username')) ?>" required pattern="[a-zA-Z0-9._\-]{3,60}" autocomplete="off"></div>
    <div>
      <label class="label"><?= $editing ? 'Reset password' : 'Password' ?> <?= $editing ? '' : '<span class="req">*</span>' ?></label>
      <input class="input" type="password" name="password" minlength="8" <?= $editing ? '' : 'required' ?> autocomplete="new-password" placeholder="<?= $editing ? 'Leave blank to keep current' : 'Min. 8 characters' ?>">
    </div>
    <div><label class="label">Email</label><input class="input" type="email" name="email" value="<?= e($v('email')) ?>"></div>
    <div><label class="label">Mobile</label><input class="input" name="phone" value="<?= e($v('phone')) ?>"></div>
    <div><label class="label">Designation</label><input class="input" name="designation" value="<?= e($v('designation')) ?>" maxlength="160" placeholder="e.g. Placement Officer, Registrar"></div>
    <div><label class="label">Name of Institution</label><input class="input" name="institution_name" value="<?= e($v('institution_name')) ?>" maxlength="200" placeholder="Institution / organisation the user belongs to"></div>
    <div>
      <label class="label">Role <span class="req">*</span></label>
      <?php if ($isDistrict): ?>
        <input class="input" value="Institution User" disabled><input type="hidden" id="user-role" value="institution">
      <?php else: ?>
        <select class="input" name="role" id="user-role" required>
          <?php foreach ($roles as $r): ?><option value="<?= $r ?>" <?= $v('role', 'institution') === $r ? 'selected' : '' ?>><?= e(ROLES[$r]) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>
    <?php if (!$isDistrict): ?>
    <div data-role-field="district,institution,district_entity">
      <label class="label">District</label>
      <select class="input" name="district_id" id="user-district">
        <option value="">Select District</option>
        <?php foreach (districts() as $d): ?><option value="<?= $d['id'] ?>" <?= (int) $v('district_id') === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
      </select>
      <span class="hint">Required for district users and District-level Entities; filters institutions for institution users.</span>
    </div>
    <?php endif; ?>
    <div>
      <label class="label">Status</label>
      <select class="input" name="is_active"><option value="1">Active</option><option value="0" <?= (string) $v('is_active', '1') === '0' ? 'selected' : '' ?>>Inactive</option></select>
    </div>
  </div>

  <?php if (!$isDistrict): ?>
  <div class="mt-6 bg-slate-50 p-4 rounded-xl border border-slate-200" data-role-field="state_entity,district_entity">
    <div class="mb-3">
      <h3 class="text-sm font-bold text-slate-800">Group institution access</h3>
      <p class="text-xs text-slate-500">The user sees, view-only, every institution matching <strong>all</strong> of the choices below. Leave a group on “All” for no restriction — e.g. a university: all districts, all types, all management types, with that university ticked.</p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
      <?php foreach ($scopeOptions as $dim => $opts): ?>
        <fieldset class="bg-white rounded-lg border border-slate-200 p-3" data-scope-group <?= $dim === 'district' ? 'data-role-field="state_entity"' : '' ?>>
          <legend class="px-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500"><?= e($scopeLabels[$dim]) ?></legend>
          <label class="flex items-center gap-2 py-1 text-sm font-semibold text-slate-800 border-b border-slate-100 mb-1">
            <input type="checkbox" data-scope-all class="h-4 w-4 rounded border-slate-300 text-sky-600" <?= $formScope[$dim] ? '' : 'checked' ?>> All <?= e(strtolower($scopeLabels[$dim])) ?>
          </label>
          <div class="max-h-48 overflow-auto pr-1">
            <?php foreach ($opts as $o): ?>
              <label class="flex items-start gap-2 py-1 text-xs text-slate-700">
                <input type="checkbox" name="scope_<?= $dim ?>[]" value="<?= $o['id'] ?>" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600" <?= in_array($o['id'], $formScope[$dim], true) ? 'checked' : '' ?>> <?= e($o['name']) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </fieldset>
      <?php endforeach; ?>
    </div>
    <p class="text-[11px] text-slate-500 mt-2" data-role-field="district_entity">A District-level Entity is always limited to the district selected above.</p>
  </div>
  <?php endif; ?>

  <div class="mt-6 bg-slate-50 p-4 rounded-xl border border-slate-200" data-role-field="institution" id="inst-picker"
       data-search-url="<?= e(url('users', ['inst_search' => 1])) ?>">
    <div class="mb-3">
      <h3 class="text-sm font-bold text-slate-800">Assigned institutions <span class="req">*</span></h3>
      <p class="text-xs text-slate-500">The user can update and view the dashboard of each selected institution. Filter to find institutions, then tick them.</p>
    </div>

    <div class="mb-3">
      <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500 mb-1.5">Selected (<span id="inst-selected-count"><?= count($selectedRows) ?></span>)</p>
      <div id="inst-selected" class="flex flex-wrap gap-1.5 min-h-8">
        <?php foreach ($selectedRows as $sr): ?>
          <span class="inline-flex items-center gap-1.5 pl-2.5 pr-1 py-1 rounded-lg bg-sky-100 text-sky-800 text-xs font-medium" data-chip="<?= (int) $sr['id'] ?>">
            <?= e($sr['name']) ?><?php if (!$isDistrict): ?> <span class="text-sky-600/70">· <?= e($sr['district']) ?></span><?php endif; ?>
            <input type="hidden" name="institutions[]" value="<?= (int) $sr['id'] ?>">
            <button type="button" class="p-0.5 rounded hover:bg-sky-200" data-chip-remove aria-label="Remove <?= e($sr['name']) ?>"><?= icon('x', 'w-3.5 h-3.5') ?></button>
          </span>
        <?php endforeach; ?>
        <span id="inst-none" class="text-xs text-slate-400 italic py-1 <?= $selectedRows ? 'hidden' : '' ?>">No institution selected yet.</span>
      </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 <?= $isDistrict ? 'lg:grid-cols-4' : 'lg:grid-cols-5' ?> gap-2 mb-2">
      <?php if (!$isDistrict): ?>
        <select id="pick-district" class="input input-sm" aria-label="District">
          <option value="">All districts</option>
          <?php foreach (districts() as $d): ?><option value="<?= (int) $d['id'] ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <select id="pick-university" class="input input-sm" aria-label="University">
        <option value="">All universities</option>
        <?php foreach (lookup('universities') as $un): ?><option value="<?= (int) $un['id'] ?>"><?= e($un['short_name'] ?: $un['name']) ?></option><?php endforeach; ?>
      </select>
      <select id="pick-type" class="input input-sm" aria-label="Institution type"><?= institution_type_options(null, 'All institution types') ?></select>
      <select id="pick-category" class="input input-sm" aria-label="Management type">
        <option value="">All management types</option>
        <?php foreach (lookup('institution_categories') as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
      <input type="search" id="pick-q" class="input input-sm" placeholder="Search name / code / DWMS ID" autocomplete="off">
    </div>
    <p class="text-[11px] text-slate-500 mb-2" id="inst-results-hint"><?= $isDistrict ? 'Loading institutions of your district…' : 'Choose a district, university, type or management type, or type at least 2 letters of the name.' ?></p>
    <div id="inst-results" class="grid grid-cols-1 md:grid-cols-2 gap-1.5 max-h-72 overflow-auto pr-1"></div>
  </div>

  <div class="mt-6 pt-5 border-t border-slate-100 flex justify-end gap-2">
    <a href="<?= e(url('users')) ?>" class="btn-secondary">Cancel</a>
    <button class="btn-primary"><?= $editing ? 'Save changes' : 'Create user' ?></button>
  </div>
</form>
<?php endif; ?>

<form method="get" class="card p-4 mb-4 flex flex-wrap items-center gap-2">
  <div class="relative grow min-w-48">
    <span class="absolute left-3 top-2 text-slate-400"><?= icon('search') ?></span>
    <input name="q" value="<?= e($f['q']) ?>" class="input input-sm pl-9" placeholder="Search name, username or email">
  </div>
  <?php if (!$isDistrict): ?>
    <select name="role_filter" class="input input-sm w-auto">
      <option value="">All roles</option>
      <?php foreach (ROLES as $k => $lbl): if ($k === 'superadmin' && $me['role'] !== 'superadmin') continue; ?>
        <option value="<?= $k ?>" <?= $f['role'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="district" class="input input-sm w-auto">
      <option value="">All districts</option>
      <?php foreach (districts() as $d): ?><option value="<?= $d['id'] ?>" <?= $f['district'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select>
  <?php endif; ?>
  <button class="btn-secondary btn-xs" data-no-busy>Filter</button>
</form>

<div class="card p-0 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="table">
      <thead><tr><th>User</th><th>Role</th><th>Scope</th><th>Last login</th><th>Status</th><th class="text-right">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($users as $u):
          $insts = $u['institutions'] ? explode('||', $u['institutions']) : []; ?>
        <tr>
          <td>
            <div class="flex items-center gap-2.5">
              <span class="w-8 h-8 rounded-lg bg-slate-100 text-slate-600 text-[11px] font-bold flex items-center justify-center shrink-0"><?= e(initials($u['name'])) ?></span>
              <div><p class="font-semibold text-slate-900"><?= e($u['name']) ?></p><?php if ($u['designation'] || $u['institution_name']): ?><p class="text-[11px] text-slate-500"><?= e(implode(', ', array_filter([$u['designation'], $u['institution_name']]))) ?></p><?php endif; ?><p class="text-[11px] text-slate-400 font-mono"><?= e($u['username']) ?><?= $u['email'] ? ' · ' . e($u['email']) : '' ?></p></div>
            </div>
          </td>
          <td class="text-xs font-medium"><?= e(ROLES[$u['role']]) ?></td>
          <td class="text-xs max-w-xs">
            <?php if ($u['role'] === 'institution'): ?>
              <span title="<?= e(implode("\n", $insts)) ?>"><?= e($insts[0] ?? '—') ?><?= count($insts) > 1 ? ' <span class="text-slate-400">+' . (count($insts) - 1) . ' more</span>' : '' ?></span>
            <?php elseif (is_entity($u['role'])): ?><span class="text-slate-600"><?= e(scope_summary(user_scope($u))) ?></span>
            <?php elseif ($u['role'] === 'district'): ?><?= e($u['district_name']) ?>
            <?php else: ?><span class="text-slate-400">Statewide</span><?php endif; ?>
          </td>
          <td class="text-xs text-slate-500"><?= e(time_ago($u['last_login_at'])) ?></td>
          <td><?= $u['is_active'] ? status_badge('Active') : status_badge('Inactive') ?></td>
          <td class="text-right whitespace-nowrap">
            <?php if (can_manage_user($u)): ?>
              <a href="<?= e(url('users', ['edit' => $u['id']])) ?>" class="inline-flex p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 align-middle" title="Edit"><?= icon('pencil') ?></a>
              <form method="post" class="inline" data-confirm="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> <?= e($u['username']) ?>?">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="p-1.5 rounded-lg align-middle <?= $u['is_active'] ? 'text-rose-500 hover:bg-rose-50' : 'text-emerald-600 hover:bg-emerald-50' ?>" title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>"><?= icon($u['is_active'] ? 'x' : 'check') ?></button>
              </form>
            <?php elseif ((int) $u['id'] === (int) $me['id']): ?>
              <a href="<?= e(url('account')) ?>" class="text-xs link">My account</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$users): ?><tr><td colspan="6" class="text-center text-slate-400 py-10">No users found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
