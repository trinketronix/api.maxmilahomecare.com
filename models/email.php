<?php

use Api\Controllers\EmailController;

if (isset($app)) {
    $email = new EmailController();
    $app->post('/email/send', [$email, 'send']);
}