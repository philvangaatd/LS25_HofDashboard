<?php
declare(strict_types=1);

function terrain_gd_missing_hint(): string
{
    $iniPath = php_ini_loaded_file() ?: null;

    return $iniPath
        ? "Bitte in \"$iniPath\" die Zeile \"extension=gd\" aktivieren (führendes Semikolon entfernen) und den Server neu starten."
        : 'Bitte in der php.ini die Zeile "extension=gd" aktivieren (führendes Semikolon entfernen) und den Server neu starten.';
}

function terrain_map_id_from_savegame_dir(string $dir): string
{
    $careerFile = $dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml';
    if (!file_exists($careerFile)) {
        return '';
    }

    libxml_use_internal_errors(true);
    $career = simplexml_load_file($careerFile);
    if ($career && isset($career->settings)) {
        return (string)($career->settings->mapId ?? '');
    }

    return '';
}

function handle_terrain_upload(?array $uploadedFile = null): void
{
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        api_json_error('no_savegame_selected', 409);
        return;
    }

    // Ohne die PHP-Erweiterung "gd" schlägt jede einzelne Bildprüfung weiter unten fehl
    // (function_exists() für imagecreatefrompng/-jpeg/-webp ist dann immer false).
    if (!extension_loaded('gd')) {
        api_json_error('Die PHP-Erweiterung "gd" ist nicht aktiviert (wird für die Bildverarbeitung benötigt). ' . terrain_gd_missing_hint(), 500);
        return;
    }

    $image = $uploadedFile ?? ($_FILES['image'] ?? null);
    if (empty($image) || ($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $uploadError = $image['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            // Besonders relevant bei großen Kartentexturen: PHP-Limits greifen vor
            // unserer eigenen 25-MB-Prüfung.
            $iniPath = php_ini_loaded_file() ?: null;
            $currentLimit = ini_get('upload_max_filesize') . ' / post_max_size ' . ini_get('post_max_size');
            $hint = $iniPath
                ? "Bitte in \"$iniPath\" die Werte \"upload_max_filesize\" und \"post_max_size\" erhöhen (z. B. auf 32M) und den Server neu starten."
                : 'Bitte in der php.ini die Werte "upload_max_filesize" und "post_max_size" erhöhen (z. B. auf 32M) und den Server neu starten.';
            api_json_error("Datei überschreitet das aktuelle PHP-Upload-Limit (upload_max_filesize $currentLimit). $hint", 413);
            return;
        }

        api_json_error('Kein gültiges Bild empfangen.', 400);
        return;
    }

    $maxBytes = 25 * 1024 * 1024;
    if ($image['size'] > $maxBytes) {
        api_json_error('Datei zu groß (maximal 25 MB).', 413);
        return;
    }

    $destPath = MAP_ASSETS_DIR . '/terrain_' . $folder . '.png';
    $result = save_terrain_image_from_path($image['tmp_name'], $destPath);

    if (isset($result['error'])) {
        api_json_response($result, 422);
        return;
    }

    api_json_response($result);
}

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

    $mapId = terrain_map_id_from_savegame_dir($dir);

    api_json_response(['size' => find_map_size($mapId)]);
}

function handle_load_map_terrain(): void
{
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        api_json_error('no_savegame_selected', 409);
        return;
    }

    if (!extension_loaded('gd')) {
        api_json_error('Die PHP-Erweiterung "gd" ist nicht aktiviert (wird für die Bildverarbeitung benötigt). ' . terrain_gd_missing_hint(), 500);
        return;
    }

    $dir = get_general_savegame_dir($folder);
    if (!$dir) {
        api_json_error('Spielstand nicht gefunden.', 404);
        return;
    }

    $mapId = terrain_map_id_from_savegame_dir($dir);
    $found = find_map_overview_image($mapId);

    if (!$found['found']) {
        $messages = [
            'no_zip_extension' => 'Die PHP-Erweiterung "zip" wird für die automatische Kartensuche benötigt und ist nicht aktiviert.',
            'no_map_id' => 'Im Spielstand konnte keine Karten-ID gefunden werden.',
            'no_mod_zip' => 'Für diese Karte wurde keine Mod-Datei im "mods"-Ordner gefunden.',
            'zip_open_failed' => 'Die Mod-Datei der Karte konnte nicht geöffnet werden.',
            'no_candidate' => 'Es wurde kein Kartenbild gefunden.',
            'dds_only' => 'Es wurde ein Kartenbild gefunden, aber nur im DDS-Format – das kann dieses Tool nicht lesen (nur PNG/JPEG werden unterstützt).',
            'extract_failed' => 'Das gefundene Kartenbild konnte nicht gelesen werden.',
            'no_install_dir' => 'Das ist eine offizielle GIANTS-Karte ohne Mod-Datei – dafür müsste der Installationsordner des Spiels bekannt sein. Der wurde automatisch nicht gefunden. Trage ihn in config.php unter FS_INSTALL_DIR_OVERRIDE manuell ein, z. B. define(\'FS_INSTALL_DIR_OVERRIDE\', \'D:\\\\SteamLibrary\\\\steamapps\\\\common\\\\Farming Simulator 25\');.',
            'map_dir_not_found' => 'Im Installationsordner des Spiels wurde kein Datenordner für diese Karte gefunden.',
        ];
        $reason = $found['reason'] ?? 'no_candidate';
        api_json_response([
            'error' => ($messages[$reason] ?? 'Kein automatisch nutzbares Kartenbild gefunden.') . ' Bitte manuell ein Bild hochladen.',
            'ddsAvailable' => $found['ddsOnly'] ?? false,
        ], 404);
        return;
    }

    // Extrahierte Bilddaten in eine temporäre Datei schreiben, damit dieselbe
    // Verarbeitung wie beim manuellen Upload greifen kann.
    $tmpFile = tempnam(sys_get_temp_dir(), 'mapimg_');
    file_put_contents($tmpFile, $found['data']);

    $destPath = MAP_ASSETS_DIR . '/terrain_' . $folder . '.png';
    $result = save_terrain_image_from_path($tmpFile, $destPath);
    @unlink($tmpFile);

    if (isset($result['error'])) {
        api_json_response($result, 422);
        return;
    }

    $result['source'] = $found['sourceName'];
    api_json_response($result);
}

function handle_fetch_map_dds(): void
{
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        return;
    }

    $dir = get_general_savegame_dir($folder);
    if (!$dir) {
        http_response_code(404);
        return;
    }

    $mapId = terrain_map_id_from_savegame_dir($dir);
    $ddsData = find_map_overview_dds($mapId);
    if ($ddsData === null) {
        http_response_code(404);
        return;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($ddsData));
    echo $ddsData;
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
