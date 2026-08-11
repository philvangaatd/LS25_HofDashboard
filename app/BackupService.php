<?php
declare(strict_types=1);

function list_backups_for(string $folder): array
{
    $files = glob(BACKUP_DIR . '/' . $folder . '_AutoDrive_config_*.xml');
    rsort($files);

    return $files;
}

function prune_old_backups(string $folder, int $keep): void
{
    $files = list_backups_for($folder);
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
}

function make_backup_filename(string $folder): string
{
    $ms = sprintf('%03d', (int)(microtime(true) * 1000) % 1000);

    return BACKUP_DIR . '/' . $folder . '_AutoDrive_config_' . date('Y-m-d_His') . '_' . $ms . '.xml';
}

function full_backup_dir(): string
{
    $dir = BACKUP_DIR . '/full';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    return $dir;
}

function list_full_backups_for(string $folder): array
{
    $files = glob(full_backup_dir() . '/' . $folder . '_full_*.zip');
    rsort($files);

    return $files;
}

function prune_old_full_backups(string $folder, int $keep): void
{
    $files = list_full_backups_for($folder);
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
}

function make_full_backup_filename(string $folder): string
{
    $ms = sprintf('%03d', (int)(microtime(true) * 1000) % 1000);

    return full_backup_dir() . '/' . $folder . '_full_' . date('Y-m-d_His') . '_' . $ms . '.zip';
}

function list_farms_backups_for(string $folder): array
{
    $files = glob(BACKUP_DIR . '/' . $folder . '_farms_*.xml');
    rsort($files);

    return $files;
}

function prune_old_farms_backups(string $folder, int $keep): void
{
    $files = list_farms_backups_for($folder);
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
}

function make_farms_backup_filename(string $folder): string
{
    $ms = sprintf('%03d', (int)(microtime(true) * 1000) % 1000);

    return BACKUP_DIR . '/' . $folder . '_farms_' . date('Y-m-d_His') . '_' . $ms . '.xml';
}
