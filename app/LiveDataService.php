<?php
declare(strict_types=1);

function get_legacy_live_data_file_path(): string
{
    return rtrim(FS_BASE_DIR, "/\\")
        . DIRECTORY_SEPARATOR . 'modSettings'
        . DIRECTORY_SEPARATOR . 'AutoDriveFlurkarte'
        . DIRECTORY_SEPARATOR . 'liveData.json';
}

function get_live_data_file_path(): string
{
    $legacyPath = get_legacy_live_data_file_path();
    $needle = DIRECTORY_SEPARATOR . 'AutoDriveFlurkarte' . DIRECTORY_SEPARATOR;
    $replacement = DIRECTORY_SEPARATOR . 'LS25HofDashboard' . DIRECTORY_SEPARATOR;
    $primaryPath = str_replace($needle, $replacement, $legacyPath);

    if (is_file($primaryPath)) {
        return $primaryPath;
    }

    if (is_file($legacyPath)) {
        return $legacyPath;
    }

    return $primaryPath;
}

function get_live_mod_compatibility(array $data): array
{
    $modVersion = (string)($data['version'] ?? '');
    $hasExplicitProtocol = array_key_exists('protocolVersion', $data);
    $protocolVersion = $hasExplicitProtocol
        ? (int)$data['protocolVersion']
        : (($modVersion !== '' && version_compare($modVersion, '5.0.0', '>=')) ? 1 : 0);

    $status = 'compatible';
    $message = 'Dashboard und Live-Mod verwenden einen kompatiblen Datenvertrag.';

    if ($modVersion === '' || version_compare($modVersion, HOF_DASHBOARD_MIN_MOD_VERSION, '<')) {
        $status = 'mod_too_old';
        $message = 'Die Live-Mod ist älter als die vom Dashboard unterstützte Mindestversion.';
    } elseif ($protocolVersion < HOF_DASHBOARD_PROTOCOL_MIN) {
        $status = 'protocol_too_old';
        $message = 'Das Datenprotokoll der Live-Mod ist für dieses Dashboard zu alt.';
    } elseif ($protocolVersion > HOF_DASHBOARD_PROTOCOL_MAX) {
        $status = 'protocol_too_new';
        $message = 'Das Datenprotokoll der Live-Mod ist neuer als dieses Dashboard unterstützt.';
    }

    return [
        'status' => $status,
        'isCompatible' => $status === 'compatible',
        'message' => $message,
        'modVersion' => $modVersion,
        'protocolVersion' => $protocolVersion,
        'protocolSource' => $hasExplicitProtocol ? 'mod' : 'legacy_v5_assumption',
        'dashboardVersion' => HOF_DASHBOARD_VERSION,
        'supportedProtocol' => [
            'min' => HOF_DASHBOARD_PROTOCOL_MIN,
            'max' => HOF_DASHBOARD_PROTOCOL_MAX,
        ],
        'minimumModVersion' => HOF_DASHBOARD_MIN_MOD_VERSION,
    ];
}

function get_live_mod_data(): array
{
    $filePath = get_live_data_file_path();

    if (!file_exists($filePath)) {
        return [
            'status' => 'no_mod',
            'fileAgeSeconds' => 0,
            'message' => 'Mod nicht aktiv oder Spiel läuft nicht. '
                . 'Bitte FS25_HofDashboard-Mod aktivieren und Spiel starten.',
            'filePath' => $filePath,
        ];
    }

    $fileAge = time() - filemtime($filePath);
    $content = file_get_contents($filePath);

    if ($content === false || trim($content) === '') {
        return [
            'status' => 'error',
            'fileAgeSeconds' => $fileAge,
            'message' => 'Datei leer oder nicht lesbar.',
        ];
    }

    $data = json_decode($content, true);
    if ($data === null) {
        return [
            'status' => 'error',
            'fileAgeSeconds' => $fileAge,
            'message' => 'JSON-Parse-Fehler: ' . json_last_error_msg(),
        ];
    }

    $data['compatibility'] = get_live_mod_compatibility($data);
    $data['status'] = ($fileAge > 120) ? 'stale' : 'ok';
    $data['fileAgeSeconds'] = $fileAge;

    return $data;
}
