<?php
declare(strict_types=1);

function handle_farm_overview(): void
{
$liveData = get_live_mod_data();
$farm     = $liveData['farm']     ?? [];
$fields   = $liveData['fields']   ?? [];
$vehicles = $liveData['vehicles'] ?? [];
$contracts = $liveData['contracts'] ?? [];

// careerSavegame.xml nur für Metadaten die der Mod nicht liefert
$folder = $_SESSION['savegame_folder'] ?? null;
$playTime  = null;
$lastSaved = '';
$mapTitle  = $liveData['mapName'] ?? '';
// Saisonperiode aus Live-Daten
$currentDayLive    = (int)($liveData['currentDay']    ?? 0);
$daysPerPeriodLive = (int)($liveData['daysPerPeriod'] ?? 24);
$periodLabel = '';
if ($currentDayLive > 0 && $daysPerPeriodLive > 0) {
    $pidx = get_current_period_index($currentDayLive, $daysPerPeriodLive);
    $periodLabel = MARKET_PERIOD_LABELS_DE[MARKET_PERIOD_ORDER[$pidx]] ?? '';
}
if ($folder) {
    $dir = FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder;
    $careerFile = $dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml';
    if (file_exists($careerFile)) {
        libxml_use_internal_errors(true);
        $career = simplexml_load_file($careerFile);
        if ($career && isset($career->statistics))
            $playTime = (float)($career->statistics->playTime ?? 0);
        if ($career && isset($career->settings)) {
            if (empty($mapTitle)) $mapTitle = (string)($career->settings->mapTitle ?? '');
            $lastSaved = (string)($career->settings->saveDateFormatted ?? '');
        }
    }
}

// Feldzustände werden ausschließlich vom Lua-Mod ermittelt.
$harvestReady = array_values(array_filter(
    $fields,
    fn($f) => strtoupper((string)($f['fieldStatus'] ?? '')) === 'READY'
));
$fieldCount   = count($fields);
$harvestReadyFields = array_map(
    fn($f) => ['id' => $f['id'], 'fruitTypeLabel' => $f['fruitTitle'] ?? $f['fruitType'] ?? ''],
    $harvestReady
);

// Fuhrpark vollständig aus demselben Lua-Live-Export wie der Fuhrpark-Tab.
$vehicleCount = count($vehicles);

// Fahrzeuge mit Wartungsbedarf
$vehiclesNeedingAttention = array_values(array_map(
    fn($v) => ['name' => $v['name'], 'wear' => $v['wear'], 'dirt' => $v['dirt']],
    array_filter($vehicles, fn($v) => ($v['wear'] ?? 0) > 0.5 || ($v['dirt'] ?? 0) > 0.5)
));
usort($vehiclesNeedingAttention, fn($a,$b) =>
    max($b['wear'],$b['dirt']) <=> max($a['wear'],$a['dirt']));

echo json_encode([
    'farmName'               => $farm['name']  ?? '',
    'manager'                => '',
    'mapTitle'               => $mapTitle,
    'money'                  => (int)($farm['money'] ?? 0),
    'loan'                   => (int)($farm['loan']  ?? 0),
    'playTimeHours'          => $playTime !== null ? round($playTime / 60, 1) : null,
    'currentDay'             => (int)($liveData['currentDay'] ?? 0),
    'season'                 => $periodLabel ?? '',
    'fieldCount'             => $fieldCount,
    'harvestReadyCount'      => count($harvestReady),
    'vehicleCount'           => $vehicleCount,
    'harvestReadyFields'     => $harvestReadyFields,
    'vehiclesNeedingAttention' => array_slice($vehiclesNeedingAttention, 0, 5),
    'missionsTotalCount'     => count($contracts),
    'weatherForecast'        => ($folder && $currentDayLive > 0 && isset($dir) && is_dir($dir))
        ? get_weather_forecast($dir, $currentDayLive, 5)
        : [],
    'lastSaved'              => $lastSaved,
    'liveStatus'             => $liveData['status'] ?? 'unknown',
    'liveAge'                => $liveData['fileAgeSeconds'] ?? 0,
]);
}

function handle_fields_data(): void
{
$liveData   = get_live_mod_data();
$liveFields = $liveData['fields'] ?? [];

if (($liveData['status'] ?? 'error') === 'no_mod' || empty($liveFields)) {
    echo json_encode(['error' => 'Mod nicht aktiv. FS25_HofDashboard aktivieren und Spiel starten.']);
    exit;
}

$playerFarmId = (int)($liveData['farm']['farmId'] ?? 0);
if ($playerFarmId > 0) {
    $liveFields = array_values(array_filter(
        $liveFields,
        fn($field) => (int)($field['farmId'] ?? 0) === $playerFarmId
    ));
}

// PHP interpretiert keine FS25-GroundTypes oder Wachstumszustände mehr neu.
// fieldStatus und statusPercentages werden vom Lua-Mod festgelegt. PHP übernimmt
// nur noch Darstellung, Prozentwerte für UI-Balken und Handlungsempfehlungen.
$statusLabels = [
    'READY'     => 'Erntereif',
    'GROWING'   => 'Im Wachstum',
    'HARVESTED' => 'Abgeerntet',
    'TILLED'    => 'Bearbeitet',
    'WITHERED'  => 'Vertrocknet',
    'FALLOW'    => 'Brache',
    'MIXED'     => 'Teilweise bearbeitet',
];
$validStatuses = array_fill_keys(array_keys($statusLabels), true);

// Nur die Beschriftung eines bereits von Lua als TILLED klassifizierten Feldes
// darf anhand des GroundTypes genauer formuliert werden. Der Status selbst ändert
// sich dadurch ausdrücklich nicht.
$tilledLabels = [
    'PLOWED'          => 'Gepflügt',
    'CULTIVATED'      => 'Gegrubbert',
    'STUBBLE_TILLAGE' => 'Stoppelsturz',
    'SEEDBED'         => 'Saatbett',
    'ROLLED_SEEDBED'  => 'Saatbett gewalzt',
    'ROLLER_LINES'    => 'Gewalzt',
    'RIDGE'           => 'Dämme gezogen',
    'GRASS_CUT'       => 'Gemäht',
];

$fields = [];
foreach ($liveFields as $lf) {
    $fieldStatus = strtoupper((string)($lf['fieldStatus'] ?? 'FALLOW'));
    if (!isset($validStatuses[$fieldStatus])) {
        $fieldStatus = 'FALLOW';
    }

    $groundType = strtoupper((string)($lf['groundType'] ?? 'NONE'));
    $statusLabel = $statusLabels[$fieldStatus];
    if ($fieldStatus === 'TILLED' && isset($tilledLabels[$groundType])) {
        $statusLabel = $tilledLabels[$groundType];
    }

    $maxGs = max(0, (int)($lf['maxGrowthState'] ?? 0));
    $gs    = (int)($lf['growthState'] ?? 0);
    $growthPercent = ($fieldStatus === 'GROWING' && $maxGs > 0)
        ? (int)min(100, max(0, round($gs / $maxGs * 100)))
        : 0;

    $weed  = max(0, (int)($lf['weedState']  ?? 0));
    $spray = max(0, (int)($lf['sprayLevel'] ?? 0));
    $lime  = max(0, (int)($lf['limeLevel']  ?? 0));
    $plow  = max(0, (int)($lf['plowLevel']  ?? 0));
    $ft    = strtoupper((string)($lf['fruitType'] ?? 'NONE'));

    $percentages = array_merge([
        'ready' => 0.0,
        'growing' => 0.0,
        'harvested' => 0.0,
        'tilled' => 0.0,
        'withered' => 0.0,
        'fallow' => 0.0,
    ], is_array($lf['statusPercentages'] ?? null) ? $lf['statusPercentages'] : []);
    foreach ($percentages as $key => $value) {
        $percentages[$key] = round(max(0.0, min(100.0, (float)$value)), 1);
    }

    $steps = [];
    switch ($fieldStatus) {
        case 'READY':
            $steps[] = 'Ernten';
            break;
        case 'GROWING':
            if ($spray < 2) $steps[] = 'Düngen';
            if ($weed >= 5) $steps[] = 'Unkraut entfernen';
            break;
        case 'HARVESTED':
            $steps[] = 'Boden bearbeiten';
            break;
        case 'TILLED':
            if ($lime < 3) $steps[] = 'Kalken';
            $steps[] = 'Säen';
            break;
        case 'MIXED':
            if ($percentages['ready'] > 0) {
                $steps[] = 'Ernte auf Restfläche abschließen';
            }
            if ($percentages['harvested'] > 0) {
                $steps[] = 'Bodenbearbeitung abschließen';
            }
            if (empty($steps)) {
                $steps[] = 'Teilflächen prüfen';
            }
            break;
        case 'WITHERED':
            $steps[] = 'Bestand räumen';
            break;
        case 'FALLOW':
            if ($lime < 3) $steps[] = 'Kalken';
            $steps[] = 'Säen';
            break;
    }

    $fields[] = [
        'id'                 => (int)($lf['id'] ?? 0),
        'farmId'             => (int)($lf['farmId'] ?? 0),
        'farmlandId'         => (int)($lf['farmlandId'] ?? 0),
        'area'               => (float)($lf['area'] ?? 0),
        'fieldStatus'        => $fieldStatus,
        'statusLabel'        => $statusLabel,
        'statusPercentages'  => $percentages,
        'sampleCount'        => (int)($lf['sampleCount'] ?? 0),
        'fruitType'          => $ft,
        'fruitTypeLabel'     => in_array($ft, ['NONE', 'UNKNOWN'], true)
                                ? null
                                : ($lf['fruitTitle'] ?? fruit_type_label($ft)),
        'growthName'         => (string)($lf['growthName'] ?? ''),
        'maxGrowthState'     => $maxGs,
        'growthState'        => $gs,
        'growthPercent'      => $growthPercent,
        'groundType'         => $groundType,
        'weedState'          => $weed,
        'weedPercent'        => (int)min(100, round($weed / 9 * 100)),
        'sprayLevel'         => $spray,
        'sprayPercent'       => (int)min(100, round($spray / 2 * 100)),
        'limeLevel'          => $lime,
        'limePercent'        => (int)min(100, round($lime / 3 * 100)),
        'plowLevel'          => $plow,
        'stoneLevel'         => (int)($lf['stoneLevel'] ?? 0),
        'rollerLevel'        => (int)($lf['rollerLevel'] ?? 0),
        'stubbleShredLevel'  => (int)($lf['stubbleShredLevel'] ?? 0),
        'waterLevel'         => (int)($lf['waterLevel'] ?? 0),
        'steps'              => $steps,
        'liveSource'         => true,
    ];
}

usort($fields, fn($a, $b) => $a['id'] <=> $b['id']);
echo json_encode([
    'fields' => $fields,
    'fileAgeSeconds' => $liveData['fileAgeSeconds'] ?? 0,
    'timestamp' => $liveData['timestamp'] ?? null,
    'source' => 'lua-live',
]);
}

function handle_vehicles_data(): void
{
$liveData = get_live_mod_data();
if (($liveData['status'] ?? 'error') === 'no_mod') {
    echo json_encode(['error' => 'Mod nicht aktiv. FS25_HofDashboard aktivieren und Spiel starten.']);
    exit;
}

$liveVehicles = is_array($liveData['vehicles'] ?? null) ? $liveData['vehicles'] : [];
$playerFarmId = (int)($liveData['farm']['farmId'] ?? 0);
if ($playerFarmId > 0) {
    $liveVehicles = array_values(array_filter(
        $liveVehicles,
        fn($vehicle) => (int)($vehicle['farmId'] ?? 0) === $playerFarmId
    ));
}

$validCategories = ['VEHICLE' => true, 'TRAILER' => true, 'IMPLEMENT' => true];
$vehicles = [];

foreach ($liveVehicles as $lv) {
    $category = strtoupper((string)($lv['vehicleCategory'] ?? $lv['vehicleType'] ?? 'IMPLEMENT'));
    if (!isset($validCategories[$category])) $category = 'IMPLEMENT';

    $fillUnitsRaw = is_array($lv['fillUnits'] ?? null) ? $lv['fillUnits'] : [];

    // Übergangskompatibilität zu Mod 4.1: weiterhin ausschließlich Live-Daten,
    // aber alte fuel/cargo-Arrays werden einmalig als FillUnits normalisiert.
    if (empty($fillUnitsRaw)) {
        foreach (($lv['fuel'] ?? []) as $fill) {
            $fillUnitsRaw[] = array_merge($fill, ['kind' => 'FUEL', 'title' => $fill['label'] ?? $fill['title'] ?? $fill['fillType'] ?? 'Kraftstoff']);
        }
        foreach (($lv['cargo'] ?? []) as $fill) {
            $fillUnitsRaw[] = array_merge($fill, ['kind' => 'CARGO', 'title' => $fill['title'] ?? $fill['label'] ?? $fill['fillType'] ?? 'Ladung']);
        }
    }

    $fillUnits = [];
    foreach ($fillUnitsRaw as $fu) {
        $capacity = max(0.0, (float)($fu['capacity'] ?? 0));
        $liters   = max(0.0, (float)($fu['liters'] ?? 0));
        $percent  = $capacity > 0
            ? (int)min(100, max(0, round($liters / $capacity * 100)))
            : (int)min(100, max(0, (int)($fu['percent'] ?? 0)));
        $kind = strtoupper((string)($fu['kind'] ?? 'CARGO')) === 'FUEL' ? 'FUEL' : 'CARGO';
        $supported = is_array($fu['supportedFillTypes'] ?? null) ? array_values($fu['supportedFillTypes']) : [];

        $fillUnits[] = [
            'index' => (int)($fu['index'] ?? 0),
            'kind' => $kind,
            'fillType' => strtoupper((string)($fu['fillType'] ?? 'UNKNOWN')),
            'title' => (string)($fu['title'] ?? $fu['label'] ?? 'Leer'),
            'liters' => round($liters, 1),
            'capacity' => round($capacity, 1),
            'percent' => $percent,
            'supportedFillTypes' => $supported,
        ];
    }

    $shopPrice = max(0, (int)round((float)($lv['shopPrice'] ?? $lv['price'] ?? 0)));
    $wear = min(1.0, max(0.0, (float)($lv['wear'] ?? 0)));
    $dirt = min(1.0, max(0.0, (float)($lv['dirt'] ?? 0)));

    $vehicles[] = [
        'uniqueId' => (string)($lv['uniqueId'] ?? ''),
        'farmId' => (int)($lv['farmId'] ?? 0),
        'vehicleType' => $category,
        'vehicleCategory' => $category,
        'typeName' => (string)($lv['typeName'] ?? ''),
        'brand' => (string)($lv['brand'] ?? ''),
        'model' => (string)($lv['model'] ?? ''),
        'name' => (string)($lv['name'] ?? $lv['model'] ?? 'Unbekannt'),
        'shopPrice' => $shopPrice,
        'price' => $shopPrice,
        'operatingHours' => round(max(0.0, (float)($lv['operatingHours'] ?? 0)), 1),
        'wear' => $wear,
        'dirt' => $dirt,
        'isWorking' => (bool)($lv['isWorking'] ?? false),
        'fillUnits' => $fillUnits,
        'liveSource' => true,
    ];
}

usort($vehicles, function ($a, $b) {
    $order = ['VEHICLE' => 0, 'TRAILER' => 1, 'IMPLEMENT' => 2];
    $typeCmp = ($order[$a['vehicleType']] ?? 9) <=> ($order[$b['vehicleType']] ?? 9);
    if ($typeCmp !== 0) return $typeCmp;
    return strnatcasecmp($a['name'], $b['name']);
});

$totalDiesel = 0.0;
$totalAdBlue = 0.0;
$categoryCounts = ['VEHICLE' => 0, 'TRAILER' => 0, 'IMPLEMENT' => 0];
foreach ($vehicles as $vehicle) {
    $categoryCounts[$vehicle['vehicleType']]++;
    foreach ($vehicle['fillUnits'] as $fillUnit) {
        if ($fillUnit['kind'] !== 'FUEL') continue;
        if ($fillUnit['fillType'] === 'DIESEL') $totalDiesel += $fillUnit['liters'];
        if ($fillUnit['fillType'] === 'DEF') $totalAdBlue += $fillUnit['liters'];
    }
}

echo json_encode([
    'vehicles' => $vehicles,
    'totalCount' => count($vehicles),
    'categoryCounts' => $categoryCounts,
    'totalShopValue' => array_sum(array_column($vehicles, 'shopPrice')),
    'totalValue' => array_sum(array_column($vehicles, 'shopPrice')), // Kompatibilitätsalias
    'needsRepairCount' => count(array_filter($vehicles, fn($v) => $v['wear'] > 0.5)),
    'needsWashCount' => count(array_filter($vehicles, fn($v) => $v['dirt'] > 0.5)),
    'totalDieselLiters' => round($totalDiesel, 1),
    'totalAdBlueLiters' => round($totalAdBlue, 1),
    'liveStatus' => $liveData['status'] ?? 'unknown',
    'fileAgeSeconds' => $liveData['fileAgeSeconds'] ?? 0,
    'timestamp' => $liveData['timestamp'] ?? null,
    'diagnostics' => $liveData['vehicleDiagnostics'] ?? null,
    'source' => 'lua-live',
]);
}

function handle_animals_data(): void
{
$liveData = get_live_mod_data();

if (($liveData['status'] ?? 'error') === 'no_mod') {
    echo json_encode(['error' => 'Mod nicht aktiv. FS25_HofDashboard aktivieren und Spiel starten.']);
    exit;
}
if (($liveData['status'] ?? 'error') === 'error') {
    echo json_encode(['error' => $liveData['message'] ?? 'Live-Tierdaten konnten nicht gelesen werden.']);
    exit;
}

$playerFarmId = (int)($liveData['farm']['farmId'] ?? 0);
$husbandries = is_array($liveData['animals'] ?? null) ? array_values($liveData['animals']) : [];
if ($playerFarmId > 0) {
    $husbandries = array_values(array_filter(
        $husbandries,
        fn($barn) => (int)($barn['farmId'] ?? 0) === $playerFarmId
    ));
}

$clampFactor = static fn($value): float => round(max(0.0, min(1.0, (float)$value)), 3);
$clampPercent = static fn($value): int => (int)max(0, min(100, round((float)$value)));
$normalized = [];

foreach ($husbandries as $barn) {
    $clusters = [];
    foreach (($barn['clusters'] ?? []) as $cluster) {
        $clusters[] = [
            'subTypeIndex' => (int)($cluster['subTypeIndex'] ?? 0),
            'subType' => (string)($cluster['subType'] ?? ''),
            'breedTitle' => (string)($cluster['breedTitle'] ?? $cluster['subType'] ?? 'Unbekannt'),
            'ageMonths' => round(max(0.0, (float)($cluster['ageMonths'] ?? 0)), 1),
            'numAnimals' => max(0, (int)($cluster['numAnimals'] ?? 0)),
            'health' => $clampFactor($cluster['health'] ?? 0),
            'reproduction' => $clampFactor($cluster['reproduction'] ?? 0),
            'isPregnant' => (bool)($cluster['isPregnant'] ?? false),
            'isParent' => (bool)($cluster['isParent'] ?? false),
        ];
    }

    $normalizeResource = static function($resource) use ($clampPercent): array {
        $resource = is_array($resource) ? $resource : [];
        return array_merge($resource, [
            'enabled' => (bool)($resource['enabled'] ?? false),
            'level' => round(max(0.0, (float)($resource['level'] ?? 0)), 1),
            'capacity' => round(max(0.0, (float)($resource['capacity'] ?? 0)), 1),
            'percent' => $clampPercent($resource['percent'] ?? 0),
        ]);
    };

    $food = $normalizeResource($barn['food'] ?? []);
    $food['fillTypes'] = is_array($food['fillTypes'] ?? null) ? array_values($food['fillTypes']) : [];
    $food['groups'] = is_array($food['groups'] ?? null) ? array_values($food['groups']) : [];
    $water = $normalizeResource($barn['water'] ?? []);
    $water['automatic'] = (bool)($water['automatic'] ?? false);
    $water['litersPerHour'] = round(max(0.0, (float)($water['litersPerHour'] ?? 0)), 2);
    $straw = $normalizeResource($barn['straw'] ?? []);
    $straw['litersPerHour'] = round(max(0.0, (float)($straw['litersPerHour'] ?? 0)), 2);
    $meadow = $normalizeResource($barn['meadow'] ?? []);
    $meadow['fillTypes'] = is_array($meadow['fillTypes'] ?? null) ? array_values($meadow['fillTypes']) : [];

    $outputs = [];
    foreach (($barn['outputs'] ?? []) as $output) {
        $capacity = max(0.0, (float)($output['capacity'] ?? 0));
        $level = max(0.0, (float)($output['level'] ?? 0));
        $outputs[] = [
            'kind' => strtoupper((string)($output['kind'] ?? 'PRODUCT')),
            'fillType' => strtoupper((string)($output['fillType'] ?? 'UNKNOWN')),
            'title' => (string)($output['title'] ?? $output['fillType'] ?? 'Produkt'),
            'level' => round($level, 1),
            'capacity' => round($capacity, 1),
            'percent' => $capacity > 0 ? (int)min(100, max(0, round($level / $capacity * 100))) : 0,
            'pendingLiters' => round(max(0.0, (float)($output['pendingLiters'] ?? 0)), 1),
            'litersPerHour' => round(max(0.0, (float)($output['litersPerHour'] ?? 0)), 2),
            'palletLimitReached' => (bool)($output['palletLimitReached'] ?? false),
        ];
    }

    $normalized[] = [
        'uniqueId' => (string)($barn['uniqueId'] ?? ''),
        'name' => (string)($barn['name'] ?? 'Tierhaltung'),
        'farmId' => (int)($barn['farmId'] ?? 0),
        'animalType' => strtoupper((string)($barn['animalType'] ?? 'UNKNOWN')),
        'animalTypeIndex' => (int)($barn['animalTypeIndex'] ?? 0),
        'totalAnimals' => max(0, (int)($barn['totalAnimals'] ?? 0)),
        'maxAnimals' => max(0, (int)($barn['maxAnimals'] ?? 0)),
        'freeSlots' => max(0, (int)($barn['freeSlots'] ?? 0)),
        'occupancyPercent' => $clampPercent($barn['occupancyPercent'] ?? 0),
        'productivity' => $clampFactor($barn['productivity'] ?? 0),
        'health' => $clampFactor($barn['health'] ?? 0),
        'reproduction' => $clampFactor($barn['reproduction'] ?? 0),
        'clusters' => $clusters,
        'food' => $food,
        'water' => $water,
        'straw' => $straw,
        'meadow' => $meadow,
        'outputs' => $outputs,
        'liveSource' => true,
    ];
}

usort($normalized, static function(array $a, array $b): int {
    $countCmp = $b['totalAnimals'] <=> $a['totalAnimals'];
    return $countCmp !== 0 ? $countCmp : strnatcasecmp($a['name'], $b['name']);
});

$beehivesRaw = is_array($liveData['beehives'] ?? null) ? $liveData['beehives'] : [];
$beehives = [
    'hiveCount' => max(0, (int)($beehivesRaw['hiveCount'] ?? 0)),
    'activeHiveCount' => max(0, (int)($beehivesRaw['activeHiveCount'] ?? 0)),
    'honeyLitersPerHour' => round(max(0.0, (float)($beehivesRaw['honeyLitersPerHour'] ?? 0)), 2),
    'pendingHoneyLiters' => round(max(0.0, (float)($beehivesRaw['pendingHoneyLiters'] ?? 0)), 1),
    'finishedPallets' => max(0, (int)($beehivesRaw['finishedPallets'] ?? 0)),
    'honeyOnPalletsLiters' => round(max(0.0, (float)($beehivesRaw['honeyOnPalletsLiters'] ?? 0)), 1),
    'hasSpawner' => (bool)($beehivesRaw['hasSpawner'] ?? false),
    'palletLimitReached' => (bool)($beehivesRaw['palletLimitReached'] ?? false),
    'hives' => is_array($beehivesRaw['hives'] ?? null) ? array_values($beehivesRaw['hives']) : [],
];

echo json_encode([
    'husbandries' => $normalized,
    'barnCount' => count($normalized),
    'totalAnimals' => array_sum(array_column($normalized, 'totalAnimals')),
    'beehives' => $beehives,
    'diagnostics' => $liveData['animalDiagnostics'] ?? null,
    'source' => 'lua-live',
    'modVersion' => $liveData['version'] ?? '',
    'liveStatus' => $liveData['status'] ?? 'unknown',
    'liveAge' => $liveData['fileAgeSeconds'] ?? 0,
    'timestamp' => $liveData['timestamp'] ?? null,
]);
}

function handle_production_data(): void
{
$liveData = get_live_mod_data();

if ($liveData['status'] === 'no_mod') {
    echo json_encode(['error' => 'Mod nicht aktiv.']);
    exit;
}

$liveProds = is_array($liveData['productions'] ?? null)
    ? $liveData['productions']
    : [];
$points = normalize_live_production_points($liveProds);

echo json_encode([
    'productionPoints' => $points,
    'pointCount'       => count($points),
    'liveAge'          => $liveData['fileAgeSeconds'] ?? 0,
]);
}

function handle_market_data(): void
{
$liveData = get_live_mod_data();

if (($liveData['status'] ?? '') === 'no_mod') {
    echo json_encode(['error' => 'Mod nicht aktiv.']);
    exit;
}
if (($liveData['status'] ?? '') === 'error') {
    echo json_encode(['error' => $liveData['message'] ?? 'Live-Daten konnten nicht gelesen werden.']);
    exit;
}

$ownCrops = [];
foreach (($liveData['fields'] ?? []) as $field) {
    $fruitType = strtoupper((string)($field['fruitType'] ?? ''));
    if ($fruitType !== '' && $fruitType !== 'NONE' && $fruitType !== 'UNKNOWN') {
        $ownCrops[$fruitType] = true;
    }
}

$market = [];
foreach (($liveData['market'] ?? []) as $m) {
    $ft = strtoupper((string)($m['fillType'] ?? ''));
    if ($ft === '') continue;

    $stations = [];
    foreach (($m['stations'] ?? []) as $station) {
        $price = (float)($station['pricePer1000L'] ?? 0);
        if ($price <= 0) continue;
        $stations[] = [
            'name'  => (string)($station['name'] ?? 'Verkaufsstation'),
            'price' => (int)round($price),
        ];
    }

    usort($stations, static function(array $a, array $b): int {
        $priceCmp = $b['price'] <=> $a['price'];
        return $priceCmp !== 0 ? $priceCmp : strcasecmp($a['name'], $b['name']);
    });

    $currentPrice = $stations[0]['price'] ?? (int)round((float)($m['bestPrice'] ?? $m['pricePerTon'] ?? 0));
    if ($currentPrice <= 0) continue;

    $bestStation = $stations[0]['name'] ?? (string)($m['bestStation'] ?? '');
    $minPrice = $stations ? min(array_column($stations, 'price')) : $currentPrice;
    $maxPrice = $stations ? max(array_column($stations, 'price')) : $currentPrice;

    $market[] = [
        'fruitType'       => $ft,
        'label'           => (string)($m['title'] ?? $ft),
        'category'        => (string)($m['category'] ?? 'product'),
        'unit'            => '1000L',
        'currentPrice'    => $currentPrice,
        'bestPrice'       => $currentPrice,
        'bestStation'     => $bestStation,
        'stationCount'    => count($stations),
        'stations'        => $stations,
        'minPrice'        => $minPrice,
        'maxPrice'        => $maxPrice,
        'priceSpread'     => max(0, $maxPrice - $minPrice),
        'basePricePerTon' => (int)round((float)($m['basePriceTon'] ?? 0)),
        'isOwnCrop'       => isset($ownCrops[$ft]),
    ];
}

usort($market, static function(array $a, array $b): int {
    $priceCmp = $b['currentPrice'] <=> $a['currentPrice'];
    return $priceCmp !== 0 ? $priceCmp : strcasecmp($a['label'], $b['label']);
});

$periodLabel = 'Unbekannt';
$currentDayLive = (int)($liveData['currentDay'] ?? 0);
$daysPerPeriodLive = (int)($liveData['daysPerPeriod'] ?? 0);
if ($currentDayLive > 0 && $daysPerPeriodLive > 0) {
    $pidx = get_current_period_index($currentDayLive, $daysPerPeriodLive);
    $periodLabel = MARKET_PERIOD_LABELS_DE[MARKET_PERIOD_ORDER[$pidx]] ?? 'Unbekannt';
}

echo json_encode([
    'source'             => 'lua-live-stations',
    'modVersion'         => $liveData['version'] ?? '',
    'currentPeriodLabel' => $periodLabel,
    'market'             => $market,
    'liveAge'            => $liveData['fileAgeSeconds'] ?? 0,
]);
}

function handle_missions_data(): void
{
$liveData = get_live_mod_data();

if ($liveData['status'] === 'no_mod') {
    echo json_encode(['error' => 'Mod nicht aktiv.']);
    exit;
}

$liveContracts = $liveData['contracts'] ?? [];

// Vertragstyp-Klasse → deutsches Label
$TYPE_LABELS = [
    'HarvestMission'     => 'Ernten',
    'SowMission'         => 'Säen',
    'PlowMission'        => 'Pflügen',
    'CultivationMission' => 'Grubbern',
    'FertilizingMission' => 'Düngen',
    'HerbicideMission'   => 'Herbizid',
    'MowMission'         => 'Mähen',
    'WeedMission'        => 'Hacken',
    'BaleCloseMission'   => 'Ballen pressen',
    'TransportMission'   => 'Transport',
    'StonePickMission'   => 'Steine sammeln',
    'LimeMission'        => 'Kalken',
    'FieldMission'       => 'Feldarbeit',
    'DeadwoodMission'    => 'Totholz',
];

$missions = array_map(fn($lc) => [
    'type'      => $lc['type']     ?? '',
    'typeLabel' => $TYPE_LABELS[$lc['type'] ?? ''] ?? ($lc['title'] ?: ($lc['type'] ?? 'Auftrag')),
    'title'     => $lc['title']    ?? '',
    'detail'    => $lc['title']    ?? '',  // detail = title aus getTitle()
    'reward'    => (int)($lc['reward']    ?? 0),
    'fieldId'   => (int)($lc['fieldId']   ?? 0),
    'farmId'    => (int)($lc['farmId']    ?? 0),
    'isActive'  => (bool)($lc['isActive'] ?? false),
    'progress'  => (int)($lc['progress']  ?? 0),
    'fieldCrop' => '',
], $liveContracts);

echo json_encode([
    'missions'    => $missions,
    'currentDay'  => 0,
    'liveAge'     => $liveData['fileAgeSeconds'] ?? 0,
]);
}

function handle_live_data(): void
{
echo json_encode(get_live_mod_data());
}
