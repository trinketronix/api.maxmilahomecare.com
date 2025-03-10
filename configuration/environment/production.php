<?php
return [
    'database' => [
        'host'     => getenv('DB_HOSTPATH') ?: '127.0.0.1',
        'username' => getenv('DB_USERNAME') ?: 'root',
        'password' => getenv('DB_PASSWORD') ?: '',
        'dbname'   => getenv('DB_DATABASE') ?: '',
        'charset'  => 'utf8mb4'
    ],
    'email' => [
        'host'     => getenv('EMAIL_HOST') ?: 'mail.service.com',
        'smtpauth' => getenv('EMAIL_AUTH') === 'true',
        'username' => getenv('EMAIL_USER') ?: 'mail@maxmilahomecare.com',
        'password' => getenv('EMAIL_PASS') ?: 'secure_email_password',
        'port'     => (int) getenv('EMAIL_SERVPORT') ?: 465
    ],
    'debug' => true
];