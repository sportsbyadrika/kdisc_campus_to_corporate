<?php
/** Online exam: participants and their emailed exam passwords. */
require __DIR__ . '/app/bootstrap.php';
$exam = load_exam();
$id = (int) $exam['id'];

/** Validate one participant; returns [row, errors]. */
function exam_participant_input(string $name, string $email, string $phone, string $details): array
{
    $d = [
        'name'    => mb_substr(trim($name), 0, 160),
        'email'   => strtolower(trim($email)),
        'phone'   => nullable(normalize_phone($phone)),
        'details' => nullable(mb_substr(trim($details), 0, 500)),
    ];
    $errors = [];
    if ($d['name'] === '') $errors[] = 'Name is required.';
    if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || strlen($d['email']) > 160) $errors[] = "“{$email}” is not a valid email.";
    if ($d['phone'] !== null && !valid_mobile($d['phone'])) $errors[] = "“{$phone}” is not a valid 10-digit mobile number.";
    return [$d, $errors];
}

$loadP = function (int $pid) use ($id): ?array {
    $st = db()->prepare('SELECT * FROM exam_participants WHERE id = ? AND exam_id = ?');
    $st->execute([$pid, $id]);
    return $st->fetch() ?: null;
};
$emailTaken = function (string $email, int $exceptId = 0) use ($id): bool {
    $st = db()->prepare('SELECT COUNT(*) FROM exam_participants WHERE exam_id = ? AND email = ? AND id <> ?');
    $st->execute([$id, $email, $exceptId]);
    return (int) $st->fetchColumn() > 0;
};

if (is_post()) {
    verify_csrf();
    $action = input('action');

    if ($action === 'invite') {
        $expires = datetime_input('expires_at');
        $pids = array_map('intval', (array) ($_POST['pids'] ?? []));
        if (input('scope') === 'uninvited') {
            $st = db()->prepare('SELECT id FROM exam_participants WHERE exam_id = ? AND invited_at IS NULL');
            $st->execute([$id]);
            $pids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        }
        if (!$expires || strtotime($expires) <= time()) {
            flash('error', 'Set a password expiry date and time in the future.');
        } elseif (!$pids) {
            flash('error', input('scope') === 'uninvited' ? 'Everyone has already been sent a password.' : 'Tick the participants to send the exam password to.');
        } else {
            set_time_limit(0);
            $sent = $failed = $skipped = 0;
            foreach ($pids as $pid) {
                $p = $loadP($pid);
                if (!$p || $p['finished_at']) { $skipped++; continue; }
                exam_invite($p, $exam, $expires) ? $sent++ : $failed++;
            }
            flash($failed ? 'error' : 'success', "Exam password emailed to {$sent} participant(s)"
                . ($failed ? ", {$failed} failed (see Settings → Recent emails)" : '') . ($skipped ? ", {$skipped} skipped (already completed)" : '') . '.');
        }
        redirect('exam-participants', ['id' => $id]);
    }

    if ($action === 'delete') {
        $p = $loadP((int) int_input('pid', 0));
        if ($p && !$p['started_at']) {
            db()->prepare('DELETE FROM exam_participants WHERE id = ?')->execute([$p['id']]);
            flash('success', "{$p['name']} removed.");
        } else {
            flash('error', 'A participant who has started the exam cannot be removed.');
        }
        redirect('exam-participants', ['id' => $id]);
    }

    if ($action === 'bulk') {
        $rows = array_filter(array_map('trim', preg_split('/\R/', (string) input('bulk'))));
        $errors = [];
        $new = [];
        $seen = [];
        foreach (array_values($rows) as $n => $line) {
            $cols = array_map('trim', str_getcsv(str_contains($line, "\t") ? str_replace("\t", ',', $line) : $line));
            if ($n === 0 && isset($cols[1]) && strtolower($cols[1]) === 'email') continue;   // header row
            [$d, $errs] = exam_participant_input($cols[0] ?? '', $cols[1] ?? '', $cols[2] ?? '', implode(', ', array_slice($cols, 3)));
            if (!$errs && (isset($seen[$d['email']]) || $emailTaken($d['email']))) $errs[] = "{$d['email']} is already a participant.";
            foreach ($errs as $err) $errors[] = 'Line ' . ($n + 1) . ": {$err}";
            $seen[$d['email']] = true;
            $new[] = $d;
        }
        if ($errors || !$new) {
            foreach (array_slice($errors, 0, 6) as $err) flash('error', $err);
            if (count($errors) > 6) flash('error', (count($errors) - 6) . ' more error(s).');
            if (!$new) flash('error', 'Paste at least one participant.');
            $_SESSION['_old'] = ['bulk' => (string) input('bulk')];
            redirect('exam-participants', ['id' => $id, 'bulk' => 1]);
        }
        $ins = db()->prepare('INSERT INTO exam_participants (exam_id, name, email, phone, details) VALUES (?,?,?,?,?)');
        db()->beginTransaction();
        foreach ($new as $d) $ins->execute([$id, $d['name'], $d['email'], $d['phone'], $d['details']]);
        db()->commit();
        flash('success', count($new) . ' participant(s) added. Send them their exam passwords when ready.');
        redirect('exam-participants', ['id' => $id]);
    }

    // add / edit one participant
    $pid = (int) int_input('pid', 0);
    $p = $pid ? $loadP($pid) : null;
    if ($pid && !$p) forbidden('Participant not found.');
    [$d, $errors] = exam_participant_input((string) input('name'), (string) input('email'), (string) input('phone'), (string) input('details'));
    if (!$errors && $emailTaken($d['email'], $pid)) $errors[] = 'This email is already a participant of this exam.';
    if ($p && $p['started_at'] && $d['email'] !== $p['email']) $errors[] = 'The email cannot be changed after the participant has started.';
    if ($errors) {
        foreach ($errors as $err) flash('error', $err);
        $_SESSION['_old'] = $_POST;
        redirect('exam-participants', ['id' => $id] + ($pid ? ['edit' => $pid] : ['new' => 1]));
    }
    if ($p) {
        db()->prepare('UPDATE exam_participants SET name = ?, email = ?, phone = ?, details = ? WHERE id = ?')->execute([...array_values($d), $pid]);
        flash('success', 'Participant updated.');
    } else {
        db()->prepare('INSERT INTO exam_participants (exam_id, name, email, phone, details) VALUES (?,?,?,?,?)')->execute([$id, ...array_values($d)]);
        flash('success', "{$d['name']} added.");
    }
    redirect('exam-participants', ['id' => $id]);
}

exam_finalize_overdue($id);
$st = db()->prepare('SELECT p.*, s.name AS series_name FROM exam_participants p LEFT JOIN exam_series s ON s.id = p.series_id WHERE p.exam_id = ? ORDER BY p.name');
$st->execute([$id]);
$participants = $st->fetchAll();
$editing = ($e = int_input('edit')) ? $loadP($e) : null;
$showForm = $editing || isset($_GET['new']);
$showBulk = isset($_GET['bulk']);
$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
$v = fn(string $k) => $old[$k] ?? $editing[$k] ?? '';
$counts = array_count_values(array_map(fn($p) => participant_state($p, $exam)['status'], $participants));

$pageTitle = $exam['title'] . ' · Participants';
require APP_ROOT . '/app/layout/header.php';
$examTab = 'exam-participants';
require APP_ROOT . '/app/layout/exam_nav.php';
?>
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4 mb-4">
  <div>
    <h2 class="text-lg font-bold text-slate-900">Participants</h2>
    <p class="text-sm text-slate-500"><?= num(count($participants)) ?> participants<?php foreach ($counts as $k => $n): ?> · <?= num($n) ?> <?= e(strtolower($k)) ?><?php endforeach; ?></p>
  </div>
  <?php if (!$showForm && !$showBulk): ?>
    <div class="flex gap-2">
      <a href="<?= e(url('exam-participants', ['id' => $id, 'bulk' => 1])) ?>" class="btn-secondary"><?= icon('clipboard') ?> Bulk add</a>
      <a href="<?= e(url('exam-participants', ['id' => $id, 'new' => 1])) ?>" class="btn-primary"><?= icon('plus') ?> Add participant</a>
    </div>
  <?php endif; ?>
</div>

<?php if ($showForm): ?>
<form method="post" class="card mb-6">
  <?= csrf_field() ?><input type="hidden" name="pid" value="<?= e($editing['id'] ?? '') ?>">
  <h3 class="card-title mb-5"><?= $editing ? 'Edit participant' : 'Add a participant' ?></h3>
  <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
    <div><label class="label">Name <span class="req">*</span></label><input class="input" name="name" value="<?= e($v('name')) ?>" maxlength="160" required></div>
    <div><label class="label">Email <span class="req">*</span></label><input class="input" type="email" name="email" value="<?= e($v('email')) ?>" maxlength="160" required <?= $editing && $editing['started_at'] ? 'readonly' : '' ?>></div>
    <div><label class="label">Mobile number</label><input class="input" name="phone" value="<?= e($v('phone')) ?>" maxlength="20"></div>
    <div class="md:col-span-3"><label class="label">Details</label><input class="input" name="details" value="<?= e($v('details')) ?>" maxlength="500" placeholder="e.g. institution, course, register number"></div>
  </div>
  <div class="mt-6 pt-5 border-t border-slate-100 flex justify-end gap-2">
    <a href="<?= e(url('exam-participants', ['id' => $id])) ?>" class="btn-secondary">Cancel</a>
    <button class="btn-primary"><?= $editing ? 'Save' : 'Add participant' ?></button>
  </div>
</form>
<?php endif; ?>

<?php if ($showBulk): ?>
<form method="post" class="card mb-6">
  <?= csrf_field() ?><input type="hidden" name="action" value="bulk">
  <h3 class="card-title mb-1">Bulk add participants</h3>
  <p class="text-xs text-slate-500 mb-4">One participant per line: <code class="font-mono bg-slate-100 px-1 rounded">Name, Email, Mobile, Details</code>. You can paste rows copied from Excel too. Mobile and details are optional.</p>
  <textarea class="input font-mono text-xs" name="bulk" rows="10" placeholder="Anjali Nair, anjali@example.com, 9876543210, MES College - MBA
Rahul Menon, rahul@example.com"><?= e($old['bulk'] ?? '') ?></textarea>
  <div class="mt-5 flex justify-end gap-2">
    <a href="<?= e(url('exam-participants', ['id' => $id])) ?>" class="btn-secondary">Cancel</a>
    <button class="btn-primary">Add participants</button>
  </div>
</form>
<?php endif; ?>

<form method="post" id="invite-form">
  <?= csrf_field() ?><input type="hidden" name="action" value="invite">
  <div class="card p-4 mb-4 flex flex-col lg:flex-row lg:items-end gap-3">
    <div class="grow">
      <p class="text-sm font-semibold text-slate-800">Send exam passwords by email</p>
      <p class="text-xs text-slate-500">Each participant gets a new password (any earlier one stops working), valid until the expiry you set. Sign-in page: <span class="font-mono"><?= e(absolute_url('exam-login')) ?></span></p>
    </div>
    <div><label class="label">Password valid till <span class="req">*</span></label><input class="input input-sm" type="datetime-local" name="expires_at" value="<?= e(datetime_local($exam['end_at'])) ?>" required></div>
    <button class="btn-secondary btn-xs" name="scope" value="selected" data-confirm="Email a new exam password to the ticked participants?"><?= icon('key', 'w-3.5 h-3.5') ?> Send to ticked</button>
    <button class="btn-primary btn-xs" name="scope" value="uninvited" data-confirm="Email exam passwords to everyone not invited yet?"><?= icon('key', 'w-3.5 h-3.5') ?> Send to all not invited</button>
  </div>

  <div class="card p-0 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="table">
        <thead><tr><th class="w-8"><input type="checkbox" class="h-4 w-4 rounded border-slate-300" data-check-all aria-label="Tick all"></th><th>Participant</th><th>Details</th><th>Status</th><th>Series</th><th class="text-right">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($participants as $p): $state = participant_state($p, $exam); ?>
          <tr>
            <td><?php if (!$p['finished_at']): ?><input type="checkbox" name="pids[]" value="<?= $p['id'] ?>" class="h-4 w-4 rounded border-slate-300" data-check-item><?php endif; ?></td>
            <td><p class="font-semibold text-slate-900"><?= e($p['name']) ?></p><p class="text-[11px] text-slate-400"><?= e($p['email']) ?><?= $p['phone'] ? ' · ' . e($p['phone']) : '' ?></p></td>
            <td class="text-xs text-slate-600 max-w-xs"><?= e($p['details'] ?? '') ?></td>
            <td><?= status_badge($state['status']) ?><span class="block text-[11px] text-slate-400 mt-0.5"><?= e($state['remark']) ?></span></td>
            <td class="text-xs"><?= e($p['series_name'] ?? '—') ?></td>
            <td class="text-right whitespace-nowrap">
              <a href="<?= e(url('exam-participants', ['id' => $id, 'edit' => $p['id']])) ?>" class="inline-flex p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 align-middle" title="Edit"><?= icon('pencil') ?></a>
              <?php if (!$p['started_at']): ?><button form="del-<?= $p['id'] ?>" class="p-1.5 rounded-lg text-rose-500 hover:bg-rose-50 align-middle" title="Remove"><?= icon('trash') ?></button><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$participants): ?><tr><td colspan="6" class="text-center text-slate-400 py-10">No participants yet.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>
<?php foreach ($participants as $p): if ($p['started_at']) continue; ?>
  <form method="post" id="del-<?= $p['id'] ?>" class="hidden" data-confirm="Remove <?= e($p['name']) ?> from this exam?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="pid" value="<?= $p['id'] ?>"></form>
<?php endforeach; ?>
<?php require APP_ROOT . '/app/layout/footer.php';
