<?php
declare(strict_types=1);

function expect_savegame_controller_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofdashboard-savegame-controller-' . bin2hex(random_bytes(4));
$savegame1Dir = $baseDir . DIRECTORY_SEPARATOR . 'savegame1';
$savegame2Dir = $baseDir . DIRECTORY_SEPARATOR . 'savegame2';
$invalidDir = $baseDir . DIRECTORY_SEPARATOR . 'savegameX';
mkdir($savegame1Dir, 0777, true);
mkdir($savegame2Dir, 0777, true);
mkdir($invalidDir, 0777, true);

define('FS_BASE_DIR', $baseDir);

function get_farm_info(string $savegameDir): array
{
    $folder = basename($savegameDir);

    return [
        'farmName' => $folder === 'savegame2' ? 'New Farm' : 'Old Farm',
        'manager' => $folder === 'savegame2' ? 'New Manager' : 'Old Manager',
    ];
}

require __DIR__ . '/../app/ApiResponseService.php';
require __DIR__ . '/../app/SavegameService.php';
require __DIR__ . '/../app/SavegameController.php';

file_put_contents($savegame1Dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml', '<careerSavegame><settings><savegameName>Old Save</savegameName><mapTitle>Old Map</mapTitle><saveDateFormatted>10.08.2026 12:00</saveDateFormatted><saveDate>2026-08-10T12:00:00</saveDate></settings></careerSavegame>');
file_put_contents($savegame2Dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml', '<careerSavegame><settings><savegameName>New Save</savegameName><mapTitle>New Map</mapTitle><saveDateFormatted>11.08.2026 12:00</saveDateFormatted><saveDate>2026-08-11T12:00:00</saveDate></settings></careerSavegame>');
file_put_contents($savegame2Dir . DIRECTORY_SEPARATOR . 'AutoDrive_config.xml', '<AutoDrive/>');
file_put_contents($invalidDir . DIRECTORY_SEPARATOR . 'careerSavegame.xml', '<careerSavegame/>');

http_response_code(200);
ob_start();
handle_savegames_list();
$listResponse = json_decode(ob_get_clean(), true);
expect_savegame_controller_test(http_response_code() === 200, 'Expected list status 200.');
expect_savegame_controller_test($listResponse['baseDir'] === $baseDir, 'Expected list base dir.');
expect_savegame_controller_test(count($listResponse['savegames']) === 2, 'Expected valid savegames only.');
expect_savegame_controller_test($listResponse['savegames'][0]['folder'] === 'savegame2', 'Expected newest savegame first.');
expect_savegame_controller_test($listResponse['savegames'][0]['farmName'] === 'New Farm', 'Expected farm info in list.');
expect_savegame_controller_test($listResponse['savegames'][0]['hasAutoDrive'] === true, 'Expected AutoDrive flag.');

$_SESSION = [];
http_response_code(200);
ob_start();
handle_savegame_select(json_encode(['folder' => 'savegame2']));
$selectResponse = json_decode(ob_get_clean(), true);
expect_savegame_controller_test(http_response_code() === 200, 'Expected select status 200.');
expect_savegame_controller_test($selectResponse === ['success' => true, 'folder' => 'savegame2', 'hasAutoDrive' => true], 'Expected select response payload.');
expect_savegame_controller_test($_SESSION['savegame_folder'] === 'savegame2', 'Expected selected savegame in session.');

http_response_code(200);
ob_start();
handle_current_savegame();
$currentResponse = json_decode(ob_get_clean(), true);
expect_savegame_controller_test($currentResponse === ['folder' => 'savegame2', 'hasAutoDrive' => true], 'Expected current savegame response.');

http_response_code(200);
ob_start();
handle_savegame_clear();
$clearResponse = json_decode(ob_get_clean(), true);
expect_savegame_controller_test($clearResponse === ['success' => true], 'Expected clear response payload.');
expect_savegame_controller_test(!isset($_SESSION['savegame_folder']), 'Expected selected savegame to be cleared.');

http_response_code(200);
ob_start();
handle_savegame_select(json_encode(['folder' => 'savegameX']));
$invalidSelectResponse = json_decode(ob_get_clean(), true);
expect_savegame_controller_test(http_response_code() === 404, 'Expected invalid select status 404.');
expect_savegame_controller_test($invalidSelectResponse['error'] === 'Spielstand nicht gefunden.', 'Expected invalid select error.');

unlink($invalidDir . DIRECTORY_SEPARATOR . 'careerSavegame.xml');
unlink($savegame2Dir . DIRECTORY_SEPARATOR . 'AutoDrive_config.xml');
unlink($savegame2Dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml');
unlink($savegame1Dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml');
rmdir($invalidDir);
rmdir($savegame2Dir);
rmdir($savegame1Dir);
rmdir($baseDir);

echo "savegame_controller_test: ok\n";
