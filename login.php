<?php
require __DIR__ . '/app/bootstrap.php';

if (current_user()) {
    redirect('dashboard');
}

$error = null;
$username = '';
if (is_post()) {
    verify_csrf();
    $username = mb_substr((string) input('username'), 0, 60);
    $password = (string) ($_POST['password'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $st = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE (username = ? OR ip = ?) AND attempted_at > NOW() - INTERVAL 15 MINUTE');
    $st->execute([$username, $ip]);
    if ((int) $st->fetchColumn() >= 8) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } else {
        $st = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $st->execute([$username]);
        $user = $st->fetch();
        if ($user && $user['is_active'] && password_verify($password, $user['password_hash'])) {
            db()->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);
            }
            login_user($user);
            $to = $_SESSION['_intended'] ?? null;
            unset($_SESSION['_intended']);
            if ($to && str_starts_with($to, config('base_path') . '/') && !str_starts_with($to, '//')) {
                header('Location: ' . $to);
                exit;
            }
            redirect('dashboard');
        }
        db()->prepare('INSERT INTO login_attempts (username, ip) VALUES (?, ?)')->execute([$username, $ip]);
        $error = ($user && !$user['is_active']) ? 'This account has been deactivated. Contact your administrator.'
            : 'Invalid username or password.';
    }
}
$appName = setting('app_name', 'Campus to Corporate');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign in · <?= e($appName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
  <link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="bg-slate-50 text-slate-800 antialiased min-h-screen flex flex-col">
  <main class="grow flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-5xl grid lg:grid-cols-2 bg-white rounded-3xl border border-slate-200 shadow-xs overflow-hidden">
      <div class="relative bg-linear-to-br from-slate-900 via-sky-950 to-indigo-950 text-white p-8 sm:p-10 flex flex-col justify-between gap-10">
        <div class="flex items-center gap-3">
          <div class="w-11 h-11 rounded-xl bg-linear-to-tr from-sky-500 to-indigo-500 flex items-center justify-center font-bold text-lg shadow-sm">C2C</div>
          <div>
            <p class="text-lg font-bold tracking-tight"><?= e($appName) ?></p>
            <p class="text-xs text-slate-300"><?= e(setting('app_tagline')) ?></p>
          </div>
        </div>
        <div class="space-y-4">
          <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-wider bg-emerald-400/20 text-emerald-300 border border-emerald-400/30">Academic Year <?= e(academic_year()) ?></span>
          <h1 class="text-3xl font-black leading-tight">Track every campus,<br>every cohort, every district.</h1>
          <p class="text-sm text-slate-300 max-w-md">Unified onboarding, student strength, assessment gateway and DWMS service tracking for institutions across all 14 districts of Kerala.</p>
        </div>
        <div class="grid grid-cols-3 gap-3 text-center">
          <div class="bg-white/10 border border-white/15 rounded-xl p-3"><p class="text-xl font-extrabold text-amber-400">14</p><p class="text-[10px] uppercase tracking-wider text-sky-200">Districts</p></div>
          <div class="bg-white/10 border border-white/15 rounded-xl p-3"><p class="text-xl font-extrabold">6</p><p class="text-[10px] uppercase tracking-wider text-sky-200">Onboarding steps</p></div>
          <div class="bg-white/10 border border-white/15 rounded-xl p-3"><p class="text-xl font-extrabold text-emerald-300">DWMS</p><p class="text-[10px] uppercase tracking-wider text-sky-200">Integrated</p></div>
        </div>
      </div>

      <div class="p-8 sm:p-12 flex flex-col justify-center">
        <h2 class="text-xl font-bold text-slate-900">Sign in to your workspace</h2>
        <p class="text-sm text-slate-500 mb-6">Use the credentials issued by your district or state office.</p>

        <?php if ($error): ?>
          <div class="mb-5 p-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm flex items-center gap-2"><?= icon('warning') ?> <?= e($error) ?></div>
        <?php endif; ?>
        <?php foreach (take_flashes() as $f): ?>
          <div class="mb-5 p-3 rounded-xl bg-sky-50 border border-sky-200 text-sky-900 text-sm"><?= e($f['message']) ?></div>
        <?php endforeach; ?>

        <form method="post" class="space-y-5" autocomplete="on">
          <?= csrf_field() ?>
          <div>
            <label class="label" for="username">Username</label>
            <input class="input" id="username" name="username" value="<?= e($username) ?>" required autofocus autocomplete="username">
          </div>
          <div>
            <label class="label" for="password">Password</label>
            <input class="input" id="password" type="password" name="password" required autocomplete="current-password">
          </div>
          <button class="btn-primary w-full">Sign in <?= icon('arrow-right') ?></button>
        </form>
        <p class="text-xs text-slate-400 mt-8">Forgot your password? Contact your district coordinator or the portal administrator to reset it.</p>
      </div>
    </div>
  </main>
  <footer class="py-6 text-center text-xs text-slate-400"><?= e(setting('footer_right')) ?></footer>
</body>
</html>
