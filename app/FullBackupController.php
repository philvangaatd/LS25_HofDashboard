<?php
declare(strict_types=1);

function handle_full_backup_create(): void
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

    if (!class_exists('ZipArchive')) {
        $iniPath = php_ini_loaded_file() ?: null;
        $hint = $iniPath
            ? "Bitte in \"$iniPath\" die Zeile \"extension=zip\" aktivieren (führendes Semikolon entfernen) und den Server neu starten."
            : 'Bitte in der php.ini die Zeile "extension=zip" aktivieren (führendes Semikolon entfernen) und den Server neu starten. Den Pfad der geladenen php.ini zeigt "php --ini" im Terminal.';
        api_json_error('Die PHP-Erweiterung "zip" ist nicht aktiviert. ' . $hint, 500);
        return;
    }

    set_time_limit(180); // große Spielstände (Terrain-Caches etc.) können etwas dauern

    $zipPath = make_full_backup_filename($folder);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        api_json_error('ZIP-Datei konnte nicht angelegt werden.', 500);
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $localName = substr($file->getPathname(), strlen($dir) + 1);
        $zip->addFile($file->getPathname(), $localName);
    }
    $zip->close();

    prune_old_full_backups($folder, 5); // große Dateien - bewusst weniger Generationen als bei den AutoDrive-Backups

    api_json_success(['file' => basename($zipPath), 'size' => filesize($zipPath)]);
}

function handle_full_backups_list(): void
{
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        api_json_error('no_savegame_selected', 409);
        return;
    }

    $files = list_full_backups_for($folder);
    $result = array_map(function ($file) {
        preg_match('/_full_(\d{4}-\d{2}-\d{2}_\d{6})_\d{3}\.zip$/', $file, $matches);
        $timestamp = $matches[1] ?? '';
        $formatted = $timestamp ? sprintf(
            '%s.%s.%s %s:%s:%s',
            substr($timestamp, 8, 2),
            substr($timestamp, 5, 2),
            substr($timestamp, 0, 4),
            substr($timestamp, 11, 2),
            substr($timestamp, 13, 2),
            substr($timestamp, 15, 2)
        ) : '';

        return ['file' => basename($file), 'formatted' => $formatted, 'size' => filesize($file)];
    }, $files);

    api_json_response(['backups' => $result]);
}

function handle_full_backup_delete(?string $rawInput = null): void
{
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        api_json_error('no_savegame_selected', 409);
        return;
    }

    $body = json_decode($rawInput ?? file_get_contents('php://input'), true);
    $file = basename($body['file'] ?? '');

    if (!is_valid_full_backup_filename($folder, $file)) {
        api_json_error('Ungültiger Backup-Dateiname.', 400);
        return;
    }

    $path = full_backup_dir() . '/' . $file;
    if (!file_exists($path)) {
        api_json_error('Backup nicht gefunden.', 404);
        return;
    }

    @unlink($path);

    api_json_success();
}

function handle_full_backup_download(?string $fileParam = null): void
{
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        return;
    }

    $file = basename($fileParam ?? ($_GET['file'] ?? ''));
    if (!is_valid_full_backup_filename($folder, $file)) {
        http_response_code(400);
        return;
    }

    $path = full_backup_dir() . '/' . $file;
    if (!file_exists($path)) {
        http_response_code(404);
        return;
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
}

function is_valid_full_backup_filename(string $folder, string $file): bool
{
    return preg_match('/^' . preg_quote($folder, '/') . '_full_\d{4}-\d{2}-\d{2}_\d{6}_\d{3}\.zip$/', $file) === 1;
}
