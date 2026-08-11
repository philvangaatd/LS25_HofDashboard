<?php
declare(strict_types=1);

function expect_terrain_controller_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofdashboard-terrain-controller-' . bin2hex(random_bytes(4));
$savegameDir = $baseDir . DIRECTORY_SEPARATOR . 'savegame1';
$assetsDir = $baseDir . DIRECTORY_SEPARATOR . 'assets';
$bundledDir = $baseDir . DIRECTORY_SEPARATOR . 'bundled';
$installDir = $baseDir . DIRECTORY_SEPARATOR . 'install';
$mapDir = $installDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . 'mapUS';
mkdir($savegameDir, 0777, true);
mkdir($assetsDir, 0777, true);
mkdir($bundledDir, 0777, true);
mkdir($mapDir, 0777, true);

define('FS_BASE_DIR', $baseDir);
define('FS_INSTALL_DIR', $installDir);
define('MAP_ASSETS_DIR', $assetsDir);
define('BUNDLED_ASSETS_DIR', $bundledDir);

require __DIR__ . '/../app/ApiResponseService.php';
require __DIR__ . '/../app/SavegameService.php';
require __DIR__ . '/../app/MapAssetService.php';
require __DIR__ . '/../app/TerrainController.php';

file_put_contents($savegameDir . DIRECTORY_SEPARATOR . 'careerSavegame.xml', '<careerSavegame><settings><mapId>mapUS</mapId></settings></careerSavegame>');
file_put_contents($mapDir . DIRECTORY_SEPARATOR . 'mapUS.xml', '<map width="2048" height="4096"></map>');
file_put_contents($mapDir . DIRECTORY_SEPARATOR . 'overview.dds', 'dds-bytes');
file_put_contents($assetsDir . DIRECTORY_SEPARATOR . 'terrain_savegame1.png', 'persistent-png');
file_put_contents($bundledDir . DIRECTORY_SEPARATOR . 'terrain_savegame1.png', 'bundled-png');

$_SESSION = ['savegame_folder' => 'savegame1'];

http_response_code(200);
ob_start();
handle_map_size_info();
$sizeResponse = json_decode(ob_get_clean(), true);
expect_terrain_controller_test(http_response_code() === 200, 'Expected map size status 200.');
expect_terrain_controller_test($sizeResponse['size'] === ['width' => 2048, 'height' => 4096], 'Expected map size payload.');

http_response_code(200);
ob_start();
handle_terrain_upload(['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '']);
$uploadMissingResponse = json_decode(ob_get_clean(), true);
expect_terrain_controller_test(in_array(http_response_code(), [400, 500], true), 'Expected upload without file to return client or environment error.');
expect_terrain_controller_test(isset($uploadMissingResponse['error']), 'Expected upload without file error payload.');

http_response_code(200);
ob_start();
handle_load_map_terrain();
$loadResponse = json_decode(ob_get_clean(), true);
expect_terrain_controller_test(in_array(http_response_code(), [404, 500], true), 'Expected load map terrain without PNG/JPEG to return not found or environment error.');
expect_terrain_controller_test(isset($loadResponse['error']), 'Expected load map terrain error payload.');

http_response_code(200);
ob_start();
handle_fetch_map_dds();
$ddsResponse = ob_get_clean();
expect_terrain_controller_test(http_response_code() === 200, 'Expected DDS fetch status 200.');
expect_terrain_controller_test($ddsResponse === 'dds-bytes', 'Expected DDS fetch bytes.');

http_response_code(200);
ob_start();
handle_terrain_image('savegame1');
$imageResponse = ob_get_clean();
expect_terrain_controller_test(http_response_code() === 200, 'Expected terrain image status 200.');
expect_terrain_controller_test($imageResponse === 'persistent-png', 'Expected persistent terrain image to be preferred.');

http_response_code(200);
ob_start();
handle_terrain_image('../bad');
$invalidImageResponse = json_decode(ob_get_clean(), true);
expect_terrain_controller_test(http_response_code() === 400, 'Expected invalid terrain folder status 400.');
expect_terrain_controller_test($invalidImageResponse['error'] === 'invalid_savegame_folder', 'Expected invalid terrain folder error.');

http_response_code(200);
ob_start();
handle_terrain_delete();
$deleteResponse = json_decode(ob_get_clean(), true);
expect_terrain_controller_test(http_response_code() === 200, 'Expected delete terrain status 200.');
expect_terrain_controller_test($deleteResponse === ['success' => true], 'Expected delete terrain response.');
expect_terrain_controller_test(!file_exists($assetsDir . DIRECTORY_SEPARATOR . 'terrain_savegame1.png'), 'Expected persistent terrain image to be deleted.');
expect_terrain_controller_test(file_exists($bundledDir . DIRECTORY_SEPARATOR . 'terrain_savegame1.png'), 'Expected bundled terrain image to remain.');

$_SESSION = [];
http_response_code(200);
ob_start();
handle_terrain_delete();
$missingSessionResponse = json_decode(ob_get_clean(), true);
expect_terrain_controller_test(http_response_code() === 409, 'Expected missing savegame delete status 409.');
expect_terrain_controller_test($missingSessionResponse['error'] === 'no_savegame_selected', 'Expected missing savegame delete error.');

unlink($bundledDir . DIRECTORY_SEPARATOR . 'terrain_savegame1.png');
unlink($mapDir . DIRECTORY_SEPARATOR . 'overview.dds');
unlink($mapDir . DIRECTORY_SEPARATOR . 'mapUS.xml');
unlink($savegameDir . DIRECTORY_SEPARATOR . 'careerSavegame.xml');
rmdir($mapDir);
rmdir($installDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'maps');
rmdir($installDir . DIRECTORY_SEPARATOR . 'data');
rmdir($installDir);
rmdir($bundledDir);
rmdir($assetsDir);
rmdir($savegameDir);
rmdir($baseDir);

echo "terrain_controller_test: ok\n";
