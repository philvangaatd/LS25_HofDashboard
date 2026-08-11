<?php
declare(strict_types=1);

function expect_autodrive_write_fixture(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofdashboard-autodrive-write-fixture-' . bin2hex(random_bytes(4));
$backupDir = $baseDir . DIRECTORY_SEPARATOR . 'backups';
$savegameDir = $baseDir . DIRECTORY_SEPARATOR . 'savegame1';
mkdir($backupDir, 0777, true);
mkdir($savegameDir, 0777, true);

define('FS_BASE_DIR', $baseDir);
define('BACKUP_DIR', $backupDir);

require __DIR__ . '/../app/ApiResponseService.php';
require __DIR__ . '/../app/SavegameService.php';
require __DIR__ . '/../app/BackupService.php';
require __DIR__ . '/../app/AutoDriveService.php';
require __DIR__ . '/../app/AutoDriveMarkerController.php';
require __DIR__ . '/../app/AutoDriveBackupController.php';

$configPath = $savegameDir . DIRECTORY_SEPARATOR . 'AutoDrive_config.xml';
copy(__DIR__ . '/fixtures/autodrive_config.fixture.xml', $configPath);
$_SESSION = ['savegame_folder' => 'savegame1'];

http_response_code(200);
ob_start();
handle_autodrive_markers_save(json_encode([
    'markers' => [
        ['id' => '42.000000', 'name' => 'Shop', 'group' => 'Sales'],
        ['id' => '2.000000', 'name' => 'Field 2', 'group' => 'Fields'],
    ],
]));
$saveResponse = json_decode((string)ob_get_clean(), true);
expect_autodrive_write_fixture(http_response_code() === 200, 'Expected marker save status 200.');
expect_autodrive_write_fixture($saveResponse['success'] === true, 'Expected marker save success.');
expect_autodrive_write_fixture($saveResponse['count'] === 2, 'Expected marker save count.');
expect_autodrive_write_fixture(isset($saveResponse['backup']), 'Expected marker save backup name.');
$backupFilesAfterSave = glob($backupDir . DIRECTORY_SEPARATOR . 'savegame1_AutoDrive_config_*.xml');
expect_autodrive_write_fixture(count($backupFilesAfterSave) === 1, 'Expected save backup to be created.');
$saveBackup = basename($backupFilesAfterSave[0]);

$savedMarkerData = read_autodrive_markers(load_dom($configPath));
expect_autodrive_write_fixture($savedMarkerData['markers'][0]['id'] === '42.000000', 'Expected saved marker id.');
expect_autodrive_write_fixture($savedMarkerData['markers'][0]['name'] === 'Shop', 'Expected saved marker name.');
expect_autodrive_write_fixture($savedMarkerData['markers'][0]['group'] === 'Sales', 'Expected saved marker group.');

http_response_code(200);
ob_start();
handle_autodrive_markers_save(json_encode([
    'markers' => [
        ['id' => '999.000000', 'name' => 'Invalid', 'group' => ''],
    ],
]));
$invalidResponse = json_decode((string)ob_get_clean(), true);
expect_autodrive_write_fixture(http_response_code() === 422, 'Expected invalid waypoint status 422.');
expect_autodrive_write_fixture($invalidResponse['error'] === 'Wegpunkt-ID 999.000000 existiert nicht im Spielstand.', 'Expected invalid waypoint error.');
$afterInvalidMarkerData = read_autodrive_markers(load_dom($configPath));
expect_autodrive_write_fixture($afterInvalidMarkerData['markers'][0]['name'] === 'Shop', 'Expected invalid save to leave XML unchanged.');

http_response_code(200);
ob_start();
handle_autodrive_backup_restore(json_encode(['file' => $saveBackup]));
$restoreResponse = json_decode((string)ob_get_clean(), true);
expect_autodrive_write_fixture(http_response_code() === 200, 'Expected restore status 200.');
expect_autodrive_write_fixture($restoreResponse['success'] === true, 'Expected restore success.');
$restoredMarkerData = read_autodrive_markers(load_dom($configPath));
expect_autodrive_write_fixture($restoredMarkerData['markers'][0]['name'] === 'Farm', 'Expected restore to revert original fixture marker.');
expect_autodrive_write_fixture(count(glob($backupDir . DIRECTORY_SEPARATOR . 'savegame1_AutoDrive_config_*.xml')) === 2, 'Expected restore safety backup.');

foreach (glob($backupDir . DIRECTORY_SEPARATOR . '*') as $path) {
    unlink($path);
}
unlink($configPath);
rmdir($savegameDir);
rmdir($backupDir);
rmdir($baseDir);

echo "autodrive_write_fixture_test: ok\n";
