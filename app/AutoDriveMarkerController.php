<?php
declare(strict_types=1);

function handle_autodrive_markers_get(): void
{
    if (empty($_SESSION['savegame_folder'])) {
        api_json_error('no_savegame_selected', 409);
        return;
    }

    $configPath = get_selected_config_path();
    if (!$configPath) {
        api_json_error('no_autodrive', 409);
        return;
    }

    $dom = load_dom($configPath);
    $markerData = read_autodrive_markers($dom);

    $folder = $_SESSION['savegame_folder'];
    $farmInfo = get_farm_info(FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder);

    api_json_response([
        'markers' => $markerData['markers'],
        'groups' => $markerData['groups'],
        'mapName' => $markerData['mapName'],
        'farmName' => $farmInfo['farmName'],
        'manager' => $farmInfo['manager'],
    ]);
}

function handle_autodrive_markers_save(?string $rawInput = null): void
{
    if (empty($_SESSION['savegame_folder'])) {
        api_json_error('no_savegame_selected', 409);
        return;
    }

    $configPath = get_selected_config_path();
    if (!$configPath) {
        api_json_error('no_autodrive', 409);
        return;
    }

    $body = json_decode($rawInput ?? file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['markers'])) {
        api_json_error('Ungültige Daten.', 400);
        return;
    }

    $dom = load_dom($configPath);
    $validIds = get_valid_waypoint_ids($dom);

    $validationError = validate_autodrive_markers($body['markers'], $validIds);
    if ($validationError !== null) {
        api_json_error($validationError['error'], $validationError['status']);
        return;
    }

    $folder = $_SESSION['savegame_folder'];
    $backupFile = create_autodrive_config_backup($folder, $configPath, 20);

    // Siehe Kommentar bei save_course: Zeitstempel erhalten, damit Steam Cloud beim
    // nächsten Spielstart nicht fälschlich einen Synchronisationskonflikt meldet.
    $originalMTime = filemtime($configPath);

    replace_autodrive_markers($dom, $body['markers']);

    $dom->save($configPath);
    if ($originalMTime !== false) {
        touch($configPath, $originalMTime);
    }

    api_json_success(['backup' => basename($backupFile), 'count' => count($body['markers'])]);
}
