#!/usr/bin/env php
<?php
/**
 * Cron Job: Auto-Checkout Past Visits
 *
 * Every visit whose visit_date is BEFORE today (America/Detroit) and that is still
 * checked in (progress = 1) is moved to checked out (progress = 2), with
 * end_time = start_time + total_hours + extra_minutes and checkout_by = user_id.
 *
 * Only earlier days are touched so that a visit still legitimately in progress today is
 * never closed, whatever time of day the job runs.
 *
 * Configuration (in this order):
 *   1. environment variables DB_HOSTPATH, DB_DATABASE, DB_USERNAME, DB_PASSWORD (DB_PORT optional)
 *   2. a key=value file: the path given as the first argument, or cronjobs/.env next to this script
 *   Apache's SetEnv values are NOT visible to cron, so on the shared host use the file.
 *
 * Usage:
 *   php auto_checkout.php [/path/to/env-file]
 *
 * Cron example (daily at 00:10 Detroit time; the script sets its own timezone):
 *   10 0 * * * /usr/bin/php /home/<account>/cronjobs/auto_checkout.php /home/<account>/cronjobs/.env >> /home/<account>/logs/auto_checkout.log 2>&1
 *
 * Keep this directory OUTSIDE the web root: it is excluded from the FTP deploy on purpose.
 */

declare(strict_types=1);

date_default_timezone_set('America/Detroit');

$scriptName = basename(__FILE__);
$log = static function (string $message): void {
    echo '[' . date('Y-m-d H:i:s T') . "] $message\n";
};

// ─── Configuration ───────────────────────────────────────────────────
$envFile = $argv[1] ?? (__DIR__ . '/.env');
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\"'");
        if (getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

$config = [
    'host'     => getenv('DB_HOSTPATH') ?: '',
    'database' => getenv('DB_DATABASE') ?: '',
    'username' => getenv('DB_USERNAME') ?: '',
    'password' => getenv('DB_PASSWORD') ?: '',
    'port'     => (int)(getenv('DB_PORT') ?: 3306),
];

$missing = array_keys(array_filter(
    ['DB_HOSTPATH' => $config['host'], 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username']],
    static fn ($v) => $v === ''
));
if ($missing) {
    $log("$scriptName ERROR: missing configuration " . implode(', ', $missing) . " (set env vars or provide $envFile)");
    exit(2);
}
// ─────────────────────────────────────────────────────────────────────

$log("$scriptName started");

try {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']);
    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Same session timezone as the API (configuration/services.php), so NOW() is Detroit time
    $offset = (new DateTimeImmutable('now', new DateTimeZone('America/Detroit')))->format('P');
    $pdo->exec("SET time_zone = '$offset'");

    $today = date('Y-m-d');

    $sql = "UPDATE `visit`
               SET `progress`    = 2,
                   `checkout_by` = `user_id`,
                   `end_time`    = CASE
                                       WHEN `start_time` IS NULL THEN NULL
                                       ELSE DATE_ADD(
                                                DATE_ADD(`start_time`, INTERVAL `total_hours` HOUR),
                                                INTERVAL `extra_minutes` MINUTE
                                            )
                                   END,
                   `updated_at`  = NOW()
             WHERE `progress`    = 1
               AND `status`      = 1
               AND `visit_date`  < :today";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':today' => $today]);

    $log(sprintf('Checked out %d visit(s) still in progress before %s', $stmt->rowCount(), $today));

} catch (PDOException $e) {
    $log("ERROR: " . $e->getMessage());
    exit(1);
}

$log("$scriptName finished");
exit(0);
