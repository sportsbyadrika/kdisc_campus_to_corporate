<?php
/**
 * New-institution requests: district users raise them; State / Admin approve (creating
 * the institution) or reject them.
 */
require __DIR__ . '/app/bootstrap.php';
$user = require_role('admin', 'state', 'district');
$isDistrict = $user['role'] === 'district';
$canReview = has_role('admin', 'state');

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'create' && $isDistrict) {
        $d = [
            'name'          => mb_substr((string) input('name'), 0, 255),
            'code'          => nullable(input('code')),
            'email'         => nullable(input('email')),
            'address'       => nullable(input('address')),
            'university_id' => int_input('university_id'),
            'category_id'   => int_input('category_id'),
            'remarks'       => nullable(mb_substr((string) input('remarks'), 0, 1000)),
        ];
        if ($d['name'] === '' || ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL))) {
            flash('error', $d['name'] === '' ? 'Institution name is required.' : 'Enter a valid email.');
            redirect('institution-requests', ['new' => 1]);
        }
        db()->prepare('INSERT INTO institution_requests (district_id, name, code, email, address, university_id, category_id, remarks, requested_by) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([(int) $user['district_id'], ...array_values($d), $user['id']]);
        log_activity('create', 'request', (int) db()->lastInsertId(), "Requested new institution {$d['name']}", [], null, (int) $user['district_id']);
        flash('success', 'Request submitted to the State office.');
        redirect('institution-requests');
    }

    $reqId = (int) int_input('request_id', 0);
    $st = db()->prepare('SELECT * FROM institution_requests WHERE id = ?');
    $st->execute([$reqId]);
    $req = $st->fetch();
    if (!$req) {
        flash('error', 'Request not found.');
        redirect('institution-requests');
    }

    if ($action === 'withdraw' && $isDistrict && (int) $req['district_id'] === (int) $user['district_id'] && $req['status'] === 'pending') {
        db()->prepare('DELETE FROM institution_requests WHERE id = ?')->execute([$reqId]);
        log_activity('delete', 'request', $reqId, "Withdrew request for {$req['name']}", [], null, (int) $req['district_id']);
        flash('success', 'Request withdrawn.');
        redirect('institution-requests');
    }

    if (in_array($action, ['approve', 'reject'], true) && $canReview && $req['status'] === 'pending') {
        $remarks = nullable(mb_substr((string) input('review_remarks'), 0, 1000));
        db()->beginTransaction();
        $instId = null;
        if ($action === 'approve') {
            db()->prepare('INSERT INTO institutions (name, code, email, address, district_id, university_id, category_id, created_by) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$req['name'], $req['code'], $req['email'], $req['address'], $req['district_id'], $req['university_id'], $req['category_id'], $user['id']]);
            $instId = (int) db()->lastInsertId();
        }
        db()->prepare('UPDATE institution_requests SET status = ?, review_remarks = ?, institution_id = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?')
            ->execute([$action === 'approve' ? 'approved' : 'rejected', $remarks, $instId, $user['id'], $reqId]);
        db()->commit();
        log_activity($action, 'request', $reqId, ucfirst($action) . "d request for {$req['name']}", [], $instId, (int) $req['district_id']);
        flash('success', $action === 'approve' ? "Approved — {$req['name']} has been added." : 'Request rejected.');
        redirect('institution-requests');
    }
    forbidden();
}

$status = (string) input('status', 'pending');
$where = ['1=1'];
$params = [];
if ($isDistrict) { $where[] = 'r.district_id = ?'; $params[] = (int) $user['district_id']; }
if (in_array($status, ['pending', 'approved', 'rejected'], true)) { $where[] = 'r.status = ?'; $params[] = $status; }
$st = db()->prepare('SELECT r.*, d.name AS district_name, u.short_name AS university_short, c.name AS category_name,
                            ' . user_name_sql('rq') . ' AS requested_by_name, ' . user_name_sql('rv') . ' AS reviewed_by_name
                     FROM institution_requests r JOIN districts d ON d.id = r.district_id
                     LEFT JOIN universities u ON u.id = r.university_id LEFT JOIN institution_categories c ON c.id = r.category_id
                     LEFT JOIN users rq ON rq.id = r.requested_by LEFT JOIN users rv ON rv.id = r.reviewed_by
                     WHERE ' . implode(' AND ', $where) . ' ORDER BY r.created_at DESC');
$st->execute($params);
$requests = $st->fetchAll();

$pageTitle = 'Institution Requests';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">
  <div>
    <h1 class="text-xl font-bold text-slate-900">New Institution Requests</h1>
    <p class="text-sm text-slate-500"><?= $isDistrict ? 'Request the State office to add institutions to your district.' : 'Requests raised by district offices for new institutions.' ?></p>
  </div>
  <div class="flex items-center gap-2">
    <div class="flex rounded-xl border border-slate-200 bg-white p-1 text-xs font-semibold">
      <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $lbl): ?>
        <a href="<?= e(url('institution-requests', ['status' => $k])) ?>" class="px-3 py-1.5 rounded-lg <?= $status === $k ? 'bg-sky-600 text-white' : 'text-slate-600 hover:bg-slate-50' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
    <?php if ($isDistrict && !isset($_GET['new'])): ?><a href="<?= e(url('institution-requests', ['new' => 1])) ?>" class="btn-primary"><?= icon('plus') ?> New request</a><?php endif; ?>
  </div>
</div>

<?php if ($isDistrict && isset($_GET['new'])): ?>
<form method="post" class="card mb-6">
  <?= csrf_field() ?><input type="hidden" name="action" value="create">
  <h2 class="card-title mb-1">Request a new institution — <?= e(district_name((int) $user['district_id'])) ?></h2>
  <p class="card-subtitle mb-5">The State office reviews the request and adds the institution to the portal.</p>
  <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
    <div class="md:col-span-2"><label class="label">Institution Full Legal Name <span class="req">*</span></label><input class="input" name="name" required></div>
    <div><label class="label">AISHE / Affiliation Code</label><input class="input" name="code"></div>
    <div>
      <label class="label">Affiliated University</label>
      <select class="input" name="university_id"><option value="">Select University</option>
        <?php foreach (lookup('universities') as $u): ?><option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="label">Institution Category</label>
      <select class="input" name="category_id"><option value="">Select Category</option>
        <?php foreach (lookup('institution_categories') as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div><label class="label">Official Email</label><input class="input" type="email" name="email"></div>
    <div class="md:col-span-3"><label class="label">Address</label><input class="input" name="address"></div>
    <div class="md:col-span-3"><label class="label">Justification / Remarks</label><textarea class="input min-h-20" name="remarks" placeholder="Why should this institution be included in the programme?"></textarea></div>
  </div>
  <div class="mt-6 pt-5 border-t border-slate-100 flex justify-end gap-2">
    <a href="<?= e(url('institution-requests')) ?>" class="btn-secondary">Cancel</a>
    <button class="btn-primary">Submit request</button>
  </div>
</form>
<?php endif; ?>

<div class="space-y-4">
  <?php foreach ($requests as $r): ?>
    <div class="card">
      <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-4">
        <div class="min-w-0">
          <div class="flex items-center gap-2 flex-wrap">
            <h3 class="font-bold text-slate-900"><?= e($r['name']) ?></h3>
            <?= status_badge($r['status']) ?>
          </div>
          <p class="text-xs text-slate-500 mt-1">
            <?= e($r['district_name']) ?> · <?= e($r['university_short'] ?: 'University —') ?> · <?= e($r['category_name'] ?: 'Category —') ?><?= $r['code'] ? ' · ' . e($r['code']) : '' ?>
          </p>
          <?php if ($r['address'] || $r['email']): ?><p class="text-xs text-slate-500"><?= e(trim(($r['address'] ?? '') . ($r['email'] ? ' · ' . $r['email'] : ''), ' ·')) ?></p><?php endif; ?>
          <?php if ($r['remarks']): ?><p class="text-sm text-slate-700 mt-2 bg-slate-50 rounded-lg p-2.5 border border-slate-100"><?= e($r['remarks']) ?></p><?php endif; ?>
          <p class="text-[11px] text-slate-400 mt-2">Requested by <?= e($r['requested_by_name'] ?? '—') ?> · <?= e(date('d M Y, h:i A', strtotime($r['created_at']))) ?></p>
          <?php if ($r['status'] !== 'pending'): ?>
            <p class="text-[11px] text-slate-500 mt-1"><?= ucfirst($r['status']) ?> by <?= e($r['reviewed_by_name'] ?? '—') ?> · <?= e(date('d M Y', strtotime($r['reviewed_at']))) ?><?= $r['review_remarks'] ? ' — “' . e($r['review_remarks']) . '”' : '' ?></p>
            <?php if ($r['institution_id']): ?><a class="text-xs link" href="<?= e(url('institution-dashboard', ['id' => $r['institution_id']])) ?>">Open institution →</a><?php endif; ?>
          <?php endif; ?>
        </div>

        <?php if ($r['status'] === 'pending'): ?>
          <?php if ($canReview): ?>
            <form method="post" class="flex flex-col gap-2 lg:w-80 shrink-0">
              <?= csrf_field() ?><input type="hidden" name="request_id" value="<?= $r['id'] ?>">
              <input class="input input-sm" name="review_remarks" placeholder="Remarks (optional)">
              <div class="flex gap-2">
                <button name="action" value="approve" class="btn-primary btn-xs grow" data-confirm="Approve and add <?= e($r['name']) ?>?"><?= icon('check', 'w-3.5 h-3.5') ?> Approve &amp; add</button>
                <button name="action" value="reject" class="btn-secondary btn-xs grow text-rose-600" data-confirm="Reject this request?">Reject</button>
              </div>
            </form>
          <?php elseif ($isDistrict): ?>
            <form method="post" data-confirm="Withdraw this request?">
              <?= csrf_field() ?><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><input type="hidden" name="action" value="withdraw">
              <button class="btn-secondary btn-xs">Withdraw</button>
            </form>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$requests): ?>
    <div class="card text-center py-12 text-sm text-slate-400">No <?= $status !== 'all' ? e($status) : '' ?> requests.</div>
  <?php endif; ?>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
