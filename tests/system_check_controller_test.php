<?php
declare(strict_types=1);

function expect_system_check_controller_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofdashboard-system-check-' . bin2hex(random_bytes(4));
$backupDir = $baseDir . DIRECTORY_SEPARATOR . 'backups';
$assetsDir = $baseDir . DIRECTORY_SEPARATOR . 'assets';
mkdir($backupDir, 0777, true);
mkdir($assetsDir, 0777, true);

define('HOF_DASHBOARD_VERSION', '5.0-test');
define('HOF_DASHBOARD_PROTOCOL_MIN', 1);
define('HOF_DASHBOARD_PROTOCOL_MAX', 2);
define('APP_DATA_DIR', $baseDir);
define('FS_BASE_DIR', $baseDir);
define('FS_INSTALL_DIR', '');
define('BACKUP_DIR', $backupDir);
define('MAP_ASSETS_DIR', $assetsDir);

require __DIR__ . '/../app/ApiResponseService.php';
require __DIR__ . '/../app/SystemCheckController.php';

http_response_code(200);
ob_start();
handle_system_check();
$response = json_decode(ob_get_clean(), true);

expect_system_check_controller_test(http_response_code() === 200, 'Expected system check status 200.');
expect_system_check_controller_test(is_array($response['checks']), 'Expected checks array.');

$byLabel = [];
foreach ($response['checks'] as $check) {
    $byLabel[$check['label']] = $check;
}

expect_system_check_controller_test($byLabel['Dashboard-Version']['status'] === 'ok', 'Expected dashboard version status.');
expect_system_check_controller_test(str_contains($byLabel['Dashboard-Version']['detail'], '5.0-test'), 'Expected dashboard version detail.');
expect_system_check_controller_test($byLabel['App-Datenordner']['status'] === 'ok', 'Expected app data dir status.');
expect_system_check_controller_test($byLabel['Spielstand-Ordner (FS_BASE_DIR)']['detail'] === $baseDir, 'Expected savegame base dir detail.');
expect_system_check_controller_test($byLabel['Backup-Ordner beschreibbar']['status'] === 'ok', 'Expected backup dir status.');
expect_system_check_controller_test($byLabel['Kartenbild-Ordner beschreibbar']['status'] === 'ok', 'Expected assets dir status.');
expect_system_check_controller_test(isset($byLabel['PHP-Erweiterung "gd" (Bildverarbeitung)']), 'Expected gd extension check.');
expect_system_check_controller_test(isset($byLabel['PHP-Erweiterung "zip" (Backups, Mod-Kartensuche)']), 'Expected zip extension check.');
expect_system_check_controller_test(isset($byLabel['Zeitzone']), 'Expected timezone check.');

rmdir($assetsDir);
rmdir($backupDir);
rmdir($baseDir);

echo "system_check_controller_test: ok\n";
