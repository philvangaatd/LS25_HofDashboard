<?php
// -----------------------------------------------------------------
// Basis-Ordner mit allen Spielständen.
//
// Wird automatisch erkannt: %USERPROFILE%\Documents\My Games\FarmingSimulator2025
//
// Falls das bei dir nicht passt (z. B. abweichender Installationsort, OneDrive-
// Verknüpfung, o. Ä.), hier den Pfad manuell eintragen. Ist diese Konstante nicht
// leer, wird sie verwendet und die Auto-Erkennung übersprungen.
// Beispiel: define('FS_BASE_DIR_OVERRIDE', 'D:\\Spiele\\FarmingSimulator2025');
// -----------------------------------------------------------------
define('FS_BASE_DIR_OVERRIDE', '');

if (FS_BASE_DIR_OVERRIDE !== '') {
    define('FS_BASE_DIR', FS_BASE_DIR_OVERRIDE);
} else {
    $userProfile = getenv('USERPROFILE') ?: getenv('HOME');
    $autoPath = $userProfile ? $userProfile . '\\Documents\\My Games\\FarmingSimulator2025' : null;
    define('FS_BASE_DIR', $autoPath ?: '');
}

if (FS_BASE_DIR === '' || !is_dir(FS_BASE_DIR)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Spielstand-Ordner nicht gefunden" . (FS_BASE_DIR !== '' ? (': ' . FS_BASE_DIR) : ' (USERPROFILE nicht ermittelbar).') . "\n\n";
    echo "Bitte in config.php die Konstante FS_BASE_DIR_OVERRIDE mit dem korrekten Pfad zu\n";
    echo "deinem FarmingSimulator2025-Ordner setzen, z. B.:\n";
    echo "define('FS_BASE_DIR_OVERRIDE', 'D:\\\\Spiele\\\\FarmingSimulator2025');\n";
    exit;
}

// Ordner für automatische Backups vor jedem Speichern
define('BACKUP_DIR', __DIR__ . '/backups');
if (!is_dir(BACKUP_DIR)) {
    mkdir(BACKUP_DIR, 0777, true);
}

// Mitgelieferte Kartenhintergründe liegen als Base64-Text vor (assets/*.png.b64)
// und werden beim ersten Start automatisch zu echten PNG-Dateien dekodiert.
$assetsDir = __DIR__ . '/assets';
if (is_dir($assetsDir)) {
    foreach (glob($assetsDir . '/*.png.b64') as $b64File) {
        $pngFile = substr($b64File, 0, -4); // ".b64" entfernen
        if (!file_exists($pngFile)) {
            $decoded = base64_decode(file_get_contents($b64File));
            if ($decoded !== false) {
                file_put_contents($pngFile, $decoded);
            }
        }
    }
}

session_start();
