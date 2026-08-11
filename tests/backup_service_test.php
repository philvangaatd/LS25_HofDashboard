<?php
declare(strict_types=1);

function expect_backup_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$backupRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofdashboard-backup-test-' . bin2hex(random_bytes(4));
mkdir($backupRoot, 0777, true);
define('BACKUP_DIR', $backupRoot);

require __DIR__ . '/../app/BackupService.php';

file_put_contents($backupRoot . DIRECTORY_SEPARATOR . 'savegame1_AutoDrive_config_2026-08-11_120000_001.xml', 'old');
file_put_contents($backupRoot . DIRECTORY_SEPARATOR . 'savegame1_AutoDrive_config_2026-08-11_120001_001.xml', 'new');
file_put_contents($backupRoot . DIRECTORY_SEPARATOR . 'savegame2_AutoDrive_config_2026-08-11_120002_001.xml', 'other');

$autoDriveBackups = list_backups_for('savegame1');
expect_backup_test(count($autoDriveBackups) === 2, 'Expected two AutoDrive backups for savegame1.');
expect_backup_test(str_contains($autoDriveBackups[0], '120001'), 'Expected newest AutoDrive backup first.');
expect_backup_test(preg_match('/savegame1_AutoDrive_config_\d{4}-\d{2}-\d{2}_\d{6}_\d{3}\.xml$/', make_backup_filename('savegame1')) === 1, 'Expected AutoDrive backup filename format.');

prune_old_backups('savegame1', 1);
expect_backup_test(count(list_backups_for('savegame1')) === 1, 'Expected AutoDrive pruning to keep one file.');
expect_backup_test(is_file($backupRoot . DIRECTORY_SEPARATOR . 'savegame2_AutoDrive_config_2026-08-11_120002_001.xml'), 'Expected pruning to keep other savegames.');

$fullDir = full_backup_dir();
expect_backup_test(is_dir($fullDir), 'Expected full backup directory to be created.');
file_put_contents($fullDir . DIRECTORY_SEPARATOR . 'savegame1_full_2026-08-11_120000_001.zip', 'old');
file_put_contents($fullDir . DIRECTORY_SEPARATOR . 'savegame1_full_2026-08-11_120001_001.zip', 'new');

$fullBackups = list_full_backups_for('savegame1');
expect_backup_test(count($fullBackups) === 2, 'Expected two full backups.');
expect_backup_test(str_contains($fullBackups[0], '120001'), 'Expected newest full backup first.');
expect_backup_test(preg_match('/savegame1_full_\d{4}-\d{2}-\d{2}_\d{6}_\d{3}\.zip$/', make_full_backup_filename('savegame1')) === 1, 'Expected full backup filename format.');

prune_old_full_backups('savegame1', 1);
expect_backup_test(count(list_full_backups_for('savegame1')) === 1, 'Expected full backup pruning to keep one file.');

file_put_contents($backupRoot . DIRECTORY_SEPARATOR . 'savegame1_farms_2026-08-11_120000_001.xml', 'old');
file_put_contents($backupRoot . DIRECTORY_SEPARATOR . 'savegame1_farms_2026-08-11_120001_001.xml', 'new');

$farmBackups = list_farms_backups_for('savegame1');
expect_backup_test(count($farmBackups) === 2, 'Expected two farms backups.');
expect_backup_test(str_contains($farmBackups[0], '120001'), 'Expected newest farms backup first.');
expect_backup_test(preg_match('/savegame1_farms_\d{4}-\d{2}-\d{2}_\d{6}_\d{3}\.xml$/', make_farms_backup_filename('savegame1')) === 1, 'Expected farms backup filename format.');

prune_old_farms_backups('savegame1', 1);
expect_backup_test(count(list_farms_backups_for('savegame1')) === 1, 'Expected farms backup pruning to keep one file.');

foreach (glob($backupRoot . DIRECTORY_SEPARATOR . '*') as $path) {
    if (is_dir($path)) {
        foreach (glob($path . DIRECTORY_SEPARATOR . '*') as $child) {
            unlink($child);
        }
        rmdir($path);
    } else {
        unlink($path);
    }
}
rmdir($backupRoot);

echo "backup_service_test: ok\n";
