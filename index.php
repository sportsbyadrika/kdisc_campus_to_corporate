<?php
/**
 * Public home (landing) page, built to the client's design. Texts, photos and partner logos
 * are managed under Settings → Home page; "Latest Updates & Resources" under the same tab.
 */
require __DIR__ . '/app/bootstrap.php';

$hero = home_image('home_hero');
$light = home_text('home_hero_theme') !== 'dark';
$bannerLeft = home_image('home_banner_left');
$bannerRight = home_image('home_banner_right');
$updates = site_updates(3);

$why = [
    ['cap', 'Builds a skilled and employable youth workforce'],
    ['growth', 'Strengthens industry–institution linkages'],
    ['users', 'Creates meaningful employment opportunities'],
    ['kerala', "Supports Kerala's economic growth and innovation"],
    ['target', 'Focuses on verified employment outcomes'],
];
$journey = [
    ['user', 'Identify', 'Onboard eligible students through institutions', 'bg-[#2fa660]'],
    ['search', 'Assess', 'English, skill & mock interview assessments', 'bg-[#1d7fd8]'],
    ['document', 'Prepare', 'Resume, interview & confidence building', 'bg-[#6a3dc8]'],
    ['trending', 'Upskill', 'Targeted training based on needs', 'bg-[#0d8a95]'],
    ['handshake', 'Connect with Industry', 'Share talent with employers', 'bg-[#f28a1d]'],
    ['users', 'Recruit', 'Placement drives & selection', 'bg-[#e5375b]'],
    ['check-circle', 'Verify Employment', 'Track joining & confirm employment', 'bg-[#3aa84a]'],
];
$faqs = [
    ['Which institutions can enroll?', 'Higher education institutions in Kerala listed on the portal. Choose “Enroll Your Institution”, select the district, institution type and category, pick your institution and enter the details of the Head of the Institution and the Placement Officer.'],
    ['What happens after we submit the enrollment request?', 'The District office reviews the request. On approval, the Placement Officer receives an email to set a password and sign in; the Head of the Institution receives a confirmation email.'],
    ['Can an institution send more than one request?', 'No. Only one request per institution is accepted. A new request is possible only if the earlier one was rejected.'],
    ['What does the institution update on the portal?', 'The institution profile, placement officers, course-wise student strength, student cohorts and assessments, and the DWMS services availed. Progress is shown on the institution dashboard.'],
    ['We forgot our password. What should we do?', 'Use “Forgot password?” on the Institution Login page. A reset link is sent to the registered email address.'],
    ['Whom do we contact for help?', 'Write to ' . home_text('contact_email') . ', or contact your District office.'],
];
$kerala = fn(string $class) => '<svg viewBox="0 0 100 300" class="' . e($class) . '" aria-hidden="true"><path d="' . KERALA_PATH . '" fill="currentColor"/></svg>';

$pageTitle = '';
$navActive = 'home';
require APP_ROOT . '/app/layout/site_header.php';
?>
<!-- Hero: "light" = white background with dark labels (client design), "dark" = navy -->
<?php if ($light): ?>
<section class="relative overflow-hidden bg-white text-c2c-ink" data-spy="home">
  <?php if ($hero): ?>
    <img src="<?= e($hero) ?>" alt="" class="hidden lg:block absolute inset-y-0 right-0 h-full w-[62%] max-w-[1100px] object-cover object-[right_top]">
    <div class="hidden lg:block absolute inset-y-0 right-0 w-[62%] max-w-[1100px] bg-gradient-to-r from-white via-white/0 via-25% to-transparent"></div>
  <?php else: ?>
    <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_78%_40%,#d9f3f1_0%,#eef8fb_35%,#ffffff_70%)]"></div>
    <?= $kerala('absolute right-[8%] top-4 h-[90%] w-auto text-c2c-teal opacity-10 rotate-[-14deg] pointer-events-none') ?>
  <?php endif; ?>
  <div class="relative max-w-[1440px] mx-auto px-4 sm:px-8 pt-8 pb-8 lg:pt-9 lg:pb-7 lg:min-h-[440px] grid grid-cols-1 lg:grid-cols-[minmax(0,540px)_1fr] gap-6">
    <div>
      <?php if (home_text('home_eyebrow') !== ''): ?><p class="font-display text-[13px] sm:text-sm font-semibold uppercase tracking-wide text-c2c-ink"><?= e(home_text('home_eyebrow')) ?></p><?php endif; ?>
      <h1 class="font-display font-extrabold leading-[0.95] tracking-tight mt-2">
        <span class="block text-5xl sm:text-6xl xl:text-[4.4rem] text-[#0d3778]">Campus to</span>
        <span class="block text-5xl sm:text-6xl xl:text-[4.4rem] pb-1 bg-gradient-to-r from-[#0f9a8a] via-[#11a09a] to-[#1d7fb8] bg-clip-text text-transparent">Corporate</span>
      </h1>
      <p class="font-display text-xl sm:text-[1.45rem] font-semibold leading-tight mt-3 text-[#13428f]"><?= e(home_text('home_subtitle')) ?></p>
      <p class="text-[15px] leading-relaxed text-c2c-ink/85 mt-3 max-w-md"><?= e(home_text('home_intro')) ?></p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-5 max-w-[530px]">
        <div class="rounded-xl bg-gradient-to-r from-[#0f9a8a] to-[#17ab9f] text-white px-5 py-4 flex items-center gap-4 shadow-lg shadow-teal-900/15">
          <?= icon('users', 'w-11 h-11 shrink-0') ?><span class="self-stretch w-px bg-white/40"></span>
          <div><p class="font-display text-[2rem] font-bold leading-none"><?= e(home_text('home_stat1_value')) ?></p><p class="text-[13px] font-medium leading-snug mt-1"><?= e(home_text('home_stat1_label')) ?></p></div>
        </div>
        <div class="rounded-xl bg-[#e2f0fb] text-[#0d3778] px-5 py-4 flex items-center gap-4 shadow-lg shadow-sky-900/10">
          <?= icon('briefcase', 'w-11 h-11 shrink-0') ?><span class="self-stretch w-px bg-[#0d3778]/25"></span>
          <div><p class="font-display text-[2rem] font-bold leading-none"><?= e(home_text('home_stat2_value')) ?></p><p class="text-[13px] font-medium leading-snug mt-1"><?= e(home_text('home_stat2_label')) ?></p></div>
        </div>
      </div>
      <?php if (home_text('home_stat_note') !== ''): ?><p class="text-xs text-slate-500 mt-3"><?= e(home_text('home_stat_note')) ?></p><?php endif; ?>
    </div>

    <?php if ($hero): ?><img src="<?= e($hero) ?>" alt="" class="lg:hidden w-full h-60 sm:h-80 object-cover object-[65%_top] rounded-2xl"><?php endif; ?>
    <div class="relative flex flex-col justify-between items-center lg:items-end gap-6">
      <?php if (home_text('home_tagline') !== ''): ?><p class="hidden lg:block font-script text-4xl xl:text-5xl leading-[1.05] text-right -rotate-6 mt-2 mr-2 text-[#0d3778] [text-shadow:0_1px_0_rgba(255,255,255,0.7)]"><?= nl2br(e(str_replace(', ', ",\n", home_text('home_tagline')))) ?></p><?php else: ?><span></span><?php endif; ?>
      <div class="text-center">
        <a href="<?= e(url('enroll')) ?>" class="inline-flex items-center gap-3 rounded-full bg-gradient-to-r from-[#1550a8] to-[#0d3778] border-2 border-white px-7 py-3.5 text-[15px] font-semibold text-white shadow-xl shadow-[#0d3778]/30 hover:from-[#0d3778]">
          <?= icon('academic', 'w-6 h-6') ?> Enroll Your Institution <?= icon('arrow-right', 'w-4 h-4') ?>
        </a>
        <p class="text-xs mt-2 font-semibold text-[#0d3778] lg:[text-shadow:0_0_6px_#fff,0_0_2px_#fff]">Only for Higher Education Institutions</p>
      </div>
    </div>
  </div>
</section>
<?php else: ?>
<section class="relative overflow-hidden bg-c2c-navy text-white" data-spy="home">
  <?php if ($hero): ?>
    <img src="<?= e($hero) ?>" alt="" class="absolute inset-y-0 right-0 h-full w-full lg:w-[66%] object-cover object-[60%_20%]">
    <div class="absolute inset-0 bg-gradient-to-r from-c2c-navy from-30% lg:from-36% via-c2c-navy/80 via-50% to-transparent to-75% lg:to-68%"></div>
  <?php else: ?>
    <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_75%_30%,#1b8aa0_0%,#0f5d7f_35%,#0b3a5c_70%)]"></div>
    <div class="absolute inset-y-0 right-0 w-1/2 opacity-20 bg-[repeating-linear-gradient(135deg,transparent_0_22px,rgba(255,255,255,0.08)_22px_23px)]"></div>
  <?php endif; ?>
  <?php if (!$hero): ?><?= $kerala('absolute right-[6%] top-4 h-[88%] w-auto text-[#8fd8f5] opacity-25 rotate-[-14deg] pointer-events-none') ?><?php endif; ?>

  <div class="relative max-w-[1440px] mx-auto px-4 sm:px-8 pt-10 pb-8 lg:pt-12 lg:pb-6 min-h-[500px] grid grid-cols-1 lg:grid-cols-[minmax(0,560px)_1fr] gap-8">
    <div>
      <p class="font-display text-[13px] sm:text-sm font-semibold uppercase tracking-wide"><?= e(home_text('home_eyebrow')) ?></p>
      <h1 class="font-display font-extrabold leading-[0.95] tracking-tight mt-3">
        <span class="block text-5xl sm:text-6xl xl:text-[5.2rem]">Campus to</span>
        <span class="block text-5xl sm:text-6xl xl:text-[5.2rem] text-c2c-aqua">Corporate</span>
      </h1>
      <p class="font-display text-2xl sm:text-[1.65rem] font-semibold leading-tight mt-5 max-w-md"><?= e(home_text('home_subtitle')) ?></p>
      <p class="text-[15px] leading-relaxed text-white/90 mt-3 max-w-lg"><?= e(home_text('home_intro')) ?></p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-6 max-w-[520px]">
        <div class="rounded-lg bg-c2c-teal px-5 py-4 flex items-center gap-4 shadow-lg shadow-black/10">
          <?= icon('users', 'w-11 h-11 shrink-0') ?>
          <div><p class="font-display text-[2rem] font-bold leading-none"><?= e(home_text('home_stat1_value')) ?></p><p class="text-[13px] leading-snug mt-1"><?= e(home_text('home_stat1_label')) ?></p></div>
        </div>
        <div class="rounded-lg bg-white text-c2c-navy px-5 py-4 flex items-center gap-4 shadow-lg shadow-black/10">
          <?= icon('briefcase', 'w-11 h-11 shrink-0') ?>
          <div><p class="font-display text-[2rem] font-bold leading-none"><?= e(home_text('home_stat2_value')) ?></p><p class="text-[13px] leading-snug mt-1"><?= e(home_text('home_stat2_label')) ?></p></div>
        </div>
      </div>
      <?php if (home_text('home_stat_note') !== ''): ?><p class="text-xs text-white/85 mt-4"><?= e(home_text('home_stat_note')) ?></p><?php endif; ?>
    </div>

    <div class="relative flex flex-col justify-between items-end gap-10">
      <p class="font-script text-4xl sm:text-5xl leading-[1.05] text-right -rotate-6 mt-4 mr-2 <?= $hero ? 'text-c2c-navy drop-shadow-[0_1px_0_rgba(255,255,255,0.6)]' : 'text-white' ?>">
        <?= nl2br(e(str_replace(', ', ",\n", home_text('home_tagline')))) ?>
      </p>
      <div class="text-center">
        <a href="<?= e(url('enroll')) ?>" class="inline-flex items-center gap-3 rounded-full bg-c2c-navy border border-white/25 px-7 py-3.5 text-[15px] font-semibold shadow-xl shadow-black/25 hover:bg-c2c-deep">
          <?= icon('academic', 'w-6 h-6') ?> Enroll Your Institution <?= icon('arrow-right', 'w-4 h-4') ?>
        </a>
        <p class="text-xs mt-2.5 <?= $hero ? 'text-c2c-navy font-medium lg:text-white lg:[text-shadow:0_1px_2px_rgba(0,0,0,0.6)]' : 'text-white/85' ?>">Only for Higher Education Institutions</p>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- About + Why it matters -->
<section id="about" data-spy="about" class="bg-white scroll-mt-24">
  <div class="max-w-[1440px] mx-auto px-4 sm:px-8 py-10 grid grid-cols-1 lg:grid-cols-[minmax(0,5fr)_minmax(0,8fr)] gap-10">
    <div>
      <h2 class="font-display text-[1.7rem] sm:text-3xl font-bold leading-tight text-c2c-ink">A Stronger Bridge Between<br><span class="text-c2c-teal">Education and Employment</span></h2>
      <p class="text-[15px] leading-relaxed text-c2c-ink/90 mt-5"><?= e(home_text('home_about')) ?></p>
    </div>
    <div>
      <h3 class="font-display text-xl font-bold text-c2c-ink mb-5">Why It Matters?</h3>
      <div class="grid grid-cols-2 sm:grid-cols-5">
        <?php foreach ($why as $i => [$ico, $text]): ?>
          <div class="flex flex-col items-center text-center px-3 py-2 <?= $i ? 'sm:border-l sm:border-slate-200' : '' ?>">
            <span class="w-16 h-16 rounded-full bg-c2c-mint text-c2c-teal flex items-center justify-center">
              <?= $ico === 'kerala' ? $kerala('h-10 w-auto rotate-[-12deg]') : icon($ico, 'w-8 h-8') ?>
            </span>
            <p class="text-[13.5px] font-medium leading-snug text-c2c-ink mt-3"><?= e($text) ?></p>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<!-- The journey -->
<section id="journey" data-spy="how" class="bg-c2c-wash scroll-mt-24">
  <div class="max-w-[1440px] mx-auto px-4 sm:px-8 py-8 grid grid-cols-1 xl:grid-cols-[340px_1fr] gap-8 items-center">
    <div>
      <h2 class="font-display text-[1.45rem] font-bold leading-tight text-c2c-ink">From Campus to Corporate<br><span class="text-c2c-teal">The Journey</span></h2>
      <p class="text-[15px] leading-relaxed text-c2c-ink/90 mt-3">A structured pathway that takes students from identification to verified employment.</p>
    </div>
    <ol class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-y-6">
      <?php foreach ($journey as $i => [$ico, $title, $text, $bg]): ?>
        <li class="relative flex flex-col items-center text-center px-2">
          <?php if ($i): ?><span class="hidden lg:block absolute -left-3 top-7 -translate-y-1/2 text-c2c-teal/70" aria-hidden="true"><?= icon('arrow-right', 'w-6 h-6') ?></span><?php endif; ?>
          <span class="w-14 h-14 rounded-full <?= $bg ?> text-white flex items-center justify-center shadow-md shadow-black/10"><?= icon($ico, 'w-7 h-7') ?></span>
          <p class="font-display text-[15px] font-bold leading-tight text-c2c-ink mt-2.5"><?= e($title) ?></p>
          <p class="text-xs leading-snug text-c2c-teal mt-1 max-w-[9.5rem]"><?= e($text) ?></p>
        </li>
      <?php endforeach; ?>
    </ol>
  </div>
</section>

<!-- Banners + updates -->
<section id="updates" data-spy="resources" class="bg-white scroll-mt-24">
  <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,31fr)_minmax(0,40fr)_minmax(0,29fr)] items-stretch">
    <div class="relative min-h-[170px] lg:min-h-[180px] overflow-hidden <?= $bannerLeft ? '' : 'bg-gradient-to-br from-[#0f6e7a] via-[#127f6c] to-[#0b3a5c]' ?>">
      <?php if ($bannerLeft): // the banner image carries its own message ?>
        <img src="<?= e($bannerLeft) ?>" alt="" class="absolute inset-0 w-full h-full object-cover">
      <?php endif; ?>
    </div>

    <div class="px-4 sm:px-8 py-5 lg:border-x lg:border-slate-100">
      <div class="flex items-start gap-3">
        <span class="text-c2c-navy mt-0.5"><?= icon('megaphone', 'w-6 h-6') ?></span>
        <div class="grow min-w-0">
          <h2 class="font-display text-[15px] font-bold text-c2c-ink pb-2 border-b border-slate-200">Latest Updates &amp; Resources</h2>
          <ul class="divide-y divide-slate-100">
            <?php foreach ($updates as $u): $href = site_update_href($u); ?>
              <li class="flex items-center justify-between gap-4 py-2.5 text-xs">
                <span class="flex items-center gap-2 min-w-0 text-c2c-ink">
                  <span class="text-c2c-teal shrink-0"><?= icon($u['file'] ? 'document' : 'globe', 'w-3.5 h-3.5') ?></span>
                  <?php if ($href): ?><a href="<?= e($href) ?>" class="truncate hover:text-c2c-teal hover:underline" <?= $u['file'] ? 'target="_blank" rel="noopener"' : '' ?>><?= e($u['title']) ?></a>
                  <?php else: ?><span class="truncate"><?= e($u['title']) ?></span><?php endif; ?>
                </span>
                <span class="shrink-0 text-slate-500"><?= e(date('d M Y', strtotime($u['published_on']))) ?></span>
              </li>
            <?php endforeach; ?>
            <?php if (!$updates): ?><li class="py-4 text-xs text-slate-400">Updates will be posted here.</li><?php endif; ?>
          </ul>
          <a href="<?= e(url('updates')) ?>" class="inline-flex items-center gap-1.5 mt-2 text-[13px] font-semibold text-c2c-navy hover:text-c2c-teal">View All Updates <?= icon('arrow-right', 'w-4 h-4') ?></a>
        </div>
      </div>
    </div>

    <div class="relative min-h-[170px] overflow-hidden <?= $bannerRight ? '' : 'bg-gradient-to-r from-[#cfeee9] via-[#e9f7f5] to-white' ?>">
      <?php if ($bannerRight): ?>
        <img src="<?= e($bannerRight) ?>" alt="Kerala landmarks" class="absolute inset-0 w-full h-full object-cover">
      <?php else: ?>
      <span class="absolute left-6 bottom-0 text-c2c-teal/25"><?= icon('building', 'w-36 h-36') ?></span>
      <div class="relative h-full flex items-center justify-end p-6 pr-8">
        <p class="font-script text-c2c-navy text-[1.6rem] 2xl:text-3xl leading-[1.05] -rotate-[6deg] text-left">Together for a<br>Skilled and Prosperous<br>Kerala
          <svg viewBox="0 0 160 14" class="block w-36 ml-16 -mt-1" aria-hidden="true"><path d="M2 10C50 2 110 2 158 6" fill="none" stroke="#f2b705" stroke-width="3.5" stroke-linecap="round"/></svg>
        </p>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- FAQs -->
<section id="faqs" data-spy="faqs" class="bg-c2c-wash scroll-mt-24">
  <div class="max-w-[1440px] mx-auto px-4 sm:px-8 py-10 grid grid-cols-1 lg:grid-cols-[300px_1fr] gap-8">
    <div>
      <h2 class="font-display text-2xl font-bold leading-tight text-c2c-ink">Frequently Asked<br><span class="text-c2c-teal">Questions</span></h2>
      <p class="text-[15px] leading-relaxed text-c2c-ink/90 mt-3">For higher education institutions joining the programme.</p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
      <?php foreach ($faqs as [$q, $a]): ?>
        <details class="group bg-white rounded-xl border border-slate-200 px-5 py-4 open:shadow-sm">
          <summary class="list-none cursor-pointer flex items-start justify-between gap-3 font-semibold text-[14px] text-c2c-ink">
            <?= e($q) ?><span class="text-c2c-teal transition-transform group-open:rotate-90 shrink-0"><?= icon('chevron-right', 'w-4 h-4 mt-0.5') ?></span>
          </summary>
          <p class="text-[13.5px] leading-relaxed text-slate-600 mt-2"><?= e($a) ?></p>
        </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php require APP_ROOT . '/app/layout/site_footer.php';
