</main>
<footer class="py-6 text-center text-xs text-slate-400"><?= e(setting('footer_right')) ?></footer>
<?php foreach ($publicScripts ?? [] as $src): ?><script src="<?= e(asset($src)) ?>"></script><?php endforeach; ?>
</body>
</html>
