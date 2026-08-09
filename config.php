<?php
require_once __DIR__ . '/version.php';

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

// Basisordner für veränderliche App-Daten. Ohne Umgebungsvariable bleibt das
// bisherige Verhalten vollständig erhalten. Der spätere Windows-Launcher setzt
// HOF_DASHBOARD_DATA_DIR auf %LOCALAPPDATA%\\HofDashboard.
$appDataOverride = trim((string)(getenv('HOF_DASHBOARD_DATA_DIR') ?: ''));
$appDataDir = $appDataOverride !== '' ? rtrim($appDataOverride, "/\\") : __DIR__;
define('APP_DATA_DIR', $appDataDir !== '' ? $appDataDir : __DIR__);

if (!is_dir(APP_DATA_DIR)
    && !mkdir(APP_DATA_DIR, 0777, true)
    && !is_dir(APP_DATA_DIR)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "App-Datenordner konnte nicht erstellt werden: " . APP_DATA_DIR;
    exit;
}

// Ordner für automatische Backups vor jedem Speichern
define('BACKUP_DIR', APP_DATA_DIR . DIRECTORY_SEPARATOR . 'backups');
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

// -----------------------------------------------------------------
// Spiel-Installationsordner (nur für die automatische Kartenbild-Suche bei
// offiziellen GIANTS-Karten nötig – anders als Mod-Karten liegen die nicht im
// mods-Ordner des Spielstands, sondern direkt in den Spieldateien. Alle anderen
// Funktionen des Tools funktionieren auch ohne diesen Fund einwandfrei.
//
// Auto-Erkennung probiert ein paar übliche Steam-Installationspfade durch. Falls
// deine Installation woanders liegt, hier manuell eintragen:
// define('FS_INSTALL_DIR_OVERRIDE', 'D:\\SteamLibrary\\steamapps\\common\\Farming Simulator 25');
// -----------------------------------------------------------------
define('FS_INSTALL_DIR_OVERRIDE', '');

function detect_fs_install_dir(): string {
    if (FS_INSTALL_DIR_OVERRIDE !== '' && is_dir(FS_INSTALL_DIR_OVERRIDE . '/data/maps')) {
        return FS_INSTALL_DIR_OVERRIDE;
    }
    $candidates = [];
    // Alle Laufwerksbuchstaben prüfen, nicht nur C-F: Steam-Bibliotheken liegen häufig
    // auf zusätzlichen Laufwerken/Partitionen (z. B. A:, B: bei vielen Laufwerken im System).
    foreach (range('A', 'Z') as $drive) {
        $candidates[] = $drive . ':\\Program Files (x86)\\Steam\\steamapps\\common\\Farming Simulator 25';
        $candidates[] = $drive . ':\\Steam\\steamapps\\common\\Farming Simulator 25';
        $candidates[] = $drive . ':\\SteamLibrary\\steamapps\\common\\Farming Simulator 25';
        $candidates[] = $drive . ':\\Games\\Farming Simulator 25';
    }
    foreach ($candidates as $c) {
        if (is_dir($c . '\\data\\maps')) return $c;
    }
    return '';
}
define('FS_INSTALL_DIR', detect_fs_install_dir());

session_start();
