<?php
/**
 * Review of public "Enroll my Institution" requests.
 * District users act on their own district; administrators on any district; State users view only.
 */
require __DIR__ . '/app/bootstrap.php';
require_once APP_ROOT . '/app/enrollment.php';
$user = require_role('admin', 'state', 'district');
$isDistrict = $user['role'] === 'district';
$canAct = has_role('admin', 'district');

$inScope = fn(array $req) => !$isDistrict || (int) $req['district_id'] === (int) $user['district_id'];

if (is_post()) {
    verify_csrf();
    if (!$canAct) {
        forbidden('State users can view enrollment requests but cannot approve or reject them.');
    }
    $id = (int) int_input('id', 0);
    $action = (string) input('action');
    $comment = nullable(mb_substr((string) input('comment'), 0, 1000));

    if ($action === 'approve' || $action === 'reject') {
        db()->beginTransaction();
        $req = load_enrollment($id, true);
        if (!$req || !$inScope($req)) {
            db()->rollBack();
            forbidden('This request is outside your district.');
        }
        if ($req['status'] !== 'pending') {
            db()->rollBack();
            flash('error', 'This request has already been ' . $req['status'] . '.');
            redirect('enrollments');
        }
        if ($action === 'reject') {
            if ($comment === null) {
                db()->rollBack();
                flash('error', 'Please enter a comment explaining why the request is rejected.');
                redirect('enrollments');
            }
            db()->prepare("UPDATE enrollment_requests SET status = 'rejected', review_comment = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
                ->execute([$comment, $user['id'], $id]);
            db()->commit();
            log_activity('reject', 'enrollment', $id, "Rejected enrollment request for {$req['institution_name']}", ['comment' => [null, $comment]], (int) $req['institution_id']);
            mail_enrollment_rejected($req, $comment);
            flash('success', "Request rejected. The Institution Head and the Placement Officer have been notified with your comment.");
            redirect('enrollments');
        }
        try {
            [$newUser, $token] = approve_enrollment($req, (int) $user['id'], $comment);
            db()->commit();
        } catch (RuntimeException $ex) {
            db()->rollBack();
            flash('error', $ex->getMessage());
            redirect('enrollments');
        }
        log_activity('approve', 'enrollment', $id, "Approved enrollment of {$req['institution_name']}; login {$newUser['username']} created for {$req['po_name']}",
            [], (int) $req['institution_id']);
        $sentPo = mail_enrollment_login($req, $newUser, $token);
        $sentHead = mail_enrollment_head($req);
        flash($sentPo ? 'success' : 'error', "Approved. Login \"{$newUser['username']}\" created for {$req['po_name']}. "
            . ($sentPo ? "Set-password email sent to {$req['po_email']}" : "The email to {$req['po_email']} could not be sent - check the mail settings, then use \"Resend login email\"")
            . ($sentHead ? "; confirmation sent to {$req['head_email']}." : "; the confirmation to {$req['head_email']} could not be sent."));
        redirect('enrollments', ['status' => 'approved']);
    }

    if ($action === 'resend') {
        $req = load_enrollment($id);
        if (!$req || !$inScope($req)) {
            forbidden('This request is outside your district.');
        }
        $st = db()->prepare('SELECT * FROM users WHERE id = ? AND is_active = 1');
        $st->execute([(int) $req['user_id']]);
        $po = $st->fetch();
        if ($req['status'] !== 'approved' || !$po) {
            flash('error', 'The login for this request no longer exists or is inactive.');
            redirect('enrollments', ['status' => 'approved']);
        }
        $token = create_password_token((int) $po['id'], 'setup', SETUP_LINK_HOURS);
        $ok = mail_enrollment_login(['po_email' => $po['email'] ?: $req['po_email'], 'po_name' => $po['name']] + $req, $po, $token);
        log_activity('update', 'enrollment', $id, "Resent the login email to {$po['name']}", [], (int) $req['institution_id']);
        flash($ok ? 'success' : 'error', $ok ? 'A new set-password link has been emailed to ' . ($po['email'] ?: $req['po_email']) . '. Earlier links no longer work.'
            : 'The email could not be sent - check the mail settings in .env (the administrator can see the error under Settings → Recent emails).');
        redirect('enrollments', ['status' => 'approved']);
    }
    forbidden();
}

$status = (string) input('status', 'pending');
$where = ['1=1'];
$params = [];
if ($isDistrict) { $where[] = 'er.district_id = ?'; $params[] = (int) $user['district_id']; }
if (in_array($status, ['pending', 'approved', 'rejected'], true)) { $where[] = 'er.status = ?'; $params[] = $status; }
$st = db()->prepare('SELECT er.*, i.name AS institution_name, d.name AS district_name, u.username AS login_username, u.is_active AS login_active,
                            ' . user_name_sql('rv') . ' AS reviewer_name
                     FROM enrollment_requests er
                     JOIN institutions i ON i.id = er.institution_id JOIN districts d ON d.id = er.district_id
                     LEFT JOIN users u ON u.id = er.user_id LEFT JOIN users rv ON rv.id = er.reviewed_by
                     WHERE ' . implode(' AND ', $where) . ' ORDER BY er.status = \'pending\' DESC, er.created_at DESC LIMIT 300');
$st->execute($params);
$requests = $st->fetchAll();

$st = db()->prepare("SELECT status, COUNT(*) n FROM enrollment_requests er WHERE " . ($isDistrict ? 'er.district_id = ?' : '1=1') . ' GROUP BY status');
$st->execute($isDistrict ? [(int) $user['district_id']] : []);
$counts = array_column($st->fetchAll(), 'n', 'status');

$pageTitle = 'Enrollment Requests';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-6">
  <div>
    <h1 class="text-xl font-bold text-slate-900">Institution Enrollment Requests</h1>
    <p class="text-sm text-slate-500">Submitted through "Enroll my Institution" on the sign-in page<?= $isDistrict ? ' for ' . e(district_name((int) $user['district_id'])) : '' ?>. Approving creates a login for the Placement Officer.</p>
  </div>
  <div class="flex rounded-xl border border-slate-200 bg-white p-1 text-xs font-semibold self-start">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $lbl): ?>
      <a href="<?= e(url('enrollments', ['status' => $k])) ?>" class="px-3 py-1.5 rounded-lg <?= $status === $k ? 'bg-sky-600 text-white' : 'text-slate-600 hover:bg-slate-50' ?>">
        <?= $lbl ?><?php if ($k !== 'all' && !empty($counts[$k])): ?> <span class="opacity-75">(<?= (int) $counts[$k] ?>)</span><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="space-y-4">
<?php foreach ($requests as $r):
    $ref = enrollment_ref((int) $r['id']); ?>
  <div class="card <?= $r['status'] === 'pending' ? 'border-amber-200' : '' ?>" id="r<?= (int) $r['id'] ?>">
    <div class="flex flex-col lg:flex-row lg:items-start justify-between gap-3 mb-4">
      <div>
        <div class="flex items-center gap-2 flex-wrap">
          <h2 class="font-bold text-slate-900"><?= e($r['institution_name']) ?></h2>
          <?= status_badge($r['status']) ?>
          <span class="text-[11px] font-mono text-slate-400"><?= e($ref) ?></span>
        </div>
        <p class="text-xs text-slate-500 mt-0.5"><?= e($r['district_name']) ?> · submitted <?= e(date('d M Y, h:i A', strtotime($r['created_at']))) ?><?= has_role('admin') && $r['ip'] ? ' · IP ' . e($r['ip']) : '' ?></p>
      </div>
      <?php if ($r['status'] === 'approved' && $r['login_username']): ?>
        <div class="text-xs text-slate-600 bg-emerald-50 border border-emerald-200 rounded-xl px-3 py-2">
          Login <span class="font-mono font-semibold"><?= e($r['login_username']) ?></span><?= $r['login_active'] ? '' : ' <span class="text-rose-600">(inactive)</span>' ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
      <div class="rounded-xl border border-slate-200 p-4">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Head of the Institution</p>
        <p class="font-semibold text-slate-900"><?= e($r['head_name']) ?> <span class="font-normal text-slate-500">· <?= e($r['head_designation']) ?></span></p>
        <p class="text-slate-600 break-all"><?= e($r['head_email']) ?> · <?= e($r['head_phone']) ?></p>
      </div>
      <div class="rounded-xl border border-sky-200 bg-sky-50/40 p-4">
        <p class="text-[10px] font-bold uppercase tracking-wider text-sky-700 mb-1">Placement Officer (portal login)</p>
        <p class="font-semibold text-slate-900"><?= e($r['po_name']) ?></p>
        <p class="text-slate-600 break-all"><?= e($r['po_email']) ?> · <?= e($r['po_phone']) ?></p>
      </div>
    </div>

    <?php if ($r['status'] !== 'pending'): ?>
      <p class="text-xs text-slate-500 mt-3"><?= ucfirst(e($r['status'])) ?> by <?= e($r['reviewer_name'] ?? '—') ?><?= $r['reviewed_at'] ? ' on ' . e(date('d M Y, h:i A', strtotime($r['reviewed_at']))) : '' ?></p>
      <?php if ($r['review_comment']): ?><p class="text-sm text-slate-700 mt-1 bg-slate-50 border border-slate-100 rounded-lg p-2.5"><?= nl2br(e($r['review_comment'])) ?></p><?php endif; ?>
      <?php if ($canAct && $inScope($r) && $r['status'] === 'approved' && $r['login_username'] && $r['login_active']): ?>
        <form method="post" class="mt-3" data-confirm="Email a new set-password link to <?= e($r['po_email']) ?>? Earlier links will stop working.">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="action" value="resend">
          <button class="btn-secondary btn-xs"><?= icon('key', 'w-3.5 h-3.5') ?> Resend login email</button>
        </form>
      <?php endif; ?>
    <?php elseif ($canAct && $inScope($r)): ?>
      <form method="post" class="mt-4 pt-4 border-t border-slate-100">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <label class="label" for="c<?= (int) $r['id'] ?>">Comment <span class="normal-case font-normal text-slate-400">(required to reject; optional when approving)</span></label>
        <textarea class="input min-h-16" id="c<?= (int) $r['id'] ?>" name="comment" maxlength="1000" placeholder="e.g. Verified with the principal by phone"></textarea>
        <div class="flex flex-wrap justify-end gap-2 mt-3">
          <button name="action" value="reject" class="btn-secondary btn-xs text-rose-600 border-rose-200 hover:bg-rose-50" data-confirm="Reject this enrollment request? Both contacts will be emailed your comment."><?= icon('x', 'w-3.5 h-3.5') ?> Reject</button>
          <button name="action" value="approve" class="btn-primary btn-xs" data-confirm="Approve and create a login for <?= e($r['po_name']) ?> (<?= e($r['po_email']) ?>)?"><?= icon('check', 'w-3.5 h-3.5') ?> Approve &amp; create login</button>
        </div>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if (!$requests): ?>
  <div class="card text-center py-12 text-sm text-slate-400">No <?= $status !== 'all' ? e($status) : '' ?> enrollment requests.</div>
<?php endif; ?>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
