<?php
/** Online exam: co-admins - portal users who can also view and modify this exam. Managed by the exam's creator. */
require __DIR__ . '/app/bootstrap.php';
$exam = load_exam();
$id = (int) $exam['id'];
$isOwner = is_exam_owner($exam);
const EXAM_ADMIN_ROLES = ['admin', 'state', 'district', 'institution'];

if (is_post()) {
    verify_csrf();
    if (!$isOwner) forbidden('Only the creator of the exam can change its co-admins.');
    $uid = (int) int_input('user_id', 0);
    if (input('action') === 'remove') {
        db()->prepare('DELETE FROM exam_admins WHERE exam_id = ? AND user_id = ?')->execute([$id, $uid]);
        flash('success', 'Co-admin removed.');
    } else {
        $st = db()->prepare("SELECT id, name FROM users WHERE id = ? AND is_active = 1 AND role IN ('" . implode("','", EXAM_ADMIN_ROLES) . "')");
        $st->execute([$uid]);
        $u = $st->fetch();
        if (!$u || $uid === (int) $exam['created_by']) {
            flash('error', 'Choose a valid user.');
        } else {
            db()->prepare('INSERT IGNORE INTO exam_admins (exam_id, user_id, added_by) VALUES (?,?,?)')->execute([$id, $uid, current_user()['id']]);
            flash('success', "{$u['name']} can now view and modify this exam.");
        }
    }
    redirect('exam-admins', ['id' => $id]);
}

$st = db()->prepare('SELECT u.id, u.name, u.username, u.role, u.designation, u.institution_name, d.name AS district_name, a.added_at
                     FROM exam_admins a JOIN users u ON u.id = a.user_id LEFT JOIN districts d ON d.id = u.district_id
                     WHERE a.exam_id = ? ORDER BY u.name');
$st->execute([$id]);
$admins = $st->fetchAll();
$candidates = [];
if ($isOwner) {
    $st = db()->prepare("SELECT u.id, u.name, u.username, u.role, d.name AS district_name FROM users u LEFT JOIN districts d ON d.id = u.district_id
                         WHERE u.is_active = 1 AND u.role IN ('" . implode("','", EXAM_ADMIN_ROLES) . "') AND u.id <> ?
                           AND u.id NOT IN (SELECT user_id FROM exam_admins WHERE exam_id = ?)
                         ORDER BY FIELD(u.role, 'admin','state','district','institution'), u.name");
    $st->execute([(int) $exam['created_by'], $id]);
    $candidates = $st->fetchAll();
}

$pageTitle = $exam['title'] . ' · Co-admins';
require APP_ROOT . '/app/layout/header.php';
$examTab = 'exam-admins';
require APP_ROOT . '/app/layout/exam_nav.php';
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
  <div class="card p-0 overflow-hidden lg:col-span-2">
    <div class="p-5 pb-3"><h2 class="card-title">Co-admins</h2><p class="card-subtitle">Besides <?= e($exam['owner_name'] ?? 'the creator') ?> (creator), these users can view and modify this exam. They find it under Activity → Online Exams.</p></div>
    <div class="overflow-x-auto">
      <table class="table">
        <thead><tr><th>User</th><th>Role</th><th>Added</th><?php if ($isOwner): ?><th></th><?php endif; ?></tr></thead>
        <tbody>
        <?php foreach ($admins as $a): ?>
          <tr>
            <td><p class="font-semibold text-slate-900"><?= e($a['name']) ?></p><p class="text-[11px] text-slate-400"><?= e(implode(' · ', array_filter([$a['username'], $a['designation'], $a['institution_name']]))) ?></p></td>
            <td class="text-xs"><?= e(ROLES[$a['role']] ?? $a['role']) ?><?= $a['district_name'] ? ' · ' . e($a['district_name']) : '' ?></td>
            <td class="text-xs text-slate-500"><?= e(time_ago($a['added_at'])) ?></td>
            <?php if ($isOwner): ?>
              <td class="text-right"><form method="post" data-confirm="Remove <?= e($a['name']) ?> as co-admin?"><?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="user_id" value="<?= $a['id'] ?>"><button class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50" title="Remove"><?= icon('x') ?></button></form></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$admins): ?><tr><td colspan="4" class="text-center text-slate-400 py-8">No co-admins yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($isOwner): ?>
  <form method="post" class="card space-y-4 self-start">
    <?= csrf_field() ?><input type="hidden" name="action" value="add">
    <h2 class="card-title">Add a co-admin</h2>
    <div>
      <label class="label">Portal user <span class="req">*</span></label>
      <select class="input" name="user_id" required>
        <option value="">Select user</option>
        <?php foreach ($candidates as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (<?= e(ROLES[$c['role']] . ($c['district_name'] ? ' · ' . $c['district_name'] : '')) ?>)</option><?php endforeach; ?>
      </select>
      <span class="hint">Co-admins can change the settings, question bank, series and participants, send exam passwords, mark answers and publish results.</span>
    </div>
    <button class="btn-primary w-full justify-center"><?= icon('plus') ?> Add co-admin</button>
  </form>
  <?php endif; ?>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
