<?php
declare(strict_types=1);

$manifestPath = __DIR__ . '/app-manifest.json';
$manifestJson = file_get_contents($manifestPath);

if ($manifestJson === false) {
    throw new RuntimeException('App-Manifest konnte nicht gelesen werden: ' . $manifestPath);
}

try {
    $appManifest = json_decode($manifestJson, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    throw new RuntimeException('App-Manifest enthält ungültiges JSON.', 0, $exception);
}

$requiredKeys = ['version', 'dataLayoutVersion', 'apiProtocol', 'minimumModVersion'];
foreach ($requiredKeys as $requiredKey) {
    if (!array_key_exists($requiredKey, $appManifest)) {
        throw new RuntimeException('App-Manifest enthält kein Feld "' . $requiredKey . '".');
    }
}

if (!is_array($appManifest['apiProtocol'])
    || !isset($appManifest['apiProtocol']['min'], $appManifest['apiProtocol']['max'])) {
    throw new RuntimeException('App-Manifest enthält keine gültige API-Protokollspanne.');
}

define('HOF_DASHBOARD_APP_MANIFEST', $appManifest);
define('HOF_DASHBOARD_VERSION', (string)$appManifest['version']);
define('HOF_DASHBOARD_DATA_LAYOUT_VERSION', (int)$appManifest['dataLayoutVersion']);
define('HOF_DASHBOARD_PROTOCOL_MIN', (int)$appManifest['apiProtocol']['min']);
define('HOF_DASHBOARD_PROTOCOL_MAX', (int)$appManifest['apiProtocol']['max']);
define('HOF_DASHBOARD_MIN_MOD_VERSION', (string)$appManifest['minimumModVersion']);
