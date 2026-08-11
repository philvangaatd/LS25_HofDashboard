<?php
declare(strict_types=1);

function expect_autodrive_backup_controller_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofdashboard-backup-controller-' . bin2hex(random_bytes(4));
$backupDir = $baseDir . DIRECTORY_SEPARATOR . 'backups';
$savegameDir = $baseDir . DIRECTORY_SEPARATOR . 'savegame1';
mkdir($backupDir, 0777, true);
mkdir($savegameDir, 0777, true);

define('FS_BASE_DIR', $baseDir);
define('BACKUP_DIR', $backupDir);

require __DIR__ . '/../app/ApiResponseService.php';
require __DIR__ . '/../app/SavegameService.php';
require __DIR__ . '/../app/BackupService.php';
require __DIR__ . '/../app/AutoDriveBackupController.php';

$configPath = $savegameDir . DIRECTORY_SEPARATOR . 'AutoDrive_config.xml';
file_put_contents($configPath, '<AutoDrive><current/></AutoDrive>');

$restoreFile = 'savegame1_AutoDrive_config_2026-08-11_120000_001.xml';
$deleteFile = 'savegame1_AutoDrive_config_2026-08-11_120001_001.xml';
file_put_contents($backupDir . DIRECTORY_SEPARATOR . $restoreFile, '<AutoDrive><restored/></AutoDrive>');
file_put_contents($backupDir . DIRECTORY_SEPARATOR . $deleteFile, '<AutoDrive><delete/></AutoDrive>');

$_SESSION = ['savegame_folder' => 'savegame1'];

http_response_code(200);
ob_start();
handle_autodrive_backups_list();
$listResponse = json_decode(ob_get_clean(), true);
expect_autodrive_backup_controller_test(http_response_code() === 200, 'Expected list status 200.');
expect_autodrive_backup_controller_test(count($listResponse['backups']) === 2, 'Expected two listed backups.');
expect_autodrive_backup_controller_test($listResponse['backups'][0]['file'] === $deleteFile, 'Expected newest backup first.');
expect_autodrive_backup_controller_test($listResponse['backups'][0]['formatted'] === '11.08.2026 12:00:01', 'Expected formatted backup timestamp.');

http_response_code(200);
ob_start();
handle_autodrive_backup_restore(json_encode(['file' => $restoreFile]));
$restoreResponse = json_decode(ob_get_clean(), true);
expect_autodrive_backup_controller_test(http_response_code() === 200, 'Expected restore status 200.');
expect_autodrive_backup_controller_test($restoreResponse === ['success' => true, 'restoredFrom' => $restoreFile], 'Expected restore response payload.');
expect_autodrive_backup_controller_test(file_get_contents($configPath) === '<AutoDrive><restored/></AutoDrive>', 'Expected config to be restored from backup.');
expect_autodrive_backup_controller_test(count(glob($backupDir . DIRECTORY_SEPARATOR . 'savegame1_AutoDrive_config_*.xml')) === 3, 'Expected safety backup to be created.');

http_response_code(200);
ob_start();
handle_autodrive_backup_delete(json_encode(['file' => $deleteFile]));
$deleteResponse = json_decode(ob_get_clean(), true);
expect_autodrive_backup_controller_test(http_response_code() === 200, 'Expected delete status 200.');
expect_autodrive_backup_controller_test($deleteResponse === ['success' => true], 'Expected delete response payload.');
expect_autodrive_backup_controller_test(!file_exists($backupDir . DIRECTORY_SEPARATOR . $deleteFile), 'Expected backup file to be deleted.');

http_response_code(200);
ob_start();
handle_autodrive_backup_delete(json_encode(['file' => '../bad.xml']));
$invalidResponse = json_decode(ob_get_clean(), true);
expect_autodrive_backup_controller_test(http_response_code() === 400, 'Expected invalid filename status 400.');
expect_autodrive_backup_controller_test($invalidResponse['error'] === 'Ungültiger Backup-Dateiname.', 'Expected invalid filename error.');

foreach (glob($backupDir . DIRECTORY_SEPARATOR . '*') as $path) {
    unlink($path);
}
unlink($configPath);
rmdir($savegameDir);
rmdir($backupDir);
rmdir($baseDir);

echo "autodrive_backup_controller_test: ok\n";
