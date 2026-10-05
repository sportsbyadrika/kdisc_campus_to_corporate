</main>

<footer class="bg-white border-t border-slate-200 mt-12 py-6 text-center text-xs text-slate-500 no-print">
  <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-2">
    <div><?= e(setting('footer_left')) ?></div>
    <div class="font-medium text-slate-400"><?= e(setting('footer_right')) ?></div>
  </div>
</footer>

<div id="toast" class="fixed bottom-6 right-6 bg-slate-900 text-white text-xs px-4 py-3 rounded-xl shadow-lg translate-y-20 opacity-0 transition-all duration-300 z-50 flex items-center gap-2 pointer-events-none">
  <?= icon('check', 'w-4 h-4 text-emerald-400') ?>
  <span id="toast-msg">Operation successful</span>
</div>

<script src="<?= e(asset('assets/js/app.js')) ?>"></script>
<?php foreach ($extraScripts ?? [] as $s) echo $s, "\n"; ?>
</body>
</html>
