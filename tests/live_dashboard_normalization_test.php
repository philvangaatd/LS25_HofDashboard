<?php
declare(strict_types=1);

function expect_live_dashboard_normalization(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

function capture_live_dashboard_response(callable $handler): array
{
    http_response_code(200);
    ob_start();
    $handler();
    $raw = ob_get_clean();
    $decoded = json_decode((string)$raw, true);
    expect_live_dashboard_normalization(is_array($decoded), 'Expected JSON object response.');
    expect_live_dashboard_normalization(http_response_code() === 200, 'Expected response status 200.');
    return $decoded;
}

function fruit_type_label(string $fruitType): string
{
    return [
        'CANOLA' => 'Raps',
        'WATER' => 'Wasser',
        'TOMATO' => 'Tomaten',
    ][$fruitType] ?? $fruitType;
}

const MARKET_PERIOD_ORDER = [
    'EARLY_SPRING', 'MID_SPRING', 'LATE_SPRING',
    'EARLY_SUMMER', 'MID_SUMMER', 'LATE_SUMMER',
    'EARLY_AUTUMN', 'MID_AUTUMN', 'LATE_AUTUMN',
    'EARLY_WINTER', 'MID_WINTER', 'LATE_WINTER',
];
const MARKET_PERIOD_LABELS_DE = [
    'EARLY_SPRING' => 'Fr. FrÃ¼hling',
    'MID_SPRING' => 'FrÃ¼hling',
    'LATE_SPRING' => 'Sp. FrÃ¼hling',
    'EARLY_SUMMER' => 'Fr. Sommer',
    'MID_SUMMER' => 'Sommer',
    'LATE_SUMMER' => 'Sp. Sommer',
    'EARLY_AUTUMN' => 'Fr. Herbst',
    'MID_AUTUMN' => 'Herbst',
    'LATE_AUTUMN' => 'Sp. Herbst',
    'EARLY_WINTER' => 'Fr. Winter',
    'MID_WINTER' => 'Winter',
    'LATE_WINTER' => 'Sp. Winter',
];

function get_current_period_index(int $currentDay, int $daysPerPeriod): int
{
    $daysPerPeriod = max(1, $daysPerPeriod);
    return (((int)(floor(($currentDay - 1) / $daysPerPeriod)) % 12) + 12) % 12;
}

$baseDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hofdashboard-live-normalization-' . bin2hex(random_bytes(4));
$liveDir = $baseDir . DIRECTORY_SEPARATOR . 'modSettings' . DIRECTORY_SEPARATOR . 'LS25HofDashboard';
mkdir($liveDir, 0777, true);
copy(__DIR__ . '/fixtures/liveData.v1.fixture.json', $liveDir . DIRECTORY_SEPARATOR . 'liveData.json');

define('FS_BASE_DIR', $baseDir);
define('HOF_DASHBOARD_VERSION', '5.0.1');
define('HOF_DASHBOARD_MIN_MOD_VERSION', '5.0.1');
define('HOF_DASHBOARD_PROTOCOL_MIN', 1);
define('HOF_DASHBOARD_PROTOCOL_MAX', 1);

require __DIR__ . '/../app/LiveDataService.php';
require __DIR__ . '/../production_data.php';
require __DIR__ . '/../app/DashboardDataController.php';

$fields = capture_live_dashboard_response('handle_fields_data');
expect_live_dashboard_normalization($fields['source'] === 'lua-live', 'Expected fields source lua-live.');
expect_live_dashboard_normalization(count($fields['fields']) === 1, 'Expected one normalized field.');
expect_live_dashboard_normalization($fields['fields'][0]['fieldStatus'] === 'MIXED', 'Expected field status from live data.');
expect_live_dashboard_normalization($fields['fields'][0]['statusPercentages']['growing'] === 53.7, 'Expected field status percentages to be preserved.');
expect_live_dashboard_normalization($fields['fields'][0]['fruitTypeLabel'] === 'Raps', 'Expected fruit label normalization.');

$vehicles = capture_live_dashboard_response('handle_vehicles_data');
expect_live_dashboard_normalization($vehicles['source'] === 'lua-live', 'Expected vehicles source lua-live.');
expect_live_dashboard_normalization($vehicles['totalCount'] === 1, 'Expected one normalized vehicle.');
expect_live_dashboard_normalization($vehicles['categoryCounts']['VEHICLE'] === 1, 'Expected vehicle category count.');
expect_live_dashboard_normalization((float)$vehicles['totalDieselLiters'] === 150.0, 'Expected diesel liters from fill unit.');

$animals = capture_live_dashboard_response('handle_animals_data');
expect_live_dashboard_normalization($animals['source'] === 'lua-live', 'Expected animals source lua-live.');
expect_live_dashboard_normalization($animals['barnCount'] === 1, 'Expected one normalized husbandry.');
expect_live_dashboard_normalization($animals['totalAnimals'] === 10, 'Expected total animals.');
expect_live_dashboard_normalization($animals['husbandries'][0]['food']['percent'] === 20, 'Expected animal food percentage.');

$productions = capture_live_dashboard_response('handle_production_data');
expect_live_dashboard_normalization($productions['pointCount'] === 1, 'Expected one production point.');
expect_live_dashboard_normalization($productions['productionPoints'][0]['productions'][0]['id'] === 'tomato', 'Expected active production id.');
expect_live_dashboard_normalization($productions['productionPoints'][0]['inputStorages'][0]['fillType'] === 'WATER', 'Expected input storage normalization.');

$market = capture_live_dashboard_response('handle_market_data');
expect_live_dashboard_normalization($market['source'] === 'lua-live-stations', 'Expected market source.');
expect_live_dashboard_normalization($market['market'][0]['fruitType'] === 'CANOLA', 'Expected market fill type.');
expect_live_dashboard_normalization($market['market'][0]['isOwnCrop'] === true, 'Expected own crop detection.');
expect_live_dashboard_normalization($market['market'][0]['stations'][0]['price'] === 1292, 'Expected station price normalization.');

$missions = capture_live_dashboard_response('handle_missions_data');
expect_live_dashboard_normalization(count($missions['missions']) === 1, 'Expected one mission.');
expect_live_dashboard_normalization($missions['missions'][0]['typeLabel'] === 'Ernten', 'Expected mission type label.');
expect_live_dashboard_normalization($missions['missions'][0]['daysLeft'] === 99, 'Expected current placeholder compatibility.');

unlink($liveDir . DIRECTORY_SEPARATOR . 'liveData.json');
rmdir($liveDir);
rmdir(dirname($liveDir));
rmdir(dirname(dirname($liveDir)));

echo "live_dashboard_normalization_test: ok\n";
