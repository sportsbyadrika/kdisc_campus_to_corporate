<?php
/** @var string|null $pageTitle */
$user = current_user();
$appName = setting('app_name', 'Campus to Corporate');
$page = current_page();

$nav = [];
if ($user) {
    $nav[] = ['dashboard', 'Dashboard', 'home', ['dashboard', 'institution-dashboard']];
    $nav[] = ['reports', 'Reports', 'chart', ['reports']];
    $nav[] = ['institutions', $user['role'] === 'institution' ? 'My Institutions' : 'Institutions', 'building',
        ['institutions', 'institution-profile', 'institution-officers', 'institution-students', 'institution-cohorts', 'institution-services']];
    if (has_role('admin', 'state', 'district')) {
        $nav[] = ['institution-requests', 'Requests', 'inbox', ['institution-requests']];
    }
    if (has_role('admin', 'district')) {
        $nav[] = ['vendor-upload', 'Test Upload', 'clipboard', ['vendor-upload']];
    }
    if (has_role('admin', 'district')) {
        $nav[] = ['users', 'Users', 'users', ['users', 'user-edit']];
    }
    if (has_role('admin', 'state')) {
        $nav[] = ['masters', 'Masters', 'database', ['masters', 'districts']];
    }
    if (has_role('admin', 'state', 'district')) {
        $nav[] = ['activity', 'Activity', 'clock', ['activity']];
    }
    if (has_role('admin')) {
        $nav[] = ['settings', 'Settings', 'cog', ['settings']];
    }
}
$roleLabel = $user ? ROLES[$user['role']] . ($user['role'] === 'district' ? ' · ' . district_name((int) $user['district_id']) : '') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(($pageTitle ?? '') !== '' ? $pageTitle . ' · ' . $appName : $appName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
  <link rel="icon" href="<?= e(asset('assets/img/favicon.svg')) ?>" type="image/svg+xml">
  <?php foreach ($extraHead ?? [] as $h) echo $h, "\n"; ?>
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
</head>
<body class="bg-slate-50 text-slate-800 antialiased min-h-screen flex flex-col">

<header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-xs no-print">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
    <a href="<?= e(url($user ? 'dashboard' : '')) ?>" class="flex items-center space-x-3 shrink-0">
      <div class="w-10 h-10 rounded-xl bg-linear-to-tr from-sky-600 to-indigo-600 flex items-center justify-center text-white font-bold text-lg shadow-sm">C2C</div>
      <div>
        <span class="text-lg font-bold tracking-tight text-slate-900 flex items-center gap-1.5">
          <?= e($appName) ?>
          <span class="hidden md:inline text-[10px] uppercase font-semibold bg-sky-100 text-sky-700 px-2 py-0.5 rounded-full"><?= e(academic_year()) ?></span>
        </span>
        <p class="text-xs text-slate-500 hidden sm:block"><?= e(setting('app_tagline')) ?></p>
      </div>
    </a>

    <?php if ($user): ?>
    <div class="flex items-center gap-2">
      <div class="relative" data-dropdown>
        <button type="button" data-dropdown-toggle class="flex items-center gap-2.5 pl-1.5 pr-3 py-1.5 rounded-xl hover:bg-slate-100 transition-colors">
          <span class="w-8 h-8 rounded-lg bg-sky-100 text-sky-700 font-bold text-xs flex items-center justify-center"><?= e(initials($user['name'])) ?></span>
          <span class="hidden sm:block text-left leading-tight">
            <span class="block text-sm font-semibold text-slate-800"><?= e($user['name']) ?></span>
            <span class="block text-[11px] text-slate-500"><?= e($roleLabel) ?></span>
          </span>
        </button>
        <div data-dropdown-menu class="hidden absolute right-0 mt-2 w-56 bg-white rounded-xl border border-slate-200 shadow-lg py-1.5 z-40">
          <div class="px-4 py-2 border-b border-slate-100 sm:hidden">
            <p class="text-sm font-semibold"><?= e($user['name']) ?></p>
            <p class="text-xs text-slate-500"><?= e($roleLabel) ?></p>
          </div>
          <a href="<?= e(url('account')) ?>" class="flex items-center gap-2 px-4 py-2 text-sm text-slate-700 hover:bg-slate-50"><?= icon('key') ?> My Account</a>
          <form method="post" action="<?= e(url('logout')) ?>">
            <?= csrf_field() ?>
            <button class="w-full flex items-center gap-2 px-4 py-2 text-sm text-rose-600 hover:bg-rose-50"><?= icon('logout') ?> Sign out</button>
          </form>
        </div>
      </div>
      <button type="button" class="lg:hidden p-2 rounded-lg text-slate-600 hover:bg-slate-100" data-nav-toggle aria-label="Menu"><?= icon('menu', 'w-5 h-5') ?></button>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($user): ?>
  <nav class="border-t border-slate-100 bg-white">
    <div id="main-nav" class="hidden lg:flex max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex-col lg:flex-row gap-1 py-2">
      <?php foreach ($nav as [$href, $label, $ico, $navPages]):
          $active = in_array($page, $navPages, true); ?>
        <a href="<?= e(url($href)) ?>"
           class="flex items-center gap-2 px-3 py-2 rounded-lg text-sm font-medium transition-colors <?= $active ? 'bg-sky-50 text-sky-700' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900' ?>">
          <?= icon($ico) ?> <?= e($label) ?>
        </a>
      <?php endforeach; ?>
    </div>
  </nav>
  <?php endif; ?>
</header>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full grow">
<?php foreach (take_flashes() as $flashMsg):
    $ok = $flashMsg['type'] === 'success'; ?>
  <div class="mb-6 p-4 rounded-2xl border flex items-start gap-3 text-sm <?= $ok ? 'bg-emerald-50 border-emerald-200 text-emerald-900' : ($flashMsg['type'] === 'error' ? 'bg-rose-50 border-rose-200 text-rose-900' : 'bg-sky-50 border-sky-200 text-sky-900') ?>" data-flash>
    <span class="mt-0.5"><?= icon($ok ? 'check-circle' : ($flashMsg['type'] === 'error' ? 'warning' : 'info'), 'w-5 h-5') ?></span>
    <span class="grow"><?= e($flashMsg['message']) ?></span>
    <button type="button" class="opacity-60 hover:opacity-100" onclick="this.parentElement.remove()" aria-label="Dismiss"><?= icon('x') ?></button>
  </div>
<?php endforeach; ?>
