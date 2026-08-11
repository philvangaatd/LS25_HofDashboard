<?php
declare(strict_types=1);

function is_valid_savegame_folder(string $folder): bool
{
    return preg_match('/^savegame\d+$/', $folder) === 1;
}

function get_config_path_for_folder(string $folder): ?string
{
    if (!is_valid_savegame_folder($folder)) {
        return null;
    }

    $path = FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . 'AutoDrive_config.xml';

    return file_exists($path) ? $path : null;
}

function get_general_savegame_dir(string $folder): ?string
{
    if (!is_valid_savegame_folder($folder)) {
        return null;
    }

    $dir = FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder;

    return file_exists($dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml') ? $dir : null;
}

function get_selected_config_path(): ?string
{
    if (empty($_SESSION['savegame_folder'])) {
        return null;
    }

    return get_config_path_for_folder($_SESSION['savegame_folder']);
}
