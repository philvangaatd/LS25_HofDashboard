<?php
declare(strict_types=1);

function expect_full_backup_controller_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofdashboard-full-backup-controller-' . bin2hex(random_bytes(4));
$backupDir = $baseDir . DIRECTORY_SEPARATOR . 'backups';
$savegameDir = $baseDir . DIRECTORY_SEPARATOR . 'savegame1';
mkdir($backupDir, 0777, true);
mkdir($savegameDir, 0777, true);

define('FS_BASE_DIR', $baseDir);
define('BACKUP_DIR', $backupDir);

require __DIR__ . '/../app/ApiResponseService.php';
require __DIR__ . '/../app/SavegameService.php';
require __DIR__ . '/../app/BackupService.php';
require __DIR__ . '/../app/FullBackupController.php';

file_put_contents($savegameDir . DIRECTORY_SEPARATOR . 'careerSavegame.xml', '<careerSavegame/>');
file_put_contents($savegameDir . DIRECTORY_SEPARATOR . 'farms.xml', '<farms/>');

$_SESSION = ['savegame_folder' => 'savegame1'];

if (class_exists('ZipArchive')) {
    http_response_code(200);
    ob_start();
    handle_full_backup_create();
    $createResponse = json_decode(ob_get_clean(), true);
    expect_full_backup_controller_test(http_response_code() === 200, 'Expected create status 200.');
    expect_full_backup_controller_test($createResponse['success'] === true, 'Expected create success.');
    expect_full_backup_controller_test(isset($createResponse['file'], $createResponse['size']), 'Expected create file and size.');
    expect_full_backup_controller_test(is_file(full_backup_dir() . DIRECTORY_SEPARATOR . $createResponse['file']), 'Expected created full backup file.');

    $zip = new ZipArchive();
    expect_full_backup_controller_test($zip->open(full_backup_dir() . DIRECTORY_SEPARATOR . $createResponse['file']) === true, 'Expected created ZIP to open.');
    expect_full_backup_controller_test($zip->locateName('careerSavegame.xml') !== false, 'Expected savegame file in created ZIP.');
    $zip->close();
}

$manualFile = 'savegame1_full_2026-08-11_120001_001.zip';
file_put_contents(full_backup_dir() . DIRECTORY_SEPARATOR . $manualFile, 'zip');

http_response_code(200);
ob_start();
handle_full_backups_list();
$listResponse = json_decode(ob_get_clean(), true);
expect_full_backup_controller_test(http_response_code() === 200, 'Expected list status 200.');
$manualEntry = null;
foreach ($listResponse['backups'] as $backup) {
    if ($backup['file'] === $manualFile) {
        $manualEntry = $backup;
        break;
    }
}
expect_full_backup_controller_test($manualEntry !== null, 'Expected manual full backup in list.');
expect_full_backup_controller_test($manualEntry['formatted'] === '11.08.2026 12:00:01', 'Expected formatted full backup timestamp.');

http_response_code(200);
ob_start();
handle_full_backup_delete(json_encode(['file' => $manualFile]));
$deleteResponse = json_decode(ob_get_clean(), true);
expect_full_backup_controller_test(http_response_code() === 200, 'Expected delete status 200.');
expect_full_backup_controller_test($deleteResponse === ['success' => true], 'Expected delete response payload.');
expect_full_backup_controller_test(!file_exists(full_backup_dir() . DIRECTORY_SEPARATOR . $manualFile), 'Expected full backup file to be deleted.');

http_response_code(200);
ob_start();
handle_full_backup_delete(json_encode(['file' => '../bad.zip']));
$invalidDeleteResponse = json_decode(ob_get_clean(), true);
expect_full_backup_controller_test(http_response_code() === 400, 'Expected invalid delete filename status 400.');
expect_full_backup_controller_test($invalidDeleteResponse['error'] === 'Ungültiger Backup-Dateiname.', 'Expected invalid delete filename error.');

http_response_code(200);
ob_start();
handle_full_backup_download('../bad.zip');
ob_end_clean();
expect_full_backup_controller_test(http_response_code() === 400, 'Expected invalid download filename status 400.');

foreach (glob(full_backup_dir() . DIRECTORY_SEPARATOR . '*') as $path) {
    unlink($path);
}
rmdir(full_backup_dir());
unlink($savegameDir . DIRECTORY_SEPARATOR . 'farms.xml');
unlink($savegameDir . DIRECTORY_SEPARATOR . 'careerSavegame.xml');
rmdir($savegameDir);
rmdir($backupDir);
rmdir($baseDir);

echo "full_backup_controller_test: ok\n";
