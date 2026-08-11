<?php
declare(strict_types=1);

function expect_savegame_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofdashboard-savegame-test-' . bin2hex(random_bytes(4));
$savegameDir = $baseDir . DIRECTORY_SEPARATOR . 'savegame1';
$invalidDir = $baseDir . DIRECTORY_SEPARATOR . 'savegameX';
mkdir($savegameDir, 0777, true);
mkdir($invalidDir, 0777, true);
file_put_contents($savegameDir . DIRECTORY_SEPARATOR . 'careerSavegame.xml', '<careerSavegame/>');
file_put_contents($savegameDir . DIRECTORY_SEPARATOR . 'AutoDrive_config.xml', '<AutoDrive/>');

define('FS_BASE_DIR', $baseDir);

require __DIR__ . '/../app/SavegameService.php';

expect_savegame_test(is_valid_savegame_folder('savegame1'), 'Expected savegame1 to be valid.');
expect_savegame_test(is_valid_savegame_folder('savegame42'), 'Expected savegame42 to be valid.');
expect_savegame_test(!is_valid_savegame_folder('../savegame1'), 'Expected traversal folder to be invalid.');
expect_savegame_test(!is_valid_savegame_folder('savegameX'), 'Expected non-numeric savegame folder to be invalid.');

expect_savegame_test(get_general_savegame_dir('savegame1') === $savegameDir, 'Expected valid savegame directory.');
expect_savegame_test(get_general_savegame_dir('savegameX') === null, 'Expected invalid savegame directory rejection.');

$configPath = $savegameDir . DIRECTORY_SEPARATOR . 'AutoDrive_config.xml';
expect_savegame_test(get_config_path_for_folder('savegame1') === $configPath, 'Expected AutoDrive config path.');
expect_savegame_test(get_config_path_for_folder('../savegame1') === null, 'Expected invalid AutoDrive path rejection.');

$_SESSION['savegame_folder'] = 'savegame1';
expect_savegame_test(get_selected_config_path() === $configPath, 'Expected selected config path from session.');

unset($_SESSION['savegame_folder']);
expect_savegame_test(get_selected_config_path() === null, 'Expected no selected config path without session.');

unlink($savegameDir . DIRECTORY_SEPARATOR . 'AutoDrive_config.xml');
unlink($savegameDir . DIRECTORY_SEPARATOR . 'careerSavegame.xml');
rmdir($savegameDir);
rmdir($invalidDir);
rmdir($baseDir);

echo "savegame_service_test: ok\n";
