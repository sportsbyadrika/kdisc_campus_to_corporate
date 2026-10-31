<?php
/**
 * Header of the public website (home page design). Expects $pageTitle; optional $navActive.
 * Strict CSP: no inline scripts (the mobile menu is a <details> element).
 */
send_public_csp();
header('Cache-Control: no-cache');
$appName = setting('app_name', 'Campus to Corporate');
$navActive ??= 'home';
$home = url('');
$siteNav = [
    'home'         => ['Home', $home . '#top'],
    'about'        => ['About', $home . '#about'],
    'how'          => ['How It Works', $home . '#journey'],
    'updates'      => ['Updates', url('updates')],
    'faqs'         => ['FAQs', url('faqs')],
    'contact'      => ['Contact', '#contact'],
];
$signedIn = current_user() !== null;
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(($pageTitle ?? '') !== '' ? $pageTitle . ' · ' . $appName : $appName . ' · ' . home_text('home_subtitle')) ?></title>
  <meta name="description" content="<?= e(home_text('home_intro')) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Caveat:wght@600;700&family=Inter:wght@400;500;600;700&family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
  <?= favicon_tag() ?>
</head>
<body class="bg-white text-slate-700 antialiased min-h-screen flex flex-col" id="top">
<header class="bg-white border-b border-slate-100 sticky top-0 z-40">
  <div class="max-w-[1440px] mx-auto px-4 sm:px-8 h-[76px] flex items-center justify-between gap-4">
    <a href="<?= e($home) ?>" class="flex items-center gap-5 shrink-0" aria-label="<?= e($appName) ?> home">
      <?= home_logo('logo_gok') ?>
      <span class="hidden sm:flex"><?= home_logo('logo_kdisc') ?></span>
      <span class="hidden md:flex"><?= home_logo('logo_kkem') ?></span>
    </a>
    <nav class="hidden xl:flex items-center gap-4 2xl:gap-6 text-[13px] 2xl:text-[13.5px] font-medium text-c2c-ink whitespace-nowrap" aria-label="Main">
      <?php foreach ($siteNav as $k => [$label, $href]): ?>
        <a href="<?= e($href) ?>" data-nav="<?= $k ?>" <?= $k === $navActive ? 'data-active aria-current="page"' : '' ?> class="site-nav-link relative py-2 hover:text-c2c-teal"><?= e($label) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="flex items-center gap-2">
      <a href="<?= e(url($signedIn ? 'dashboard' : 'login')) ?>" class="inline-flex items-center gap-2.5 rounded-lg bg-c2c-navy px-3 sm:px-5 py-2.5 sm:py-3 text-sm font-semibold text-white shadow-sm hover:bg-c2c-deep whitespace-nowrap">
        <?= icon('academic', 'w-5 h-5 hidden sm:block') ?> <span><?= $signedIn ? 'Go to Dashboard' : 'Institution Login' ?></span>
      </a>
      <details class="xl:hidden relative">
        <summary class="list-none p-2.5 rounded-lg text-c2c-navy hover:bg-slate-100 cursor-pointer" aria-label="Menu"><?= icon('menu', 'w-5 h-5') ?></summary>
        <nav class="absolute right-0 mt-2 w-56 bg-white rounded-xl border border-slate-200 shadow-lg py-2" aria-label="Main">
          <?php foreach ($siteNav as $k => [$label, $href]): ?>
            <a href="<?= e($href) ?>" data-nav="<?= $k ?>" <?= $k === $navActive ? 'data-active' : '' ?> class="site-nav-item block px-4 py-2 text-sm text-c2c-ink hover:bg-c2c-wash"><?= e($label) ?></a>
          <?php endforeach; ?>
        </nav>
      </details>
    </div>
  </div>
</header>
<?php foreach (take_flashes() as $flashMsg): $isErr = $flashMsg['type'] === 'error'; ?>
  <div class="max-w-[1440px] mx-auto w-full px-4 sm:px-8 mt-4"><div class="p-4 rounded-2xl border text-sm <?= $isErr ? 'bg-rose-50 border-rose-200 text-rose-900' : 'bg-emerald-50 border-emerald-200 text-emerald-900' ?>"><?= e($flashMsg['message']) ?></div></div>
<?php endforeach; ?>
<main class="grow">
