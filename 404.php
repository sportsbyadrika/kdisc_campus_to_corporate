<?php
if (!defined('APP_ROOT')) {
    require __DIR__ . '/app/bootstrap.php';
}
http_response_code(http_response_code() === 403 ? 403 : 404);
$pageTitle = 'Page not found';
require APP_ROOT . '/app/layout/header.php';
?>
<div class="card max-w-xl mx-auto text-center py-12">
  <p class="text-5xl font-black text-sky-600">404</p>
  <h1 class="text-lg font-bold text-slate-900 mt-2">Page not found</h1>
  <p class="text-sm text-slate-500 mt-1">The page you are looking for does not exist or has moved.</p>
  <a href="<?= e(url(current_user() ? 'dashboard' : 'login')) ?>" class="btn-primary mt-6">Go home</a>
</div>
<?php require APP_ROOT . '/app/layout/footer.php';
