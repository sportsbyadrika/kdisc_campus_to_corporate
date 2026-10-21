<?php
/** Online exam: participant sign-in with the emailed exam password. */
require __DIR__ . '/app/bootstrap.php';

if (current_participant()) {
    redirect('exam-portal');
}

if (is_post()) {
    verify_csrf();
    $email = strtolower(trim((string) input('email')));
    $password = strtoupper(preg_replace('/\s+/', '', (string) ($_POST['password'] ?? '')));
    // 300 attempts per IP (exam halls share one IP) and 10 failures per email in 15 minutes
    if (!throttle_allow('exam-login-ip:' . client_ip(), 300, 900) || throttle_hits('exam-login:' . $email, 900) >= 10) {
        too_many_requests('Too many sign-in attempts. Please wait 15 minutes and try again.');
    }
    $st = db()->prepare('SELECT p.*, x.status AS exam_status FROM exam_participants p JOIN exams x ON x.id = p.exam_id
                         WHERE p.email = ? AND p.password_hash IS NOT NULL ORDER BY p.id DESC');
    $st->execute([$email]);
    $match = null;
    foreach ($st->fetchAll() as $row) {
        if (password_verify($password, $row['password_hash'])) {
            $match = $row;
            break;
        }
    }
    $error = match (true) {
        !$match => 'The email or exam password is incorrect.',
        strtotime((string) $match['password_expires_at']) < time() => 'This exam password has expired. Please contact the exam coordinator.',
        $match['exam_status'] === 'draft' => 'This exam is not open yet.',
        default => null,
    };
    if ($error) {
        throttle_hit('exam-login:' . $email);
        flash('error', $error);
        $_SESSION['_old_email'] = $email;
        redirect('exam-login');
    }
    exam_login_participant($match);
    redirect('exam-portal');
}

$oldEmail = $_SESSION['_old_email'] ?? '';
unset($_SESSION['_old_email']);
$pageTitle = 'Online exam sign in';
$headerRight = '';
require APP_ROOT . '/app/layout/public_header.php';
?>
<div class="max-w-md mx-auto">
  <div class="card">
    <div class="text-center mb-6">
      <div class="mx-auto w-12 h-12 rounded-xl bg-sky-100 text-sky-700 flex items-center justify-center mb-3"><?= icon('academic', 'w-6 h-6') ?></div>
      <h1 class="text-xl font-bold text-slate-900">Online exam</h1>
      <p class="text-sm text-slate-500 mt-1">Sign in with your registered email and the exam password sent to you.</p>
    </div>
    <form method="post" class="space-y-4" autocomplete="off">
      <?= csrf_field() ?>
      <div><label class="label" for="email">Registered email</label><input class="input" type="email" id="email" name="email" value="<?= e($oldEmail) ?>" required autofocus></div>
      <div><label class="label" for="password">Exam password</label><input class="input font-mono tracking-widest uppercase" type="password" id="password" name="password" required autocomplete="off"></div>
      <button class="btn-primary w-full justify-center">Sign in to exam</button>
    </form>
  </div>
  <p class="text-xs text-slate-500 text-center mt-4">Lost your connection during the exam? Sign in again to continue before your time runs out.</p>
</div>
<?php require APP_ROOT . '/app/layout/public_footer.php';
