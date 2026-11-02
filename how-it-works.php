<?php
/** Public How It Works page: the programme's stages. Managed under Settings → How It Works. */
require __DIR__ . '/app/bootstrap.php';
$stages = home_stages();

$pageTitle = 'How It Works';
$navActive = 'how';
require APP_ROOT . '/app/layout/site_header.php';
?>
<section class="bg-[#fafbfc] min-h-[calc(100vh-76px-136px)]">
  <div class="max-w-[1000px] mx-auto px-4 sm:px-8 py-10 sm:py-12">
    <?php if (home_text('hiw_eyebrow') !== ''): ?><p class="font-display text-lg sm:text-xl font-semibold uppercase tracking-wide text-slate-900"><?= e(home_text('hiw_eyebrow')) ?></p><?php endif; ?>
    <h1 class="font-display text-2xl sm:text-[1.85rem] font-semibold leading-tight text-[#1f74b8] mt-3"><?= e(home_text('hiw_title')) ?></h1>
    <p class="text-[15px] sm:text-base leading-relaxed text-slate-800 mt-3"><?= e(home_text('hiw_intro')) ?></p>

    <div class="mt-6 bg-white rounded-2xl border border-slate-200 px-4 sm:px-3.5">
      <?php foreach ($stages as $s): $img = upload_url($s['image']); ?>
        <article class="flex gap-4 sm:gap-5 py-4 border-b border-slate-200 last:border-0">
          <?php if ($img): ?>
            <img src="<?= e($img) ?>" alt="" class="w-20 h-20 sm:w-28 sm:h-28 rounded-xl object-cover shrink-0" loading="lazy">
          <?php else: ?>
            <span class="w-20 h-20 sm:w-28 sm:h-28 rounded-xl bg-c2c-mint text-c2c-teal flex items-center justify-center shrink-0"><?= icon('academic', 'w-9 h-9') ?></span>
          <?php endif; ?>
          <div class="min-w-0 pt-1">
            <span class="inline-block rounded-full bg-[#dbeafe] text-[#1d63b0] px-2.5 py-0.5 text-xs font-bold uppercase tracking-wide"><?= e($s['stage_label']) ?></span>
            <h2 class="text-base sm:text-[17px] font-semibold text-slate-900 mt-1.5"><?= e($s['heading']) ?></h2>
            <p class="text-sm sm:text-[15px] leading-relaxed text-slate-500 mt-1 whitespace-pre-line"><?= e($s['content']) ?></p>
          </div>
        </article>
      <?php endforeach; ?>
      <?php if (!$stages): ?><p class="py-10 text-center text-slate-400">The stages will be published here.</p><?php endif; ?>
    </div>
  </div>
</section>
<?php require APP_ROOT . '/app/layout/site_footer.php';
