<?php
declare(strict_types=1);

function expect_live_contract(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

define('HOF_DASHBOARD_VERSION', '5.0.1');
define('HOF_DASHBOARD_MIN_MOD_VERSION', '5.0.1');
define('HOF_DASHBOARD_PROTOCOL_MIN', 1);
define('HOF_DASHBOARD_PROTOCOL_MAX', 1);

require __DIR__ . '/../app/LiveDataService.php';

$schemaPath = __DIR__ . '/../docs/live-data.schema.json';
$fixturePath = __DIR__ . '/fixtures/liveData.v1.fixture.json';

$schema = json_decode((string)file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);
$fixture = json_decode((string)file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);

expect_live_contract(($schema['properties']['protocolVersion']['const'] ?? null) === HOF_DASHBOARD_PROTOCOL_MIN, 'Schema protocolVersion must match dashboard protocol.');
expect_live_contract(($fixture['protocolVersion'] ?? null) === HOF_DASHBOARD_PROTOCOL_MIN, 'Fixture protocolVersion must match dashboard protocol.');
expect_live_contract(($fixture['minimumDashboardVersion'] ?? '') === HOF_DASHBOARD_MIN_MOD_VERSION, 'Fixture minimum dashboard version must match dashboard minimum.');

$required = $schema['required'] ?? [];
foreach ($required as $key) {
    expect_live_contract(array_key_exists($key, $fixture), "Fixture missing required top-level key: {$key}");
}

$compatibility = get_live_mod_compatibility($fixture);
expect_live_contract($compatibility['status'] === 'compatible', 'Fixture must be compatible with the dashboard.');
expect_live_contract(is_array($fixture['fields']) && count($fixture['fields']) > 0, 'Fixture must include field data.');
expect_live_contract(is_array($fixture['vehicles']) && count($fixture['vehicles']) > 0, 'Fixture must include vehicle data.');
expect_live_contract(is_array($fixture['animals']) && count($fixture['animals']) > 0, 'Fixture must include animal data.');
expect_live_contract(is_array($fixture['productions']) && count($fixture['productions']) > 0, 'Fixture must include production data.');
expect_live_contract(is_array($fixture['contracts']) && count($fixture['contracts']) > 0, 'Fixture must include contract data.');
expect_live_contract(is_array($fixture['market']) && count($fixture['market']) > 0, 'Fixture must include market data.');

echo "live_data_contract_test: ok\n";
