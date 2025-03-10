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
        'host'     => getenv('EMAIL_HOSTPATH') ?: 'mail.service.com',
        'smtpauth' => getenv('EMAIL_SMTPAUTH') === 'true',
        'username' => getenv('EMAIL_USERNAME') ?: 'mail@maxmilahomecare.com',
        'password' => getenv('EMAIL_PASSWORD') ?: 'secure_email_password',
        'port'     => (int) getenv('EMAIL_SERVPORT') ?: 465
    ],
    'debug' => true
];