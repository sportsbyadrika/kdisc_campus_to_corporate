<?php
require __DIR__ . '/app/bootstrap.php';
$user = require_login();

if (is_post()) {
    verify_csrf();
    $action = input('action');
    if ($action === 'profile') {
        $name = (string) input('name');
        $email = nullable(input('email'));
        $phone = nullable(input('phone'));
        if ($name === '') {
            flash('error', 'Name is required.');
        } elseif ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid email address.');
        } else {
            db()->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?')->execute([$name, $email, $phone, $user['id']]);
            log_activity('update', 'user', (int) $user['id'], 'Updated own profile');
            flash('success', 'Profile updated.');
        }
    } elseif ($action === 'password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        if (!password_verify($current, $user['password_hash'])) {
            flash('error', 'Your current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'New password and confirmation do not match.');
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            log_activity('update', 'user', (int) $user['id'], 'Changed own password');
            session_regenerate_id(true);
            flash('success', 'Password changed successfully.');
        }
    }
    redirect('account');
}

$pageTitle = 'My Account';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="mb-6">
  <h1 class="text-xl font-bold text-slate-900">My Account</h1>
  <p class="text-sm text-slate-500"><?= e(ROLES[$user['role']]) ?> · signed in as <span class="font-mono"><?= e($user['username']) ?></span></p>
</div>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
  <form method="post" class="card space-y-5">
    <?= csrf_field() ?><input type="hidden" name="action" value="profile">
    <h2 class="card-title">Profile</h2>
    <div><label class="label">Full name <span class="req">*</span></label><input class="input" name="name" value="<?= e($user['name']) ?>" required></div>
    <div><label class="label">Email</label><input class="input" type="email" name="email" value="<?= e($user['email']) ?>"></div>
    <div><label class="label">Mobile</label><input class="input" name="phone" value="<?= e($user['phone']) ?>"></div>
    <div class="pt-4 border-t border-slate-100 flex justify-end"><button class="btn-primary">Save profile</button></div>
  </form>
  <form method="post" class="card space-y-5">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <h2 class="card-title">Change password</h2>
    <div><label class="label">Current password <span class="req">*</span></label><input class="input" type="password" name="current_password" required autocomplete="current-password"></div>
    <div><label class="label">New password <span class="req">*</span></label><input class="input" type="password" name="new_password" minlength="8" required autocomplete="new-password"><span class="hint">Minimum 8 characters.</span></div>
    <div><label class="label">Confirm new password <span class="req">*</span></label><input class="input" type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></div>
    <div class="pt-4 border-t border-slate-100 flex justify-end"><button class="btn-primary">Update password</button></div>
  </form>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
