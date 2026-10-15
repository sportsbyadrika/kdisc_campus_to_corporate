<?php
/** CAPTCHA image for public forms: /captcha?form=enroll|forgot */
require __DIR__ . '/app/bootstrap.php';

$form = (string) input('form');
if (!in_array($form, ['enroll', 'forgot'], true)) {
    http_response_code(404);
    exit;
}
if (!throttle_allow('captcha:' . client_ip(), 60, 600)) {
    too_many_requests();
}
captcha_render($form);
