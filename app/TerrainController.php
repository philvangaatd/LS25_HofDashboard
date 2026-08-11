<?php
declare(strict_types=1);

function handle_map_size_info(): void
{
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        api_json_error('no_savegame_selected', 409);
        return;
    }

    $dir = get_general_savegame_dir($folder);
    if (!$dir) {
        api_json_error('Spielstand nicht gefunden.', 404);
        return;
    }

    $careerFile = $dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml';
    $mapId = '';
    if (file_exists($careerFile)) {
        libxml_use_internal_errors(true);
        $career = simplexml_load_file($careerFile);
        if ($career && isset($career->settings)) {
            $mapId = (string)($career->settings->mapId ?? '');
        }
    }

    api_json_response(['size' => find_map_size($mapId)]);
}

function handle_terrain_image(?string $folderParam = null): void
{
    $folder = (string)($folderParam ?? ($_GET['folder'] ?? ''));
    if (!preg_match('/^savegame\d+$/', $folder)) {
        api_json_error('invalid_savegame_folder', 400);
        return;
    }

    $fileName = 'terrain_' . $folder . '.png';
    $persistentPath = MAP_ASSETS_DIR . DIRECTORY_SEPARATOR . $fileName;
    $bundledPath = BUNDLED_ASSETS_DIR . DIRECTORY_SEPARATOR . $fileName;
    $path = is_file($persistentPath) ? $persistentPath : $bundledPath;

    if (!is_file($path)) {
        api_json_error('terrain_not_found', 404);
        return;
    }

    header('Content-Type: image/png');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=300');
    readfile($path);
}

function handle_terrain_delete(): void
{
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        api_json_error('no_savegame_selected', 409);
        return;
    }

    $path = MAP_ASSETS_DIR . DIRECTORY_SEPARATOR . 'terrain_' . $folder . '.png';
    if (file_exists($path)) {
        @unlink($path);
    }

    api_json_success();
}
