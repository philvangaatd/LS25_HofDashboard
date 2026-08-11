<?php
declare(strict_types=1);

function handle_autodrive_backups_list(): void
{
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        api_json_error('no_savegame_selected', 409);
        return;
    }

    $files = list_backups_for($folder);
    $result = array_map(function ($file) {
        // Millisekunden-Suffix ist optional: ältere Backups von vor dessen Einführung
        // haben nur Datum+Uhrzeit ohne "_XXX" am Ende.
        preg_match('/_(\d{4}-\d{2}-\d{2}_\d{6})(?:_\d{3})?\.xml$/', $file, $matches);
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

function handle_autodrive_backup_restore(?string $rawInput = null): void
{
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        api_json_error('no_savegame_selected', 409);
        return;
    }

    $configPath = get_selected_config_path();
    if (!$configPath) {
        api_json_error('no_autodrive', 409);
        return;
    }

    $body = json_decode($rawInput ?? file_get_contents('php://input'), true);
    $file = basename($body['file'] ?? ''); // basename() verhindert Path-Traversal

    // Millisekunden-Suffix optional (ältere Backups von vor dessen Einführung haben ihn nicht)
    if (!preg_match('/^' . preg_quote($folder, '/') . '_AutoDrive_config_\d{4}-\d{2}-\d{2}_\d{6}(?:_\d{3})?\.xml$/', $file)) {
        api_json_error('Ungültiger Backup-Dateiname.', 400);
        return;
    }

    $backupPath = BACKUP_DIR . '/' . $file;
    if (!file_exists($backupPath)) {
        api_json_error('Backup nicht gefunden.', 404);
        return;
    }

    // Sicherheitsnetz: aktuellen Stand vor dem Zurückspielen selbst sichern
    $safetyBackup = make_backup_filename($folder);
    copy($configPath, $safetyBackup);

    copy($backupPath, $configPath);
    prune_old_backups($folder, 20);

    api_json_success(['restoredFrom' => $file]);
}

function handle_autodrive_backup_delete(?string $rawInput = null): void
{
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        api_json_error('no_savegame_selected', 409);
        return;
    }

    $body = json_decode($rawInput ?? file_get_contents('php://input'), true);
    $file = basename($body['file'] ?? ''); // basename() verhindert Path-Traversal

    // Millisekunden-Suffix optional (ältere Backups von vor dessen Einführung haben ihn nicht)
    if (!preg_match('/^' . preg_quote($folder, '/') . '_AutoDrive_config_\d{4}-\d{2}-\d{2}_\d{6}(?:_\d{3})?\.xml$/', $file)) {
        api_json_error('Ungültiger Backup-Dateiname.', 400);
        return;
    }

    $path = BACKUP_DIR . '/' . $file;
    if (!file_exists($path)) {
        api_json_error('Backup nicht gefunden.', 404);
        return;
    }

    @unlink($path);

    api_json_success();
}
