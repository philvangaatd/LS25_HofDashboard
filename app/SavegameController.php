<?php
declare(strict_types=1);

function handle_savegames_list(): void
{
    $result = [];
    foreach (glob(FS_BASE_DIR . DIRECTORY_SEPARATOR . 'savegame*', GLOB_ONLYDIR) as $dir) {
        $folder = basename($dir);
        if (!preg_match('/^savegame\d+$/', $folder)) {
            continue;
        }

        $careerFile = $dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml';
        $autoDriveFile = $dir . DIRECTORY_SEPARATOR . 'AutoDrive_config.xml';
        if (!file_exists($careerFile)) {
            continue;
        }

        $entry = [
            'folder' => $folder,
            'savegameName' => $folder,
            'farmName' => '',
            'manager' => '',
            'mapTitle' => '',
            'saveDate' => '',
            'saveDateSort' => '',
            'hasAutoDrive' => file_exists($autoDriveFile),
        ];

        libxml_use_internal_errors(true);
        $career = simplexml_load_file($careerFile);
        if ($career && isset($career->settings)) {
            $settings = $career->settings;
            $entry['savegameName'] = (string)($settings->savegameName ?? $folder);
            $entry['mapTitle'] = (string)($settings->mapTitle ?? '');
            $entry['saveDate'] = (string)($settings->saveDateFormatted ?? '');
            $entry['saveDateSort'] = (string)($settings->saveDate ?? '');
        }

        $farmInfo = get_farm_info($dir);
        $entry['farmName'] = $farmInfo['farmName'];
        $entry['manager'] = $farmInfo['manager'];

        $result[] = $entry;
    }

    // Neueste zuerst (ISO-Datum sortiert korrekt lexikalisch)
    usort($result, fn($a, $b) => strcmp($b['saveDateSort'], $a['saveDateSort']));

    api_json_response(['savegames' => $result, 'baseDir' => FS_BASE_DIR]);
}

function handle_savegame_select(?string $rawInput = null): void
{
    $body = json_decode($rawInput ?? file_get_contents('php://input'), true);
    $folder = $body['folder'] ?? '';
    $dir = get_general_savegame_dir($folder);

    if (!$dir) {
        api_json_error('Spielstand nicht gefunden.', 404);
        return;
    }

    $_SESSION['savegame_folder'] = $folder;
    api_json_success(['folder' => $folder, 'hasAutoDrive' => get_config_path_for_folder($folder) !== null]);
}

function handle_current_savegame(): void
{
    $folder = $_SESSION['savegame_folder'] ?? null;
    $dir = $folder ? get_general_savegame_dir($folder) : null;
    api_json_response([
        'folder' => $dir ? $folder : null,
        'hasAutoDrive' => $dir ? (get_config_path_for_folder($folder) !== null) : false,
    ]);
}

function handle_savegame_clear(): void
{
    unset($_SESSION['savegame_folder']);
    api_json_success();
}
