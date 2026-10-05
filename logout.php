<?php
require __DIR__ . '/app/bootstrap.php';
if (is_post()) {
    verify_csrf();
    logout_user();
}
redirect('login');
