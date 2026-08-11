<?php
declare(strict_types=1);

function expect_live_data_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofdashboard-live-test-' . bin2hex(random_bytes(4));
mkdir($baseDir . DIRECTORY_SEPARATOR . 'modSettings' . DIRECTORY_SEPARATOR . 'LS25HofDashboard', 0777, true);
mkdir($baseDir . DIRECTORY_SEPARATOR . 'modSettings' . DIRECTORY_SEPARATOR . 'AutoDriveFlurkarte', 0777, true);

define('FS_BASE_DIR', $baseDir);
define('HOF_DASHBOARD_VERSION', '5.0.1');
define('HOF_DASHBOARD_MIN_MOD_VERSION', '5.0.1');
define('HOF_DASHBOARD_PROTOCOL_MIN', 1);
define('HOF_DASHBOARD_PROTOCOL_MAX', 1);

require __DIR__ . '/../app/LiveDataService.php';

$compatibility = get_live_mod_compatibility([
    'version' => '5.0.1',
    'protocolVersion' => 1,
]);
expect_live_data_test($compatibility['status'] === 'compatible', 'Expected compatible live mod contract.');
expect_live_data_test($compatibility['protocolSource'] === 'mod', 'Expected explicit protocol source.');

$legacyCompatibility = get_live_mod_compatibility([
    'version' => '5.0.1',
]);
expect_live_data_test($legacyCompatibility['status'] === 'compatible', 'Expected implicit v5 protocol compatibility.');
expect_live_data_test($legacyCompatibility['protocolSource'] === 'legacy_v5_assumption', 'Expected legacy protocol source.');

$tooNewCompatibility = get_live_mod_compatibility([
    'version' => '5.0.1',
    'protocolVersion' => 2,
]);
expect_live_data_test($tooNewCompatibility['status'] === 'protocol_too_new', 'Expected too-new protocol rejection.');

$primaryPath = $baseDir . DIRECTORY_SEPARATOR . 'modSettings' . DIRECTORY_SEPARATOR . 'LS25HofDashboard' . DIRECTORY_SEPARATOR . 'liveData.json';
file_put_contents($primaryPath, json_encode([
    'version' => '5.0.1',
    'protocolVersion' => 1,
    'timestamp' => '2026-08-11T12:00:00',
], JSON_THROW_ON_ERROR));

$data = get_live_mod_data();
expect_live_data_test($data['status'] === 'ok', 'Expected fresh primary liveData.json to be ok.');
expect_live_data_test($data['compatibility']['status'] === 'compatible', 'Expected loaded data to include compatibility.');

unlink($primaryPath);
$legacyPath = $baseDir . DIRECTORY_SEPARATOR . 'modSettings' . DIRECTORY_SEPARATOR . 'AutoDriveFlurkarte' . DIRECTORY_SEPARATOR . 'liveData.json';
file_put_contents($legacyPath, json_encode([
    'version' => '5.0.1',
    'protocolVersion' => 1,
], JSON_THROW_ON_ERROR));

expect_live_data_test(get_live_data_file_path() === $legacyPath, 'Expected legacy path fallback when primary file is absent.');

unlink($legacyPath);
rmdir($baseDir . DIRECTORY_SEPARATOR . 'modSettings' . DIRECTORY_SEPARATOR . 'LS25HofDashboard');
rmdir($baseDir . DIRECTORY_SEPARATOR . 'modSettings' . DIRECTORY_SEPARATOR . 'AutoDriveFlurkarte');
rmdir($baseDir . DIRECTORY_SEPARATOR . 'modSettings');
rmdir($baseDir);

echo "live_data_service_test: ok\n";
