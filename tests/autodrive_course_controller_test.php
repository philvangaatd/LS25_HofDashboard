<?php
declare(strict_types=1);

function expect_autodrive_course_controller_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofdashboard-course-controller-' . bin2hex(random_bytes(4));
$savegameDir = $baseDir . DIRECTORY_SEPARATOR . 'savegame1';
mkdir($savegameDir, 0777, true);

define('FS_BASE_DIR', $baseDir);

require __DIR__ . '/../app/ApiResponseService.php';
require __DIR__ . '/../app/SavegameService.php';
require __DIR__ . '/../app/AutoDriveService.php';
require __DIR__ . '/../app/AutoDriveCourseController.php';

$configPath = $savegameDir . DIRECTORY_SEPARATOR . 'AutoDrive_config.xml';
file_put_contents($savegameDir . DIRECTORY_SEPARATOR . 'careerSavegame.xml', '<careerSavegame/>');
file_put_contents($configPath, '<AutoDrive><waypoints><id>1,2</id><x>10,20</x><y>0,0</y><z>100,200</z><out>2;</out><flags>0,1</flags></waypoints></AutoDrive>');

$_SESSION = [];
http_response_code(200);
ob_start();
handle_autodrive_course_data();
$missingSessionResponse = json_decode(ob_get_clean(), true);
expect_autodrive_course_controller_test(http_response_code() === 409, 'Expected missing savegame status 409.');
expect_autodrive_course_controller_test($missingSessionResponse['error'] === 'no_savegame_selected', 'Expected missing savegame error.');

$_SESSION = ['savegame_folder' => 'savegame1'];
http_response_code(200);
ob_start();
handle_autodrive_course_data();
$courseResponse = json_decode(ob_get_clean(), true);
expect_autodrive_course_controller_test(http_response_code() === 200, 'Expected course data status 200.');
expect_autodrive_course_controller_test($courseResponse['ids'] === ['1', '2'], 'Expected course IDs.');
expect_autodrive_course_controller_test($courseResponse['x'] === [10, 20], 'Expected course X coordinates.');
expect_autodrive_course_controller_test($courseResponse['edges'] === [[0, 1]], 'Expected course edges.');

unlink($configPath);
http_response_code(200);
ob_start();
handle_autodrive_course_data();
$missingAutoDriveResponse = json_decode(ob_get_clean(), true);
expect_autodrive_course_controller_test(http_response_code() === 409, 'Expected missing AutoDrive status 409.');
expect_autodrive_course_controller_test($missingAutoDriveResponse['error'] === 'no_autodrive', 'Expected missing AutoDrive error.');

unlink($savegameDir . DIRECTORY_SEPARATOR . 'careerSavegame.xml');
rmdir($savegameDir);
rmdir($baseDir);

echo "autodrive_course_controller_test: ok\n";
