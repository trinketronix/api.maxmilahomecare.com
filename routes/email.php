<?php
use Api\Controllers\EmailController;

if (isset($app)) {
    $email = new EmailController();
    /**
     * Http POST method request: to send emails
     */
    $app->post('/send/email', [$email, 'send']);
}