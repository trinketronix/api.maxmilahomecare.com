#!/usr/bin/env php
<?php
/**
 * Cron Job: Auto-Checkout Past Visits
 *
 * Finds all visits from today and earlier that are still in "Check-in" (progress=1)
 * and updates them to "Check-out" (progress=2).
 *
 * Usage:
 *   php cron_auto_checkout.php
 *
 * Cron example (run daily at midnight):
 *   0 0 * * * /usr/bin/php /path/to/cron_auto_checkout.php >> /var/log/cron_auto_checkout.log 2>&1
 */

// ─── Database Configuration ──────────────────────────────────────────
$dbHost = getenv('DB_HOSTPATH');
$dbName = getenv('DB_DATABASE');
$dbUser = getenv('DB_USERNAME');
$dbPass = getenv('DB_PASSWORD');
$dbPort = 3306;
// ─────────────────────────────────────────────────────────────────────

date_default_timezone_set('UTC');

$scriptName = basename(__FILE__);
$timestamp  = date('Y-m-d H:i:s');

echo "[$timestamp] $scriptName started\n";

try {
    $dsn = "mysql:host=$dbHost;port=$dbPort;dbname=$dbName;charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $today = date('Y-m-d');

    // Update visits: checkin (1) → checkout (2) where visit_date <= today
    $sql = "UPDATE `visit`
               SET `progress`    = 2,
                   `checkout_by` = `user_id`,
                   `end_time`    = DATE_ADD(`start_time`, INTERVAL `total_hours` HOUR),
                   `updated_at`  = NOW()
             WHERE `progress`    = 1
               AND `visit_date` <= :today";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':today' => $today]);

    $affected = $stmt->rowCount();

    echo "[$timestamp] Updated $affected visit(s) from check-in to check-out (visit_date <= $today)\n";

} catch (PDOException $e) {
    $error = $e->getMessage();
    echo "[$timestamp] ERROR: $error\n";
    exit(1);
}

echo "[$timestamp] $scriptName finished\n";
exit(0);
