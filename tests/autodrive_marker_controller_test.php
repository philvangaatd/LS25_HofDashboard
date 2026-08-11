<?php
declare(strict_types=1);

function expect_autodrive_marker_controller_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofdashboard-marker-controller-' . bin2hex(random_bytes(4));
$backupDir = $baseDir . DIRECTORY_SEPARATOR . 'backups';
$savegameDir = $baseDir . DIRECTORY_SEPARATOR . 'savegame1';
mkdir($backupDir, 0777, true);
mkdir($savegameDir, 0777, true);

define('FS_BASE_DIR', $baseDir);
define('BACKUP_DIR', $backupDir);

function get_farm_info(string $savegameDir): array
{
    return ['farmName' => 'Test Farm', 'manager' => 'Tester'];
}

require __DIR__ . '/../app/ApiResponseService.php';
require __DIR__ . '/../app/SavegameService.php';
require __DIR__ . '/../app/BackupService.php';
require __DIR__ . '/../app/AutoDriveService.php';
require __DIR__ . '/../app/AutoDriveMarkerController.php';

$configPath = $savegameDir . DIRECTORY_SEPARATOR . 'AutoDrive_config.xml';
file_put_contents($configPath, '<AutoDrive><MapName>Test Map</MapName><waypoints><id>1,2,42</id></waypoints><mapmarker><mm1><id>1.000000</id><name>Farm</name><group>Home</group></mm1></mapmarker></AutoDrive>');

$_SESSION = ['savegame_folder' => 'savegame1'];

http_response_code(200);
ob_start();
handle_autodrive_markers_get();
$getResponse = json_decode(ob_get_clean(), true);
expect_autodrive_marker_controller_test(http_response_code() === 200, 'Expected marker GET status 200.');
expect_autodrive_marker_controller_test($getResponse['mapName'] === 'Test Map', 'Expected marker GET map name.');
expect_autodrive_marker_controller_test($getResponse['farmName'] === 'Test Farm', 'Expected marker GET farm name.');
expect_autodrive_marker_controller_test($getResponse['markers'][0]['name'] === 'Farm', 'Expected marker GET marker data.');

http_response_code(200);
ob_start();
handle_autodrive_markers_save(json_encode([
    'markers' => [
        ['id' => '42.000000', 'name' => 'Shop', 'group' => 'Sales'],
        ['id' => '2.000000', 'name' => 'Field', 'group' => ''],
    ],
]));
$saveResponse = json_decode(ob_get_clean(), true);
expect_autodrive_marker_controller_test(http_response_code() === 200, 'Expected marker save status 200.');
expect_autodrive_marker_controller_test($saveResponse['success'] === true, 'Expected marker save success.');
expect_autodrive_marker_controller_test($saveResponse['count'] === 2, 'Expected marker save count.');
expect_autodrive_marker_controller_test(isset($saveResponse['backup']), 'Expected marker save backup name.');
expect_autodrive_marker_controller_test(count(glob($backupDir . DIRECTORY_SEPARATOR . 'savegame1_AutoDrive_config_*.xml')) === 1, 'Expected marker save backup file.');

$dom = load_dom($configPath);
$updatedMarkerData = read_autodrive_markers($dom);
expect_autodrive_marker_controller_test($updatedMarkerData['markers'][0]['id'] === '42.000000', 'Expected marker save to update XML.');
expect_autodrive_marker_controller_test($updatedMarkerData['markers'][0]['group'] === 'Sales', 'Expected marker save to update group.');

http_response_code(200);
ob_start();
handle_autodrive_markers_save(json_encode(['markers' => [['id' => '999.000000', 'name' => 'Missing', 'group' => '']]]));
$invalidResponse = json_decode(ob_get_clean(), true);
expect_autodrive_marker_controller_test(http_response_code() === 422, 'Expected invalid waypoint status 422.');
expect_autodrive_marker_controller_test($invalidResponse['error'] === 'Wegpunkt-ID 999.000000 existiert nicht im Spielstand.', 'Expected invalid waypoint error.');

foreach (glob($backupDir . DIRECTORY_SEPARATOR . '*') as $path) {
    unlink($path);
}
unlink($configPath);
rmdir($savegameDir);
rmdir($backupDir);
rmdir($baseDir);

echo "autodrive_marker_controller_test: ok\n";
