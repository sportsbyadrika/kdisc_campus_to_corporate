<?php
/** Layout for public (signed-out) pages. Expects $pageTitle. Sends a strict CSP: no inline scripts. */
send_public_csp();
header('Cache-Control: no-store');
$appName = setting('app_name', 'Campus to Corporate');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex">
  <title><?= e($pageTitle) ?> · <?= e($appName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
  <link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="bg-slate-50 text-slate-800 antialiased min-h-screen flex flex-col">
<header class="bg-white border-b border-slate-200">
  <div class="max-w-5xl mx-auto px-4 sm:px-6 h-16 flex items-center justify-between gap-4">
    <a href="<?= e(url('login')) ?>" class="flex items-center gap-3">
      <div class="w-10 h-10 rounded-xl bg-linear-to-tr from-sky-600 to-indigo-600 flex items-center justify-center text-white font-bold text-lg shadow-sm">C2C</div>
      <div>
        <span class="text-lg font-bold tracking-tight text-slate-900"><?= e($appName) ?></span>
        <p class="text-xs text-slate-500 hidden sm:block"><?= e(setting('app_tagline')) ?></p>
      </div>
    </a>
    <a href="<?= e(url('login')) ?>" class="text-sm font-medium text-sky-700 hover:underline">Sign in</a>
  </div>
</header>
<main class="grow w-full max-w-5xl mx-auto px-4 sm:px-6 py-8">
<?php foreach (take_flashes() as $flashMsg):
    $isErr = $flashMsg['type'] === 'error'; ?>
  <div class="mb-6 p-4 rounded-2xl border flex items-start gap-3 text-sm <?= $isErr ? 'bg-rose-50 border-rose-200 text-rose-900' : 'bg-emerald-50 border-emerald-200 text-emerald-900' ?>">
    <span class="mt-0.5"><?= icon($isErr ? 'warning' : 'check-circle', 'w-5 h-5') ?></span><span><?= e($flashMsg['message']) ?></span>
  </div>
<?php endforeach; ?>
