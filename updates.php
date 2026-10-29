<?php
/** Public list of all "Latest Updates & Resources" (managed under Settings → Home page). */
require __DIR__ . '/app/bootstrap.php';
$updates = site_updates();

$pageTitle = 'Updates';
$navActive = 'updates';
require APP_ROOT . '/app/layout/site_header.php';
?>
<section class="bg-c2c-wash">
  <div class="max-w-[1100px] mx-auto px-4 sm:px-8 py-10">
    <h1 class="font-display text-3xl font-bold text-c2c-ink">Latest Updates <span class="text-c2c-teal">&amp; Resources</span></h1>
    <p class="text-[15px] text-c2c-ink/80 mt-2">Guidelines, notices and documents for participating institutions and students.</p>
  </div>
</section>
<section class="max-w-[1100px] mx-auto px-4 sm:px-8 py-8">
  <ul class="divide-y divide-slate-200 border-y border-slate-200">
    <?php foreach ($updates as $u): $href = site_update_href($u); ?>
      <li class="flex flex-col sm:flex-row sm:items-center justify-between gap-1 sm:gap-6 py-4">
        <span class="flex items-start gap-3 text-[15px] text-c2c-ink">
          <span class="text-c2c-teal mt-0.5 shrink-0"><?= icon($u['file'] ? 'document' : 'globe', 'w-5 h-5') ?></span>
          <?php if ($href): ?><a href="<?= e($href) ?>" class="font-medium hover:text-c2c-teal hover:underline" <?= $u['file'] ? 'target="_blank" rel="noopener"' : '' ?>><?= e($u['title']) ?><?= $u['file'] ? ' <span class="text-xs text-slate-400 font-normal">(PDF)</span>' : '' ?></a>
          <?php else: ?><span class="font-medium"><?= e($u['title']) ?></span><?php endif; ?>
        </span>
        <span class="text-sm text-slate-500 sm:shrink-0 pl-8 sm:pl-0"><?= e(date('d M Y', strtotime($u['published_on']))) ?></span>
      </li>
    <?php endforeach; ?>
    <?php if (!$updates): ?><li class="py-10 text-center text-slate-400">No updates have been posted yet.</li><?php endif; ?>
  </ul>
  <a href="<?= e(url('')) ?>" class="inline-flex items-center gap-1.5 mt-6 text-sm font-semibold text-c2c-navy hover:text-c2c-teal">&larr; Back to home</a>
</section>
<?php require APP_ROOT . '/app/layout/site_footer.php';
