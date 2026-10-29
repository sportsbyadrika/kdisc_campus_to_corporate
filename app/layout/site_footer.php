<?php
/** Footer of the public website (home page design). */
$quick = ['Home' => url('') . '#top', 'About' => url('') . '#about', 'For Institutions' => url('enroll'), 'How It Works' => url('') . '#journey',
    'Resources' => url('') . '#updates', 'Updates' => url('updates'), 'FAQs' => url('') . '#faqs', 'Contact' => '#contact'];
$policies = array_filter(['Privacy Policy' => home_text('privacy_url'), 'Terms of Use' => home_text('terms_url'), 'Accessibility' => home_text('accessibility_url')]);
$socials = array_filter(['youtube' => home_text('social_youtube'), 'linkedin' => home_text('social_linkedin'), 'x' => home_text('social_x')]);
$email = home_text('contact_email');
?>
</main>
<footer class="bg-c2c-deep text-white" id="contact">
  <div class="max-w-[1440px] mx-auto px-4 sm:px-8 py-8 grid grid-cols-1 lg:grid-flow-col lg:auto-cols-auto lg:grid-cols-[auto_1fr] gap-8 lg:gap-12 items-start">
    <div class="flex flex-wrap items-center gap-6">
      <?= home_logo('logo_gok', true) ?><?= home_logo('logo_kdisc', true) ?><?= home_logo('logo_kkem', true) ?>
    </div>
    <div class="lg:border-l lg:border-white/15 lg:pl-10">
      <p class="text-sm font-semibold mb-3">Quick Links</p>
      <ul class="flex flex-wrap gap-y-2 text-xs text-white/85">
        <?php foreach ($quick as $label => $href): ?><li class="pr-3 mr-3 border-r border-white/25 last:border-0"><a href="<?= e($href) ?>" class="hover:text-c2c-aqua"><?= e($label) ?></a></li><?php endforeach; ?>
      </ul>
    </div>
    <?php if ($policies || $socials): ?>
    <div class="lg:border-l lg:border-white/15 lg:pl-10">
      <?php if ($policies): ?>
        <ul class="flex flex-wrap gap-y-2 text-xs text-white/85 mb-4">
          <?php foreach ($policies as $label => $href): ?><li class="pr-3 mr-3 border-r border-white/25 last:border-0"><a href="<?= e($href) ?>" class="hover:text-c2c-aqua" rel="noopener"><?= e($label) ?></a></li><?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <?php if ($socials): ?>
        <div class="flex gap-2.5">
          <?php foreach ($socials as $net => $href): ?>
            <a href="<?= e($href) ?>" target="_blank" rel="noopener" aria-label="<?= e(['youtube' => 'YouTube', 'linkedin' => 'LinkedIn', 'x' => 'X'][$net]) ?>"
               class="w-8 h-8 rounded-full flex items-center justify-center <?= ['youtube' => 'bg-[#e62117]', 'linkedin' => 'bg-[#0a66c2]', 'x' => 'bg-white/15'][$net] ?> hover:opacity-90">
              <?php if ($net === 'youtube'): ?><svg viewBox="0 0 24 24" class="w-4 h-4" aria-hidden="true"><path d="M9 7.5v9l7.5-4.5z" fill="#fff"/></svg>
              <?php elseif ($net === 'linkedin'): ?><span class="text-[13px] font-bold">in</span>
              <?php else: ?><svg viewBox="0 0 24 24" class="w-3.5 h-3.5" aria-hidden="true"><path d="M4 4l16 16M20 4L4 20" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/></svg><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="lg:border-l lg:border-white/15 lg:pl-10 space-y-2.5 text-xs text-white/90">
      <?php if ($email !== ''): ?><a href="mailto:<?= e($email) ?>" class="flex items-center gap-2.5 hover:text-c2c-aqua"><?= icon('mail', 'w-4 h-4') ?> <?= e($email) ?></a><?php endif; ?>
      <?php if (home_text('contact_location') !== ''): ?><p class="flex items-center gap-2.5"><?= icon('pin', 'w-4 h-4') ?> <?= e(home_text('contact_location')) ?></p><?php endif; ?>
    </div>
  </div>
</footer>
</body>
</html>
