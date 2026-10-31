<?php
/** Public FAQs page (FAQs menu). Questions and answers come from home_faqs() in app/home.php. */
require __DIR__ . '/app/bootstrap.php';

$pageTitle = 'FAQs';
$navActive = 'faqs';
require APP_ROOT . '/app/layout/site_header.php';
?>
<section class="bg-c2c-wash min-h-[calc(100vh-76px-136px)]">
  <div class="max-w-[1440px] mx-auto px-4 sm:px-8 py-12 grid grid-cols-1 lg:grid-cols-[300px_1fr] gap-8">
    <div>
      <h1 class="font-display text-3xl font-bold leading-tight text-c2c-ink">Frequently Asked<br><span class="text-c2c-teal">Questions</span></h1>
      <p class="text-[15px] leading-relaxed text-c2c-ink/90 mt-3">For higher education institutions joining the programme.</p>
      <p class="text-sm text-c2c-ink/80 mt-6">Still have a question? Write to <a class="font-semibold text-c2c-teal hover:underline" href="mailto:<?= e(home_text('contact_email')) ?>"><?= e(home_text('contact_email')) ?></a>.</p>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 items-start">
      <?php foreach (home_faqs() as [$q, $a]): ?>
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
