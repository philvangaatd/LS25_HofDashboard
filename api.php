<?php
// Ohne explizite Zeitzone verwendet PHP je nach Serverkonfiguration UTC, wodurch
// Backup-Zeitstempel von der tatsächlichen lokalen Zeit abweichen können.
date_default_timezone_set('Europe/Berlin');

require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function get_config_path_for_folder(string $folder): ?string {
    // Sicherheitscheck: nur "savegameN" erlauben, kein Path-Traversal möglich
    if (!preg_match('/^savegame\d+$/', $folder)) return null;
    $path = FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . 'AutoDrive_config.xml';
    return file_exists($path) ? $path : null;
}

// Allgemeine Gültigkeitsprüfung eines Spielstands (unabhängig von AutoDrive) –
// da das Tool inzwischen mehr als nur AutoDrive abdeckt, muss jeder gespielte
// Spielstand auswählbar sein, auch ohne AutoDrive-Daten.
function get_general_savegame_dir(string $folder): ?string {
    if (!preg_match('/^savegame\d+$/', $folder)) return null;
    $dir = FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder;
    return file_exists($dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml') ? $dir : null;
}

function get_selected_config_path(): ?string {
    if (empty($_SESSION['savegame_folder'])) return null;
    return get_config_path_for_folder($_SESSION['savegame_folder']);
}

function load_dom(string $path): DOMDocument {
    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    libxml_use_internal_errors(true);
    $ok = $dom->load($path, LIBXML_PARSEHUGE);
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['error' => 'XML konnte nicht geparst werden.']);
        exit;
    }
    return $dom;
}

function get_valid_waypoint_ids(DOMDocument $dom): array {
    $idNode = $dom->getElementsByTagName('id')->item(0);
    if (!$idNode) return [];
    return array_flip(explode(',', trim($idNode->textContent)));
}

function get_farm_info(string $savegameDir): array {
    $info = ['farmName' => '', 'manager' => '', 'money' => null, 'loan' => null, 'farmId' => null];
    $farmsFile = $savegameDir . DIRECTORY_SEPARATOR . 'farms.xml';
    if (!file_exists($farmsFile)) return $info;

    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($farmsFile);
    if (!$xml || !isset($xml->farm)) return $info;

    // Erster echter Hof (farmId != 0, das ist die Umwelt/Vorbesitzer-Sammelfarm)
    foreach ($xml->farm as $farm) {
        $farmId = (string)$farm['farmId'];
        if ($farmId === '0') continue;
        $info['farmName'] = (string)$farm['name'];
        $info['farmId'] = $farmId;
        $info['money'] = isset($farm['money']) ? (float)$farm['money'] : null;
        $info['loan'] = isset($farm['loan']) ? (float)$farm['loan'] : null;
        if (isset($farm->players->player)) {
            foreach ($farm->players->player as $player) {
                if ((string)$player['farmManager'] === 'true') {
                    $info['manager'] = (string)$player['lastNickname'];
                    break;
                }
            }
        }
        break;
    }
    return $info;
}

function get_current_season(string $savegameDir, int $currentDay): string {
    $envFile = $savegameDir . DIRECTORY_SEPARATOR . 'environment.xml';
    if (!file_exists($envFile)) return '';
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($envFile);
    if (!$xml || !isset($xml->weather->forecast->instance)) return '';

    $bestSeason = '';
    $bestDay = -1;
    foreach ($xml->weather->forecast->instance as $inst) {
        $startDay = (int)$inst['startDay'];
        if ($startDay <= $currentDay && $startDay > $bestDay) {
            $bestDay = $startDay;
            $bestSeason = (string)$inst['season'];
        }
    }
    return $bestSeason;
}

// Fasst die Wettervorhersage aus environment.xml (mehrere Instanzen pro Tag) zu
// einer Zusammenfassung je Kalendertag zusammen: dominanter Wettertyp (nach
// Gesamtdauer) plus Hinweis, ob an dem Tag überhaupt Niederschlag vorkommt.
const WEATHER_TYPE_ICONS = [
    'SUN' => '☀️',
    'CLOUDY' => '☁️',
    'RAIN' => '🌧️',
    'SNOW' => '❄️',
    'HAIL' => '🌨️',
    'FOG' => '🌫️',
];

function get_weather_forecast(string $savegameDir, int $currentDay, int $daysAhead = 5): array {
    $envFile = $savegameDir . DIRECTORY_SEPARATOR . 'environment.xml';
    if (!file_exists($envFile)) return [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($envFile);
    if (!$xml || !isset($xml->weather->forecast->instance)) return [];

    $byDay = [];
    foreach ($xml->weather->forecast->instance as $inst) {
        $day = (int)$inst['startDay'];
        if ($day < $currentDay || $day > $currentDay + $daysAhead - 1) continue;
        $type = (string)$inst['typeName'];
        $duration = (float)$inst['duration'];
        if (!isset($byDay[$day])) {
            $byDay[$day] = ['durations' => [], 'season' => (string)$inst['season'], 'hasPrecipitation' => false];
        }
        $byDay[$day]['durations'][$type] = ($byDay[$day]['durations'][$type] ?? 0) + $duration;
        if (in_array($type, ['RAIN', 'SNOW', 'HAIL'], true)) {
            $byDay[$day]['hasPrecipitation'] = true;
        }
    }

    ksort($byDay);
    $result = [];
    foreach ($byDay as $day => $data) {
        arsort($data['durations']);
        $dominantType = array_key_first($data['durations']);
        $result[] = [
            'day' => $day,
            'season' => $data['season'],
            'dominantType' => $dominantType,
            'dominantTypeIcon' => WEATHER_TYPE_ICONS[$dominantType] ?? '🌡️',
            'hasPrecipitation' => $data['hasPrecipitation'],
        ];
    }
    return $result;
}

function count_vehicles_for_farm(string $savegameDir, string $farmId): int {
    $file = $savegameDir . DIRECTORY_SEPARATOR . 'vehicles.xml';
    if (!file_exists($file)) return 0;
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file);
    if (!$xml) return 0;
    $count = 0;
    foreach ($xml->vehicle as $v) {
        if ((string)$v['farmId'] === $farmId) $count++;
    }
    return $count;
}

// Maximale Wachstumsstufe je Fruchtart, direkt aus den Foliage-Definitionsdateien des
// Spiels ausgezählt (Kommentarblock mit den einzelnen Wachstumsstufen je Fruchtart, z. B.
// data/foliage/wheat/wheat.xml – dort steht buchstäblich "invisible / green small / ... /
// harvest ready" mit fortlaufender Nummerierung). VERIFIZIERT an echten Spieldateien:
// WHEAT=7, BARLEY=6, CANOLA=8, OAT=4, MAIZE=6, SUNFLOWER=7, SOYBEAN=6, POTATO=5,
// SUGARBEET=7, PARSNIP=4, GRASS=3. Für alle anderen Fruchtarten liegt noch keine
// Verifikation an der echten Datei vor – dort wird ein plausibler Näherungswert (8,
// das häufigste beobachtete Maximum) verwendet, bis sie einzeln nachgeprüft sind.
const FRUIT_TYPE_MAX_GROWTH_STATE = [
    'WHEAT' => 7, 'WINTERWHEAT' => 7,
    'BARLEY' => 6, 'WINTERBARLEY' => 6,
    'CANOLA' => 8, 'WINTERCANOLA' => 8,
    'OAT' => 4,
    'MAIZE' => 6,
    'SILAGEMAIZE' => 4, // wird grün/früher geerntet als Körnermais (siehe Kommentar in maize.xml)
    'SUNFLOWER' => 7,
    'SOYBEAN' => 6,
    'POTATO' => 5,
    'SUGARBEET' => 7, 'BEETROOT' => 7,
    'PARSNIP' => 4,
    'GRASS' => 3, 'DRYGRASS' => 3, 'ALFALFA' => 3,
    'RYE' => 6, 'WINTERRYE' => 6, 'TRITICALE' => 6, 'SPELT' => 6, 'MILLET' => 6,
];
const FRUIT_TYPE_MAX_GROWTH_STATE_DEFAULT = 8;

function fruit_max_growth_state(string $fruitType): int {
    return FRUIT_TYPE_MAX_GROWTH_STATE[$fruitType] ?? FRUIT_TYPE_MAX_GROWTH_STATE_DEFAULT;
}

// Vereinfachte, dreiteilige Statusanzeige statt der 16 internen groundType-Rohwerte –
// entspricht dem, was im Spiel grob unterschieden wird: Boden bearbeitet (kein Bewuchs),
// Kultur gesät/wächst, oder erntereif.
const GROUND_STATUS_TILLED = 'TILLED';
const GROUND_STATUS_SOWN = 'SOWN_GROWING';
const GROUND_STATUS_READY = 'READY';

const GROUND_TYPE_TO_STATUS = [
    'PLOWED' => GROUND_STATUS_TILLED, 'CULTIVATED' => GROUND_STATUS_TILLED,
    'SEEDBED' => GROUND_STATUS_TILLED, 'ROLLED_SEEDBED' => GROUND_STATUS_TILLED,
    'ROLLER_LINES' => GROUND_STATUS_TILLED, 'STUBBLE_TILLAGE' => GROUND_STATUS_TILLED,
    'RIDGE' => GROUND_STATUS_TILLED, 'NONE' => GROUND_STATUS_TILLED, 'GRASS_CUT' => GROUND_STATUS_TILLED,
    'SOWN' => GROUND_STATUS_SOWN, 'DIRECT_SOWN' => GROUND_STATUS_SOWN,
    'RIDGE_SOWN' => GROUND_STATUS_SOWN, 'PLANTED' => GROUND_STATUS_SOWN, 'GRASS' => GROUND_STATUS_SOWN,
    'HARVEST_READY' => GROUND_STATUS_READY, 'HARVEST_READY_OTHER' => GROUND_STATUS_READY,
];

const GROUND_STATUS_LABELS = [
    GROUND_STATUS_TILLED => 'Gepflügt',
    GROUND_STATUS_SOWN => 'Gesät',
    GROUND_STATUS_READY => 'Erntereif',
];

function ground_status_for(string $groundType): string {
    return GROUND_TYPE_TO_STATUS[$groundType] ?? GROUND_STATUS_TILLED;
}

// Referenzwerte für die Fortschrittsbalken (Kalk/Düngen/Unkraut). Kalk (0–3) und Düngen
// (0–2) entsprechen der bereits zuvor im Tool verwendeten Schwelle ("limeLevel < 3" /
// "sprayLevel < 2" galt schon immer als "fertig"). Unkraut (0–9) stammt aus
// maps_weed.xml: dort sind Kartenfarben/Faktoren bis Zustand 9 definiert, verifizierte
// echte Spielstand-Werte gingen bereits bis 7.
const FIELD_LIME_MAX = 3;
const FIELD_SPRAY_MAX = 2;
const FIELD_WEED_MAX = 9;

function parse_fields(string $savegameDir): array {
    $file = $savegameDir . DIRECTORY_SEPARATOR . 'fields.xml';
    if (!file_exists($file)) return [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file);
    if (!$xml) return [];

    $result = [];
    foreach ($xml->field as $f) {
        $fruitType = (string)$f['fruitType'];
        $groundType = (string)$f['groundType'];
        $growthState = (int)$f['growthState'];
        $weedState = (int)$f['weedState'];
        $sprayLevel = (int)$f['sprayLevel'];
        $limeLevel = (int)$f['limeLevel'];
        $maxGrowth = fruit_max_growth_state($fruitType);

        $result[] = [
            'id' => (string)$f['id'],
            'fruitType' => $fruitType,
            'plannedFruit' => (string)$f['plannedFruit'],
            'growthState' => $growthState,
            'maxGrowthState' => $maxGrowth,
            'growthPercent' => $maxGrowth > 0 ? round(min(100, $growthState / $maxGrowth * 100)) : 0,
            'groundType' => $groundType,
            'groundStatus' => ground_status_for($groundType),
            'weedState' => $weedState,
            'weedPercent' => round(min(100, $weedState / FIELD_WEED_MAX * 100)),
            'stoneLevel' => (int)$f['stoneLevel'],
            'sprayLevel' => $sprayLevel,
            'sprayPercent' => round(min(100, $sprayLevel / FIELD_SPRAY_MAX * 100)),
            'sprayType' => (string)$f['sprayType'],
            'limeLevel' => $limeLevel,
            'limePercent' => round(min(100, $limeLevel / FIELD_LIME_MAX * 100)),
            'plowLevel' => (int)$f['plowLevel'],
            'stubbleShredLevel' => (int)$f['stubbleShredLevel'],
        ];
    }
    return $result;
}

// Liefert die Feld-IDs, die tatsächlich im Besitz der angegebenen Farm sind.
// In FS25 entspricht die Flurstück-ID in farmland.xml auf den meisten Karten
// (so auch Gülzow) direkt der Feld-ID in fields.xml.
function get_owned_field_ids(string $savegameDir, string $farmId): array {
    $file = $savegameDir . DIRECTORY_SEPARATOR . 'farmland.xml';
    if (!file_exists($file)) return [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file);
    if (!$xml) return [];

    $owned = [];
    foreach ($xml->farmland as $fl) {
        if ((string)$fl['farmId'] === $farmId) {
            $owned[(string)$fl['id']] = true;
        }
    }
    return $owned;
}

// Deutsche Übersetzung der wichtigsten FS25-Fruchtarten für die Feld-Übersicht.
function fruit_type_label(string $fruitType): string {
    $map = [
        'WHEAT' => 'Weizen', 'BARLEY' => 'Gerste', 'CANOLA' => 'Raps', 'OAT' => 'Hafer',
        'MAIZE' => 'Mais', 'SILAGEMAIZE' => 'Silomais', 'SUNFLOWER' => 'Sonnenblumen',
        'SOYBEAN' => 'Sojabohnen', 'POTATO' => 'Kartoffeln', 'SUGARBEET' => 'Zuckerrüben',
        'SUGARCANE' => 'Zuckerrohr', 'COTTON' => 'Baumwolle', 'GRASS' => 'Gras',
        'DRYGRASS' => 'Heu', 'SPINACH' => 'Spinat', 'GREENBEAN' => 'Grüne Bohnen',
        'RICE' => 'Reis', 'PEA' => 'Erbsen', 'ONION' => 'Zwiebeln', 'CARROT' => 'Karotten',
        'PARSNIP' => 'Pastinaken', 'OILSEEDRADISH' => 'Ölrettich', 'RYE' => 'Roggen',
        'TRITICALE' => 'Triticale', 'GRAPE' => 'Weintrauben', 'OLIVE' => 'Oliven',
        'POPLAR' => 'Pappeln', 'WINTERWHEAT' => 'Winterweizen', 'WINTERBARLEY' => 'Wintergerste',
        'WINTERCANOLA' => 'Winterraps', 'WINTERRYE' => 'Winterroggen', 'SORGHUM' => 'Sorghum-Hirse',
        'MILLET' => 'Hirse', 'LETTUCE' => 'Kopfsalat', 'CABBAGE' => 'Kohl',
        'REDCABBAGE' => 'Rotkohl', 'BEETROOT' => 'Rote Bete', 'ALFALFA' => 'Luzerne',
        'SPELT' => 'Dinkel', 'FALLOW' => 'Brache', 'UNKNOWN' => 'Unbekannt',
        'SPRING_ONION' => 'Frühlingszwiebeln', 'TOMATO' => 'Tomaten', 'STRAWBERRY' => 'Erdbeeren',
        'CHILI' => 'Chili', 'CUCUMBER' => 'Gurken', 'PEPPER' => 'Paprika', 'EGGPLANT' => 'Auberginen',
        'BLUEBERRY' => 'Heidelbeeren', 'RASPBERRY' => 'Himbeeren', 'NAPACABBAGE' => 'Chinakohl',
    ];
    return $map[$fruitType] ?? $fruitType;
}

// Spritzmittel-Typ (sprayType) – aus dem offiziellen Schema (fields_savegame.xsd).
const SPRAY_TYPE_LABELS = [
    'FERTILIZER' => 'Dünger',
    'LIME' => 'Kalk',
    'LIQUID_MANURE' => 'Gülle',
    'MANURE' => 'Mist',
    'NONE' => 'Keins',
];

// Vorschlagsliste für die nächsten Arbeitsschritte, an drei Grundzuständen orientiert
// (siehe ground_status_for): Gepflügt -> Kalken/Säen, Gesät/wächst -> NUR Düngen und/oder
// Unkraut entfernen (kein Pflügen/Säen mehr, das ist bereits erledigt; keine
// "Ernten"-Vorhersage anhand der Wachstumsstufe, die Reife wird ausschließlich am
// Bodenzustand erkannt), Erntereif -> Ernten.
function suggest_field_steps(array $field, bool $limeRequired): array {
    $status = $field['groundStatus'];

    if ($status === GROUND_STATUS_READY) {
        return ['Ernten'];
    }

    if ($status === GROUND_STATUS_TILLED) {
        $steps = [];
        if ($limeRequired && $field['limeLevel'] < FIELD_LIME_MAX) {
            $steps[] = 'Kalken';
        }
        $steps[] = 'Säen';
        return $steps;
    }

    // GROUND_STATUS_SOWN: Kultur steht bereits und wächst.
    $steps = [];
    if ($field['sprayLevel'] < FIELD_SPRAY_MAX) {
        $steps[] = 'Düngen';
    }
    if ($field['weedState'] >= 5) {
        $steps[] = 'Unkraut entfernen';
    }
    return $steps;
}

// -----------------------------------------------------------------
// Fuhrpark
// -----------------------------------------------------------------
function readable_vehicle_name(string $filename): string {
    // Erwartetes Muster: data/vehicles/<marke>/<modellordner>/<datei>.xml
    $parts = explode('/', $filename);
    $brand = count($parts) >= 3 ? ucfirst($parts[2]) : '';
    $modelRaw = count($parts) >= 1 ? preg_replace('/\.xml$/i', '', end($parts)) : $filename;

    // camelCase / Ziffern grob in lesbare Wortgrenzen auftrennen (Heuristik, nicht perfekt)
    $model = preg_replace('/([a-z])([A-Z])/', '$1 $2', $modelRaw);
    $model = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $model);
    $model = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $model);
    $model = ucfirst(trim($model));

    return trim($brand . ' ' . $model);
}

function classify_vehicle_type(DOMElement $v): string {
    $childTags = [];
    foreach ($v->childNodes as $c) {
        if ($c->nodeType === XML_ELEMENT_NODE) $childTags[$c->nodeName] = true;
    }
    if (isset($childTags['drivable']) || isset($childTags['enterable'])) return 'VEHICLE';
    if (isset($childTags['trailer'])) return 'TRAILER';
    return 'IMPLEMENT';
}

// Echte Kraftstoffarten in <fillUnit>-Tanks (nicht ALLE fillType-Werte dort sind
// Kraftstoff – AIR ist Druckluft für Anbaugeräte, BALE_NET Ballennetz usw., die
// tauchen ebenfalls in fillUnit auf, sind aber kein Sprit).
const FUEL_TYPE_LABELS = [
    'DIESEL' => 'Diesel',
    'DEF' => 'AdBlue',
    'ELECTRICCHARGE' => 'Strom',
    'METHANE' => 'Methan',
];

// Nicht-Kraftstoff-Ladungen (Anhänger, Sämaschinen, Streugeräte, Mähdrescher-Tank
// usw.) – Erntegut selbst wird über fruit_type_label() abgedeckt, das hier deckt
// nur die restlichen gängigen Materialtypen ab, die keine Fruchtart sind.
const MATERIAL_TYPE_LABELS = [
    'LIME' => 'Kalk',
    'SEEDS' => 'Saatgut',
    'WATER' => 'Wasser',
    'MANURE' => 'Mist',
    'LIQUIDMANURE' => 'Gülle',
    'DIGESTATE' => 'Gärrest',
    'SILAGE' => 'Silage',
    'SILAGE_ADDITIVE' => 'Silagezusatz',
    'STONE' => 'Steine',
    'SAND' => 'Sand',
    'MILK' => 'Milch',
    'STRAW' => 'Stroh',
    'WOODCHIPS' => 'Hackschnitzel',
    'FERTILIZER' => 'Mineraldünger',
    'LIQUIDFERTILIZER' => 'Flüssigdünger',
    'HERBICIDE' => 'Herbizid',
    'EGG' => 'Eier',
    'WOOL' => 'Wolle',
    'HONEY' => 'Honig',
];

// Platzhalter/Nicht-Ladung-Typen, die in fillUnit auftauchen können, aber nicht als
// Ladung angezeigt werden sollen.
const NON_CARGO_FILL_TYPES = ['AIR', 'BALE_NET', 'UNKNOWN'];

function fill_type_label(string $t): string {
    if (isset(FUEL_TYPE_LABELS[$t])) return FUEL_TYPE_LABELS[$t];
    if (isset(MATERIAL_TYPE_LABELS[$t])) return MATERIAL_TYPE_LABELS[$t];
    $asFruit = fruit_type_label($t);
    if ($asFruit !== $t) return $asFruit;
    return ucfirst(strtolower($t));
}

// Tankkapazitäten stehen NICHT im Spielstand (nur der aktuelle Füllstand), sondern in der
// Fahrzeug-Modelldatei selbst – gleiches Prinzip wie bei der Kartengröße (siehe find_map_size).
// Verifiziert an echten Spieldateien: MF 8570 Getreidetank capacity="8000", Dieseltank
// capacity="378"; Bredal K105 Kalktank capacity="9000". Reihenfolge der <fillUnit>-Einträge
// in der ersten <fillUnitConfiguration> entspricht dem "index"-Attribut im Spielstand.
// Funktioniert nur, wenn die Modelldatei erreichbar ist (offizielles Fahrzeug im
// Installationsordner, oder Mod-Fahrzeug mit auffindbarer ZIP im mods-Ordner) – sonst wird
// einfach keine Kapazität geliefert und nur der reine Literwert angezeigt.
function find_vehicle_fill_capacities(string $filename): array {
    static $cache = [];
    if (isset($cache[$filename])) return $cache[$filename];

    $xmlContent = null;
    if (str_starts_with($filename, '$moddir$')) {
        $rest = substr($filename, strlen('$moddir$'));
        $slashPos = strpos($rest, '/');
        if ($slashPos !== false && class_exists('ZipArchive')) {
            $modName = substr($rest, 0, $slashPos);
            $innerPath = substr($rest, $slashPos + 1);
            $zipPath = FS_BASE_DIR . DIRECTORY_SEPARATOR . 'mods' . DIRECTORY_SEPARATOR . $modName . '.zip';
            if (file_exists($zipPath)) {
                $zip = new ZipArchive();
                if ($zip->open($zipPath) === true) {
                    $data = $zip->getFromName($innerPath);
                    $zip->close();
                    if ($data !== false) $xmlContent = $data;
                }
            }
        }
    } elseif (defined('FS_INSTALL_DIR') && FS_INSTALL_DIR !== '') {
        $path = FS_INSTALL_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $filename);
        if (file_exists($path)) {
            $xmlContent = @file_get_contents($path);
        }
    }

    $capacities = [];
    if ($xmlContent !== null) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        if ($xml) {
            // XPath statt starrem Objekt-Pfad, da die genaue Verschachtelung leicht variieren
            // kann; nimmt die erste fillUnitConfiguration (Standard-/Basiskonfiguration).
            $nodes = $xml->xpath('(//fillUnitConfigurations/fillUnitConfiguration)[1]/fillUnits/fillUnit');
            if ($nodes) {
                foreach ($nodes as $node) {
                    $capacities[] = isset($node['capacity']) ? (float)$node['capacity'] : null;
                }
            }
        }
    }

    $cache[$filename] = $capacities;
    return $capacities;
}

function parse_vehicles(string $savegameDir, string $farmId): array {
    $file = $savegameDir . DIRECTORY_SEPARATOR . 'vehicles.xml';
    if (!file_exists($file)) return [];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->load($file, LIBXML_PARSEHUGE);

    $result = [];
    foreach ($dom->getElementsByTagName('vehicle') as $v) {
        if ($v->getAttribute('farmId') !== $farmId) continue;

        // Paletten (Dünger-/Honig-/Eier-Boxen usw.) landen in vehicles.xml als ganz normale
        // <vehicle>-Einträge (data/objects/pallets/...), haben aber weder drivable/enterable
        // noch trailer-Kindtags und werden daher von classify_vehicle_type() faelschlich als
        // IMPLEMENT eingestuft und im Fuhrpark gelistet. Paletten gehören dort nicht hin.
        if (str_contains(strtolower($v->getAttribute('filename')), '/pallets/')) continue;

        // Der im Spiel angezeigte Verschleiß-/Schaden-Wert steht direkt als "damage"-Attribut
        // am <wearable>-Element. Die einzelnen <wearNode>-Kindelemente sind ein interner
        // Detailwert je Bauteil (z. B. für visuelle Kratzer) und entsprechen NICHT der im
        // Spiel gezeigten Prozentzahl – das hatte vorher zu falschen Werten geführt.
        $wearableNode = $v->getElementsByTagName('wearable')->item(0);
        $wear = $wearableNode ? (float)$wearableNode->getAttribute('damage') : 0.0;

        $dirtSum = 0.0; $dirtCount = 0;
        foreach ($v->getElementsByTagName('dirtNode') as $dn) {
            $dirtSum += (float)$dn->getAttribute('amount');
            $dirtCount++;
        }

        // Tankinhalt: <fillUnit><unit fillType="DIESEL" fillLevel="..."/></fillUnit>. Kraftstoff
        // und sonstige Ladung (Erntegut, Kalk, Saatgut usw.) stecken in derselben Struktur –
        // wir trennen sie hier in zwei Listen und ergänzen die Kapazität aus der Fahrzeug-
        // Modelldatei (siehe find_vehicle_fill_capacities), sofern erreichbar. Das "index"-
        // Attribut im Spielstand entspricht der Reihenfolge der fillUnit-Einträge dort (1-basiert).
        $capacities = find_vehicle_fill_capacities($v->getAttribute('filename'));
        $fuelLevels = [];
        $cargoLevels = [];
        foreach ($v->getElementsByTagName('unit') as $unit) {
            $fillType = $unit->getAttribute('fillType');
            $liters = round((float)$unit->getAttribute('fillLevel'), 1);
            if ($liters <= 0) continue; // leere Kammern nicht auflisten
            if (in_array($fillType, NON_CARGO_FILL_TYPES, true) && !isset(FUEL_TYPE_LABELS[$fillType])) continue;

            $unitIdx = (int)$unit->getAttribute('index');
            $capacity = ($unitIdx >= 1 && isset($capacities[$unitIdx - 1]) && $capacities[$unitIdx - 1] > 0)
                ? $capacities[$unitIdx - 1] : null;
            $entry = ['fillType' => $fillType, 'liters' => $liters];
            if ($capacity !== null) {
                $entry['capacity'] = $capacity;
                $entry['percent'] = round(min(100, $liters / $capacity * 100));
            }

            if (isset(FUEL_TYPE_LABELS[$fillType])) {
                $entry['label'] = FUEL_TYPE_LABELS[$fillType];
                $fuelLevels[] = $entry;
            } else {
                $entry['label'] = fill_type_label($fillType);
                $cargoLevels[] = $entry;
            }
        }

        $result[] = [
            'uniqueId' => $v->getAttribute('uniqueId'),
            'name' => readable_vehicle_name($v->getAttribute('filename')),
            'vehicleType' => classify_vehicle_type($v),
            'price' => (float)$v->getAttribute('price'),
            'operatingHours' => round((float)$v->getAttribute('operatingTime') / 3600, 1),
            'wear' => $wear,
            'dirt' => $dirtCount > 0 ? $dirtSum / $dirtCount : 0.0,
            'propertyState' => $v->getAttribute('propertyState'),
            'fuel' => $fuelLevels,
            'cargo' => $cargoLevels,
        ];
    }
    return $result;
}

// -----------------------------------------------------------------
// Tierbestände (Herden/Ställe aus placeables.xml)
// -----------------------------------------------------------------
const ANIMAL_SPECIES_LABELS = [
    'COW' => ['label' => 'Kühe', 'icon' => '🐄'],
    'PIG' => ['label' => 'Schweine', 'icon' => '🐖'],
    'SHEEP' => ['label' => 'Schafe', 'icon' => '🐑'],
    'HORSE' => ['label' => 'Pferde', 'icon' => '🐴'],
    'CHICKEN' => ['label' => 'Hühner', 'icon' => '🐔'],
    'GOAT' => ['label' => 'Ziegen', 'icon' => '🐐'],
];

// Rassen-/Farbbezeichnungen der Tiere sind im Spiel englische Codes (Rassenname bei
// Rindern/Schweinen/Schafen, Fellfarbe bei Pferden) – hier ins Deutsche übersetzt.
const ANIMAL_BREED_LABELS = [
    'LANDRACE' => 'Landrasse',
    'HOLSTEIN' => 'Holstein',
    'CHESTNUT' => 'Fuchs',
    'BAY' => 'Brauner',
    'GRAY' => 'Schimmel',
    'BLACK' => 'Rappe',
    'DUN' => 'Falbe',
    'PINTO' => 'Schecke',
    'PALOMINO' => 'Palomino',
    'SEAL_BROWN' => 'Dunkelbraun',
    'ROOSTER' => 'Hahn',
];

// Grobe, aber generische Deutsch-Übersetzung von Stall-/Gehege-Dateinamen. Mod-Autoren
// benennen ihre Dateien fast immer auf Englisch (z. B. "cowBarnSmall.xml"). Eine
// wörtliche 1:1-Übersetzung ist nicht möglich (Eigennamen von Mods bleiben unangetastet),
// aber Tierart + Größe + Stalltyp werden erkannt und zu einem deutschen Kompositum
// zusammengesetzt ("Kleiner Kuhstall", "Schweinestall", "Pferdeunterstand" usw.).
const BARN_SPECIES_STEMS = [
    'cow' => 'Kuh', 'pig' => 'Schweine', 'sheep' => 'Schaf',
    'horse' => 'Pferde', 'chicken' => 'Hühner', 'goat' => 'Ziegen',
];
const BARN_SIZE_ADJ = [
    'small' => ['m' => 'Kleiner', 'f' => 'Kleine'],
    'medium' => ['m' => 'Mittlerer', 'f' => 'Mittlere'],
    'large' => ['m' => 'Großer', 'f' => 'Große'],
    'big' => ['m' => 'Großer', 'f' => 'Große'],
];
const BARN_TYPE_WORDS = [
    'shed' => ['word' => 'schuppen', 'gender' => 'm'],
    'shelter' => ['word' => 'unterstand', 'gender' => 'm'],
    'hall' => ['word' => 'halle', 'gender' => 'f'],
    'coop' => ['word' => 'stall', 'gender' => 'm'],
    'stable' => ['word' => 'stall', 'gender' => 'm'],
    'sty' => ['word' => 'stall', 'gender' => 'm'],
    'barn' => ['word' => 'stall', 'gender' => 'm'],
];

// Gewächshaus-Gebäude sind keine Tierställe, folgen aber demselben englischen
// Benennungsschema ("greenHouseGlassLarge.xml" usw.) – eigenes, kleines
// Übersetzungsschema mit sachlichem statt männlichem/weiblichem Artikel ("das
// Gewächshaus").
const GREENHOUSE_SIZE_ADJ = [
    'small' => 'Kleines', 'medium' => 'Mittleres', 'large' => 'Großes', 'big' => 'Großes',
];
const GREENHOUSE_MATERIAL_WORDS = [
    'glass' => 'Glas-', 'foil' => 'Folien-', 'plastic' => 'Kunststoff-',
];

function readable_barn_name(string $filename): string {
    $clean = preg_replace('#^\$moddir\$[^/]+/#', '', $filename);
    $parts = explode('/', $clean);
    $base = preg_replace('/\.xml$/i', '', end($parts));
    $lower = strtolower($base);

    // Gewächshaus vor der Tierart-Erkennung prüfen, da es sonst mit keinem
    // BARN_SPECIES_STEMS-Muster übereinstimmt und ungenutzt auf den generischen
    // wortgetrennten Fallback zurückfallen würde ("Green House Glass Large").
    if (str_contains($lower, 'greenhouse')) {
        $sizePrefix = '';
        foreach (GREENHOUSE_SIZE_ADJ as $needle => $adj) {
            if (str_contains($lower, $needle)) { $sizePrefix = $adj . ' '; break; }
        }
        $material = '';
        foreach (GREENHOUSE_MATERIAL_WORDS as $needle => $word) {
            if (str_contains($lower, $needle)) { $material = $word; break; }
        }
        return trim($sizePrefix . $material . 'Gewächshaus');
    }

    $species = null;
    foreach (BARN_SPECIES_STEMS as $needle => $stem) {
        if (str_contains($lower, $needle)) { $species = $stem; break; }
    }

    if ($species !== null) {
        $type = ['word' => 'stall', 'gender' => 'm'];
        foreach (BARN_TYPE_WORDS as $needle => $t) {
            if (str_contains($lower, $needle)) { $type = $t; break; }
        }
        $sizePrefix = '';
        foreach (BARN_SIZE_ADJ as $needle => $adj) {
            if (str_contains($lower, $needle)) { $sizePrefix = $adj[$type['gender']] . ' '; break; }
        }
        return $sizePrefix . $species . $type['word'];
    }

    // Kein Tierart-Muster erkannt (z. B. Mod-Eigenname wie "Oudeschuur") -> nur
    // wortgetrennt anzeigen, unverändert übernommen statt falsch übersetzt.
    $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $base);
    $name = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $name);
    $name = ucfirst(trim($name));
    return $name !== '' ? $name : 'Stall';
}

function animal_species_info(string $subType): array {
    $species = strtok($subType, '_');
    $info = ANIMAL_SPECIES_LABELS[$species] ?? ['label' => ucfirst(strtolower($species)), 'icon' => '🐾'];
    $breedRaw = substr($subType, strlen($species) + 1);
    $breed = $breedRaw !== '' ? (ANIMAL_BREED_LABELS[$breedRaw] ?? ucwords(strtolower(str_replace('_', ' ', $breedRaw)))) : '';
    return ['species' => $info['label'], 'icon' => $info['icon'], 'breed' => $breed];
}

// Bienenstöcke sind KEINE husbandryAnimals-Gebäude – es sind einfache Placeables ohne
// Tierzahl (nur Position/Preis), die passiv die Bestäubung in der Umgebung verbessern.
// Ein separates "beeHivePalletSpawner"-Placeable sammelt den daraus entstehenden Honig
// und speichert den Fortschritt bis zur nächsten Honig-Palette als "pendingLiters".
function parse_beehives(string $savegameDir, string $farmId): array {
    $file = $savegameDir . DIRECTORY_SEPARATOR . 'placeables.xml';
    if (!file_exists($file)) return ['hiveCount' => 0, 'pendingHoneyLiters' => 0.0, 'hasSpawner' => false];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->load($file, LIBXML_PARSEHUGE);

    $hiveCount = 0;
    $pendingLiters = 0.0;
    $hasSpawner = false;
    foreach ($dom->getElementsByTagName('placeable') as $p) {
        if ($p->getAttribute('farmId') !== $farmId) continue;
        $filename = strtolower($p->getAttribute('filename'));
        if (!str_contains($filename, 'beehive')) continue;

        $spawnerNode = $p->getElementsByTagName('beehivePalletSpawner')->item(0);
        if ($spawnerNode) {
            $hasSpawner = true;
            $inner = $spawnerNode->getElementsByTagName('beehivePalletSpawner')->item(0);
            if ($inner) $pendingLiters += (float)$inner->getAttribute('pendingLiters');
            continue; // der Sammler selbst ist kein Bienenstock, nicht mitzählen
        }
        $hiveCount++;
    }

    return ['hiveCount' => $hiveCount, 'pendingHoneyLiters' => round($pendingLiters, 1), 'hasSpawner' => $hasSpawner, 'capacity' => PALLET_CAPACITY_LITERS, 'percent' => round(min(100, $pendingLiters / PALLET_CAPACITY_LITERS * 100), 1), 'finishedPallets' => $hasSpawner ? count_finished_pallets($savegameDir, $farmId, 'HONEY') : 0];
}

// Paletten-Kapazität: bei allen bekannten Paletten-Fülltypen (Eier, Honig, Wolle usw.)
// einheitlich 1000 Liter pro Palette – verifiziert an den echten Spieldateien
// (eggBoxPallet.xml und honeyBoxPallet.xml haben beide capacity="1000").
const PALLET_CAPACITY_LITERS = 1000.0;

// Dateiname-Fragmente der Paletten-Objekte je Fülltyp (aus maps_fillTypes.xml:
// <pallet filename="$data/objects/pallets/eggBoxPallet/eggBoxPallet.xml" /> usw.).
// Fertige, bereits abgeworfene Paletten liegen als eigenständige Objekte in
// vehicles.xml (physische, aufsammelbare Objekte werden dort geführt, nicht nur
// klassische Fahrzeuge) – ACHTUNG: konnte mangels eines Spielstands mit bereits
// fertigen Paletten nicht mit echten Daten verifiziert werden, nur die Kapazität
// selbst wurde bestätigt.
const PALLET_OBJECT_NAME_FRAGMENTS = [
    'EGG' => 'eggboxpallet',
    'HONEY' => 'honeyboxpallet',
    'WOOL' => 'woolpallet',
    'MILK' => 'milkpallet',
];

function count_finished_pallets(string $savegameDir, string $farmId, string $fillType): int {
    if (!isset(PALLET_OBJECT_NAME_FRAGMENTS[$fillType])) return 0;
    $needle = PALLET_OBJECT_NAME_FRAGMENTS[$fillType];
    $file = $savegameDir . DIRECTORY_SEPARATOR . 'vehicles.xml';
    if (!file_exists($file)) return 0;
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->load($file, LIBXML_PARSEHUGE);

    $count = 0;
    foreach ($dom->getElementsByTagName('vehicle') as $v) {
        if ($v->getAttribute('farmId') !== $farmId) continue;
        if (str_contains(strtolower($v->getAttribute('filename')), $needle)) $count++;
    }
    return $count;
}

// Sucht nach dem Paletten-Sammelfortschritt (z. B. Eier/Honig) im Placeable-Baum –
// verifiziert existieren dabei ZWEI unterschiedliche Strukturen in freier Wildbahn:
// Hühnerställe nutzen ein eigenes Element <pendingLiters fillType="EGG" liters="..."/>
// (Attribut heißt "liters"), Bienenstock-Sammler dagegen ein Attribut NAMENS
// "pendingLiters" auf einem gleichnamigen Element (<beehivePalletSpawner
// pendingLiters="..."/>). Beide Muster werden hier abgedeckt statt nur eines.
function find_pending_pallet_output(DOMElement $placeableNode): ?array {
    $xpath = new DOMXPath($placeableNode->ownerDocument);

    $byElement = $xpath->query('.//pendingLiters', $placeableNode);
    if ($byElement->length > 0) {
        $node = $byElement->item(0);
        $fillType = $node->getAttribute('fillType');
        $liters = (float)$node->getAttribute('liters');
        return [
            'pendingLiters' => round($liters, 1),
            'capacity' => PALLET_CAPACITY_LITERS,
            'percent' => round(min(100, $liters / PALLET_CAPACITY_LITERS * 100), 1),
            'fillType' => $fillType !== '' ? $fillType : null,
            'label' => $fillType !== '' ? fill_type_label($fillType) : null,
        ];
    }

    $byAttribute = $xpath->query('.//*[@pendingLiters]', $placeableNode);
    if ($byAttribute->length > 0) {
        $node = $byAttribute->item(0);
        $fillType = $node->getAttribute('fillType');
        $liters = (float)$node->getAttribute('pendingLiters');
        return [
            'pendingLiters' => round($liters, 1),
            'capacity' => PALLET_CAPACITY_LITERS,
            'percent' => round(min(100, $liters / PALLET_CAPACITY_LITERS * 100), 1),
            'fillType' => $fillType !== '' ? $fillType : null,
            'label' => $fillType !== '' ? fill_type_label($fillType) : null,
        ];
    }

    return null;
}

function parse_husbandries(string $savegameDir, string $farmId): array {
    $file = $savegameDir . DIRECTORY_SEPARATOR . 'placeables.xml';
    if (!file_exists($file)) return [];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->load($file, LIBXML_PARSEHUGE);

    $result = [];
    foreach ($dom->getElementsByTagName('placeable') as $p) {
        if ($p->getAttribute('farmId') !== $farmId) continue;
        $animalsNode = $p->getElementsByTagName('husbandryAnimals')->item(0);
        if (!$animalsNode) continue;

        $bySpecies = []; // Label => ['icon'=>, 'total'=>, 'breeds' => [Rasse => ['total'=>, 'clusters'=>[{age,count,health,reproduction,isPregnant,isParent}]]]]
        $total = 0;
        $anyBreedingData = false; // wird true, sobald irgendein Tier Gesundheit/Reproduktion > 0 hat
        foreach ($animalsNode->getElementsByTagName('animal') as $a) {
            $num = (int)$a->getAttribute('numAnimals');
            if ($num <= 0) continue;
            $age = (int)$a->getAttribute('age');
            // health/reproduction sind bei manchen Ställen/Mods nicht befuellt (immer 0) –
            // wir lesen sie trotzdem aus, blenden die Anzeige im Frontend aber nur ein, wenn
            // mindestens ein Tier im Bestand tatsächlich einen Wert > 0 hat (siehe $anyBreedingData).
            // Skalierung ist uneinheitlich und konnte an ECHTEN Spielständen in drei
            // verschiedenen Varianten beobachtet werden: Bruchteil 0–1 (z. B. 0.85), direkte
            // 0–10-Skala (z. B. "10" bei einem Huhn) und direkte 0–100-Skala (z. B. "100"/"50"
            // bei einem anderen Spielstand). Eine reine ">1 => ×10"-Heuristik hatte den dritten
            // Fall falsch behandelt (reproduction="50" wurde fälschlich zu 500% → auf 100%
            // gekappt statt korrekt 50% zu zeigen). Dreistufige Heuristik: Werte >10 gelten als
            // bereits 0–100, Werte >1 bis 10 als 0–10-Skala (×10), Werte ≤1 als Bruchteil (×100).
            $healthRaw = (float)$a->getAttribute('health');
            $reproductionRaw = (float)$a->getAttribute('reproduction');
            $scaleToPercent = function (float $raw): float {
                if ($raw > 10) return min(100, $raw);
                if ($raw > 1) return min(100, $raw * 10);
                return $raw * 100;
            };
            $health = $scaleToPercent($healthRaw);
            $reproduction = $scaleToPercent($reproductionRaw);
            $isPregnant = $a->getAttribute('isPregnant') === 'true';
            $isParent = $a->getAttribute('isParent') === 'true';
            if ($health > 0 || $reproduction > 0 || $isPregnant) $anyBreedingData = true;

            $info = animal_species_info($a->getAttribute('subType'));
            $total += $num;
            if (!isset($bySpecies[$info['species']])) {
                $bySpecies[$info['species']] = ['icon' => $info['icon'], 'total' => 0, 'breeds' => []];
            }
            $bySpecies[$info['species']]['total'] += $num;
            $breedKey = $info['breed'] !== '' ? $info['breed'] : $info['species'];
            if (!isset($bySpecies[$info['species']]['breeds'][$breedKey])) {
                $bySpecies[$info['species']]['breeds'][$breedKey] = ['total' => 0, 'clusters' => []];
            }
            $bySpecies[$info['species']]['breeds'][$breedKey]['total'] += $num;
            $bySpecies[$info['species']]['breeds'][$breedKey]['clusters'][] = [
                'age' => $age,
                'count' => $num,
                'health' => round($health),
                'reproduction' => round($reproduction),
                'isPregnant' => $isPregnant,
                'isParent' => $isParent,
            ];
        }
        if ($total === 0) continue; // leerer Stall (noch keine Tiere gekauft) wird nicht gelistet

        $meadow = null;
        $meadowNode = $p->getElementsByTagName('husbandryMeadow')->item(0);
        if ($meadowNode) {
            $fillTypeNode = $meadowNode->getElementsByTagName('fillType')->item(0);
            if ($fillTypeNode) {
                $level = (float)$fillTypeNode->getAttribute('fillLevel');
                $capacity = (float)$fillTypeNode->getAttribute('capacity');
                $meadow = [
                    'level' => round($level),
                    'capacity' => round($capacity),
                    'percent' => $capacity > 0 ? round($level / $capacity * 100) : 0,
                ];
            }
        }

        $speciesList = [];
        foreach ($bySpecies as $label => $data) {
            $breedList = [];
            foreach ($data['breeds'] as $breedName => $breedData) {
                // Älteste Tiere zuerst, wie in der Stall-Ansicht des Spiels
                usort($breedData['clusters'], fn($a, $b) => $b['age'] <=> $a['age']);
                $breedList[] = [
                    'name' => $breedName,
                    'total' => $breedData['total'],
                    'clusters' => $breedData['clusters'],
                ];
            }
            usort($breedList, fn($a, $b) => $b['total'] <=> $a['total']);
            $speciesList[] = [
                'label' => $label,
                'icon' => $data['icon'],
                'total' => $data['total'],
                'breeds' => $breedList,
            ];
        }
        usort($speciesList, fn($a, $b) => $b['total'] <=> $a['total']);

        $pendingProduct = find_pending_pallet_output($p);
        if ($pendingProduct && $pendingProduct['fillType']) {
            $pendingProduct['finishedPallets'] = count_finished_pallets($savegameDir, $farmId, $pendingProduct['fillType']);
        }

        $result[] = [
            'uniqueId' => $p->getAttribute('uniqueId'),
            'name' => readable_barn_name($p->getAttribute('filename')),
            'totalAnimals' => $total,
            'species' => $speciesList,
            'meadow' => $meadow,
            'hasBreedingData' => $anyBreedingData,
            'pendingProduct' => $pendingProduct,
        ];
    }
    usort($result, fn($a, $b) => $b['totalAnimals'] <=> $a['totalAnimals']);
    return $result;
}

// -----------------------------------------------------------------
// Produktionsketten (Verarbeitungsanlagen wie Mühle, Sägewerk, Biogas usw.)
// -----------------------------------------------------------------
// Deutsche Übersetzung der geläufigsten Produktionsketten-IDs. Mods bringen oft eigene,
// unbekannte IDs mit – die werden lesbar aus der ID selbst hergeleitet statt geraten übersetzt.
const PRODUCTION_ID_LABELS = [
    'flourWheat' => 'Weizenmehl', 'flourBarley' => 'Gerstenmehl', 'flourOat' => 'Hafermehl',
    'flourSorghum' => 'Sorghummehl', 'flourRice' => 'Reismehl', 'flourRiceLongGrain' => 'Langkornreismehl',
    'bread' => 'Brot', 'sugar' => 'Zucker', 'sugarBeet' => 'Zucker (aus Rüben)', 'sugarCane' => 'Zucker (aus Zuckerrohr)',
    'biogas' => 'Biogas', 'biogasManure' => 'Biogas (Mist)', 'biogasLiquidManure' => 'Biogas (Gülle)',
    'biogasSugarbeetCut' => 'Biogas (Zuckerrüben)', 'biogasPotato' => 'Biogas (Kartoffeln)',
    'planks' => 'Bretter', 'wood' => 'Holz', 'woodChips' => 'Hackschnitzel',
    'chocolate' => 'Schokolade', 'cheese' => 'Käse', 'butter' => 'Butter', 'milk' => 'Milch',
    'clothing' => 'Kleidung', 'fabric' => 'Stoff', 'cotton' => 'Baumwolle',
    'chips' => 'Chips', 'oil' => 'Öl', 'oliveOil' => 'Olivenöl', 'grapeJuice' => 'Traubensaft',
    'wine' => 'Wein', 'eggs' => 'Eier', 'pigFood' => 'Schweinefutter', 'forage' => 'Mischfutter',
    'tms' => 'Totalmischration',
    // Gewächshaus-Kulturen (Obst/Gemüse) – entsprechen keiner Feld-Fruchtart aus
    // fruit_type_label(), daher hier separat gepflegt
    'strawberry' => 'Erdbeeren', 'tomato' => 'Tomaten', 'chilli' => 'Chili', 'chili' => 'Chili',
    'cucumber' => 'Gurken', 'pepper' => 'Paprika', 'eggplant' => 'Auberginen',
    'blueberry' => 'Heidelbeeren', 'raspberry' => 'Himbeeren',
    'springOnion' => 'Frühlingszwiebeln', 'greenBean' => 'Grüne Bohnen', 'napaCabbage' => 'Chinakohl',
    'greenbean' => 'Grüne Bohnen',
];

function production_id_label(string $id): string {
    if (isset(PRODUCTION_ID_LABELS[$id])) return PRODUCTION_ID_LABELS[$id];
    // Manche Produktions-IDs entsprechen direkt einer bekannten Feld-Fruchtart (z. B.
    // "lettuce" = LETTUCE) – dieselbe Übersetzungstabelle wiederverwenden statt sie
    // doppelt zu pflegen.
    $asFruitType = fruit_type_label(strtoupper($id));
    if ($asFruitType !== strtoupper($id)) return $asFruitType;

    // Unbekannte ID: nur wortgetrennt anzeigen statt geraten zu übersetzen
    $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $id);
    return ucfirst(trim($name));
}

function parse_production_points(string $savegameDir, string $farmId): array {
    $file = $savegameDir . DIRECTORY_SEPARATOR . 'placeables.xml';
    if (!file_exists($file)) return [];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->load($file, LIBXML_PARSEHUGE);

    $result = [];
    foreach ($dom->getElementsByTagName('placeable') as $p) {
        if ($p->getAttribute('farmId') !== $farmId) continue;
        $ppNode = $p->getElementsByTagName('productionPoint')->item(0);
        if (!$ppNode) continue;

        $productions = [];
        foreach ($ppNode->getElementsByTagName('production') as $prod) {
            $productions[] = [
                'id' => $prod->getAttribute('id'),
                'label' => production_id_label($prod->getAttribute('id')),
                'enabled' => $prod->getAttribute('isEnabled') === 'true',
            ];
        }
        usort($productions, fn($a, $b) => strcmp($a['label'], $b['label']));

        $result[] = [
            'uniqueId' => $p->getAttribute('uniqueId'),
            // Gleiche Namens-Heuristik wie bei Ställen: erkennt hier keine Tierart, fällt
            // also direkt auf die wortgetrennte Rohdarstellung des Dateinamens zurück.
            'name' => readable_barn_name($p->getAttribute('filename')),
            'productions' => $productions,
            'activeCount' => count(array_filter($productions, fn($x) => $x['enabled'])),
        ];
    }
    usort($result, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $result;
}

// -----------------------------------------------------------------
// Marktpreise
// -----------------------------------------------------------------
const MARKET_PERIOD_ORDER = [
    'EARLY_SPRING', 'MID_SPRING', 'LATE_SPRING',
    'EARLY_SUMMER', 'MID_SUMMER', 'LATE_SUMMER',
    'EARLY_AUTUMN', 'MID_AUTUMN', 'LATE_AUTUMN',
    'EARLY_WINTER', 'MID_WINTER', 'LATE_WINTER',
];
const MARKET_PERIOD_LABELS_DE = [
    'EARLY_SPRING' => 'Fr. Frühling', 'MID_SPRING' => 'Frühling', 'LATE_SPRING' => 'Sp. Frühling',
    'EARLY_SUMMER' => 'Fr. Sommer', 'MID_SUMMER' => 'Sommer', 'LATE_SUMMER' => 'Sp. Sommer',
    'EARLY_AUTUMN' => 'Fr. Herbst', 'MID_AUTUMN' => 'Herbst', 'LATE_AUTUMN' => 'Sp. Herbst',
    'EARLY_WINTER' => 'Fr. Winter', 'MID_WINTER' => 'Winter', 'LATE_WINTER' => 'Sp. Winter',
];

function get_current_period_index(int $currentDay, int $daysPerPeriod): int {
    $daysPerPeriod = max(1, $daysPerPeriod);
    return (int)(floor(($currentDay - 1) / $daysPerPeriod)) % 12;
}

function parse_market_data(string $savegameDir, int $currentDay, int $daysPerPeriod): array {
    $file = $savegameDir . DIRECTORY_SEPARATOR . 'economy.xml';
    if (!file_exists($file)) return [];
    libxml_use_internal_errors(true);
    $xml = simplexml_load_file($file);
    if (!$xml || !isset($xml->fillTypes)) return [];

    $periodIdx = get_current_period_index($currentDay, $daysPerPeriod);
    $prevIdx = ($periodIdx + 11) % 12;
    $currentKey = MARKET_PERIOD_ORDER[$periodIdx];
    $prevKey = MARKET_PERIOD_ORDER[$prevIdx];

    // Nur Fruchtarten, für die wir eine deutsche Bezeichnung kennen (echte Feldfrüchte),
    // FALLOW/UNKNOWN sind keine handelbaren Güter.
    $relevantTypes = array_diff(array_keys([
        'WHEAT'=>1,'BARLEY'=>1,'CANOLA'=>1,'OAT'=>1,'MAIZE'=>1,'SILAGEMAIZE'=>1,'SUNFLOWER'=>1,
        'SOYBEAN'=>1,'POTATO'=>1,'SUGARBEET'=>1,'SUGARCANE'=>1,'COTTON'=>1,'SPINACH'=>1,
        'GREENBEAN'=>1,'RICE'=>1,'PEA'=>1,'ONION'=>1,'CARROT'=>1,'PARSNIP'=>1,'OILSEEDRADISH'=>1,
        'RYE'=>1,'TRITICALE'=>1,'GRAPE'=>1,'OLIVE'=>1,'WINTERWHEAT'=>1,'WINTERBARLEY'=>1,
        'WINTERCANOLA'=>1,'WINTERRYE'=>1,'SORGHUM'=>1,'MILLET'=>1,'LETTUCE'=>1,'CABBAGE'=>1,
        'REDCABBAGE'=>1,'BEETROOT'=>1,'ALFALFA'=>1,'SPELT'=>1,
    ]), []);

    $result = [];
    foreach ($xml->fillTypes->fillType as $ft) {
        $type = (string)$ft['fillType'];
        if (!in_array($type, $relevantTypes, true)) continue;
        if (!isset($ft->history)) continue;

        $history = [];
        foreach ($ft->history->period as $p) {
            $history[(string)$p['period']] = (float)$p;
        }
        if (!isset($history[$currentKey])) continue;

        $current = $history[$currentKey];
        $previous = $history[$prevKey] ?? $current;
        $maxVal = max($history);
        $minVal = min($history);
        $bestPeriod = array_search($maxVal, $history, true);

        $result[] = [
            'fruitType' => $type,
            'label' => fruit_type_label($type),
            'currentPrice' => round($current, 0),
            'trend' => $current > $previous ? 'up' : ($current < $previous ? 'down' : 'flat'),
            'history' => $history,
            'bestPeriod' => $bestPeriod,
            'bestPeriodLabel' => MARKET_PERIOD_LABELS_DE[$bestPeriod] ?? $bestPeriod,
            'isAtBest' => abs($current - $maxVal) < 0.01,
            'maxPrice' => round($maxVal, 0),
            'minPrice' => round($minVal, 0),
        ];
    }
    usort($result, fn($a, $b) => $b['currentPrice'] <=> $a['currentPrice']);
    return $result;
}

// -----------------------------------------------------------------
// Vertrags-Feed
// -----------------------------------------------------------------
function mission_type_label(string $tag): string {
    $map = [
        'mowMission' => 'Mähen',
        'fertilizeMission' => 'Düngen/Spritzen',
        'harvestMission' => 'Ernten',
        'baleWrapMission' => 'Ballen wickeln',
        'tedderMission' => 'Wenden/Zetten',
        'stonePickMission' => 'Steine sammeln',
        'hoeMission' => 'Hacken',
        'plowMission' => 'Pflügen',
        'cultivateMission' => 'Grubbern',
        'sowMission' => 'Säen',
        'weedMission' => 'Unkraut entfernen',
        'destoneMission' => 'Steine sammeln',
        // Forstwirtschafts-Verträge (fehlten bisher komplett, fielen auf den rohen
        // internen Tag-Namen zurück statt auf eine Übersetzung)
        'deadwoodMission' => 'Totholz entfernen',
        'treeTransportMission' => 'Baumstämme transportieren',
        'treeCuttingMission' => 'Bäume fällen',
        'treePlantingMission' => 'Bäume pflanzen',
        // Weitere im echten Spielbetrieb aufgetauchte Lücken
        'herbicideMission' => 'Herbizid spritzen',
    ];
    if (isset($map[$tag])) return $map[$tag];

    // Fallback für noch unbekannte Vertragstypen: statt des rohen internen Tags
    // wenigstens eine lesbare, wortgetrennte Näherung zeigen (z. B. "someNewMission"
    // -> "Some New") statt gar keine Übersetzung.
    $name = preg_replace('/Mission$/', '', $tag);
    $name = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);
    return ucfirst(trim($name));
}

function parse_missions(string $savegameDir, int $currentDay): array {
    $file = $savegameDir . DIRECTORY_SEPARATOR . 'missions.xml';
    if (!file_exists($file)) return [];
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->load($file, LIBXML_PARSEHUGE);

    // Aktuelle Fruchtart je Feld für zusätzlichen Kontext (unabhängig vom Eigentum,
    // Verträge betreffen i. d. R. gerade nicht die eigenen Felder)
    $fieldsById = [];
    foreach (parse_fields($savegameDir) as $f) {
        $fieldsById[$f['id']] = $f;
    }

    $result = [];
    foreach ($dom->documentElement->childNodes as $node) {
        if ($node->nodeType !== XML_ELEMENT_NODE) continue;

        $fieldNode = $node->getElementsByTagName('field')->item(0);
        $endDateNode = $node->getElementsByTagName('endDate')->item(0);
        $infoNode = $node->getElementsByTagName('info')->item(0);
        $endDay = $endDateNode ? (int)$endDateNode->getAttribute('endDay') : $currentDay;
        $fieldId = $fieldNode ? $fieldNode->getAttribute('id') : null;

        $detail = '';
        if ($node->tagName === 'harvestMission') {
            $harvestNode = $node->getElementsByTagName('harvest')->item(0);
            if ($harvestNode) $detail = fruit_type_label($harvestNode->getAttribute('fruitType'));
        } elseif ($node->tagName === 'deadwoodMission') {
            $treeCount = $node->getElementsByTagName('originalTree')->length;
            $detail = $treeCount . ' Baum' . ($treeCount === 1 ? '' : 'stämme');
        } elseif ($node->tagName === 'treeTransportMission') {
            $numTrees = $node->getAttribute('numTrees');
            $detail = $numTrees !== '' ? $numTrees . ' Bäume' : '';
        } elseif ($node->hasAttribute('fruitType')) {
            $detail = fruit_type_label($node->getAttribute('fruitType'));
        } elseif ($node->hasAttribute('targetSprayLevel')) {
            $detail = 'Zielstufe ' . $node->getAttribute('targetSprayLevel');
        }

        $reward = $infoNode ? (float)$infoNode->getAttribute('reward') : 0;

        $result[] = [
            'type' => $node->tagName,
            'typeLabel' => mission_type_label($node->tagName),
            'fieldId' => $fieldId,
            'fieldCrop' => $fieldId && isset($fieldsById[$fieldId]) ? fruit_type_label($fieldsById[$fieldId]['fruitType']) : null,
            'endDay' => $endDay,
            'daysLeft' => max(0, $endDay - $currentDay),
            'detail' => $detail,
            'status' => $node->getAttribute('status'),
            'reward' => $reward,
        ];
    }
    usort($result, fn($a, $b) => $a['daysLeft'] <=> $b['daysLeft'] ?: strcmp($a['typeLabel'], $b['typeLabel']));
    return $result;
}

function list_backups_for(string $folder): array {
    $files = glob(BACKUP_DIR . '/' . $folder . '_AutoDrive_config_*.xml');
    rsort($files); // neueste zuerst (Zeitstempel im Dateinamen sortiert lexikalisch korrekt)
    return $files;
}

function prune_old_backups(string $folder, int $keep): void {
    $files = list_backups_for($folder);
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
}

function make_backup_filename(string $folder): string {
    // Millisekunden-Anteil verhindert Kollisionen bei mehreren Speichervorgängen
    // innerhalb derselben Sekunde (z. B. schnelles Testen/Skripten).
    $ms = sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
    return BACKUP_DIR . '/' . $folder . '_AutoDrive_config_' . date('Y-m-d_His') . '_' . $ms . '.xml';
}

// -----------------------------------------------------------------
// Vollständige Spielstand-Backups (ZIP des kompletten savegameN-Ordners) –
// unabhängig von den automatischen AutoDrive-XML-Backups oben. Eigener
// Unterordner backups/full/, da diese Dateien deutlich größer sind.
// -----------------------------------------------------------------
function full_backup_dir(): string {
    $dir = BACKUP_DIR . '/full';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return $dir;
}

function list_full_backups_for(string $folder): array {
    $files = glob(full_backup_dir() . '/' . $folder . '_full_*.zip');
    rsort($files);
    return $files;
}

function prune_old_full_backups(string $folder, int $keep): void {
    $files = list_full_backups_for($folder);
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
}

function make_full_backup_filename(string $folder): string {
    $ms = sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
    return full_backup_dir() . '/' . $folder . '_full_' . date('Y-m-d_His') . '_' . $ms . '.zip';
}

// -----------------------------------------------------------------
// Backups für farms.xml (eigener, kleiner Satz an Sicherungen, unabhängig von den
// AutoDrive-Backups, da eine andere Datei betroffen ist)
// -----------------------------------------------------------------
function list_farms_backups_for(string $folder): array {
    $files = glob(BACKUP_DIR . '/' . $folder . '_farms_*.xml');
    rsort($files);
    return $files;
}

function prune_old_farms_backups(string $folder, int $keep): void {
    $files = list_farms_backups_for($folder);
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
}

function make_farms_backup_filename(string $folder): string {
    $ms = sprintf('%03d', (int)(microtime(true) * 1000) % 1000);
    return BACKUP_DIR . '/' . $folder . '_farms_' . date('Y-m-d_His') . '_' . $ms . '.xml';
}

// -----------------------------------------------------------------
// Kartenhintergrundbild: gemeinsame Verarbeitung (Formatprüfung, Downscale,
// Speichern) für sowohl manuellen Upload als auch automatisches Laden aus den
// Moddateien – identische Logik, nur die Bildquelle unterscheidet sich.
// -----------------------------------------------------------------
function save_terrain_image_from_path(string $srcPath, string $destPath): array {
    $info = @getimagesize($srcPath);
    if (!$info) {
        return ['error' => 'Datei ist kein gültiges Bild.'];
    }

    // Anhand der IMAGETYPE-Konstante statt des rohen Mime-Strings prüfen: manche Tools
    // (Screenshot-Programme, Bildbearbeitung) liefern Varianten wie "image/x-png" oder
    // "image/pjpeg" statt der Standard-Strings.
    $loadersByType = [
        IMAGETYPE_PNG => 'imagecreatefrompng',
        IMAGETYPE_JPEG => 'imagecreatefromjpeg',
        IMAGETYPE_WEBP => 'imagecreatefromwebp',
    ];
    $type = $info[2];
    if (!isset($loadersByType[$type]) || !function_exists($loadersByType[$type])) {
        return ['error' => 'Bildformat nicht unterstützt (erlaubt: PNG, JPEG, WEBP). Erkannt: ' . ($info['mime'] ?? 'unbekannt')];
    }

    $src = @$loadersByType[$type]($srcPath);
    if (!$src) {
        return ['error' => 'Bild konnte nicht gelesen werden.'];
    }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    // Sehr große Bilder auf eine handhabbare Kantenlänge herunterskalieren – die Karte
    // braucht keine Auflösung jenseits dessen, was am Bildschirm sichtbar ist, und hält
    // die Datei klein genug für schnelles Laden im Browser.
    $maxDim = 2048;
    if ($srcW > $maxDim || $srcH > $maxDim) {
        $ratio = min($maxDim / $srcW, $maxDim / $srcH);
        $dstW = max(1, (int)round($srcW * $ratio));
        $dstH = max(1, (int)round($srcH * $ratio));
        $dst = imagecreatetruecolor($dstW, $dstH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($src);
    } else {
        $dst = $src;
        $dstW = $srcW;
        $dstH = $srcH;
    }

    $assetsDir = dirname($destPath);
    if (!is_dir($assetsDir)) mkdir($assetsDir, 0777, true);

    if (!imagepng($dst, $destPath)) {
        imagedestroy($dst);
        return ['error' => 'Bild konnte nicht gespeichert werden.'];
    }
    imagedestroy($dst);

    return ['success' => true, 'width' => $dstW, 'height' => $dstH];
}

// Sucht nach einem als Kartenbild nutzbaren Bild – je nach mapId entweder in der
// Mod-ZIP (Community-Karten) oder direkt in den Spieldateien (offizielle GIANTS-
// Karten). Community-Karten haben ein mapId-Format "<ModName>.<KartenSchlüssel>"
// (z. B. "FS25_Szpakowo.SampleModMap"); offizielle Karten haben keinen Punkt im
// mapId (z. B. "mapUS") und liegen nicht im Spielstand-mods-Ordner, sondern direkt
// im Installationsverzeichnis des Spiels. Wichtige Einschränkung in beiden Fällen:
// Kartenbilder liegen in FS25 so gut wie immer als DDS-Textur vor, die PHP/GD nicht
// lesen kann – verifiziert an der echten mapUS-Installation: die eigene Karten-XML
// referenziert zwar "overview.png", tatsächlich ausgeliefert wird aber nur
// "overview.dds". Diese Funktion findet nur PNG/JPEG-Kandidaten; ein DDS-Fund wird
// als Hinweis mitgegeben, aber nicht nutzbar gemacht.
function find_map_overview_image(string $mapId): array {
    if (!class_exists('ZipArchive')) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'no_zip_extension'];
    }
    if ($mapId === '') {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'no_map_id'];
    }

    if (str_contains($mapId, '.')) {
        return find_map_overview_image_in_mod($mapId);
    }
    return find_map_overview_image_in_install($mapId);
}

// Heuristik: nur Dateien, deren DATEINAME (nicht der gesamte Pfad!) exakt auf eine
// Kartenübersicht hindeutet. Vorher wurde der komplette Pfad nach diesen Begriffen als
// Teilstring durchsucht – bei großen Mods mit tausenden Texturdateien konnte das
// versehentlich eine völlig unbezogene Datei treffen, deren Pfad zufällig "overview"
// als Teilstring enthält (z. B. ".../overviewShedRoof.dds"), und lieferte dann ein
// komplett falsches Kartenbild aus – genau das ist bei Szpakowo passiert. Der exakte
// Dateiname (ohne Endung) muss jetzt komplett übereinstimmen. "preview" ist bewusst
// NICHT dabei: mapUS/mapEU liefern getrennt "overview.dds" (volle Karte) und
// "preview.dds" (kleines Store-Icon) – verifiziert an den echten Spieldateien.
function overview_filename_matches(string $path): bool {
    $base = strtolower(pathinfo($path, PATHINFO_FILENAME));
    return in_array($base, ['overview', 'mapoverview', 'ingamemap', 'mapimage', 'minimap'], true);
}

function overview_file_type(string $lower): ?string {
    if (preg_match('/\.(png|jpe?g)$/', $lower)) return 'png';
    if (str_ends_with($lower, '.dds')) return 'dds';
    return null;
}

// Gemeinsame Auswahllogik für beide Quellen (ZIP-Einträge oder Dateisystem-Treffer):
// größte PNG/JPEG-Kandidatendatei bevorzugen (kleine Icons/Vorschaubilder sind i. d. R.
// deutlich kleiner als eine echte Kartenübersicht in nutzbarer Auflösung).
function pick_best_overview_candidate(array $entries): array {
    $pngCandidates = array_values(array_filter($entries, fn($e) => $e['type'] === 'png'));
    $ddsFound = count(array_filter($entries, fn($e) => $e['type'] === 'dds')) > 0;
    if (empty($pngCandidates)) {
        return ['found' => false, 'ddsOnly' => $ddsFound, 'reason' => $ddsFound ? 'dds_only' : 'no_candidate'];
    }
    usort($pngCandidates, fn($a, $b) => $b['size'] <=> $a['size']);
    return ['found' => true, 'best' => $pngCandidates[0]];
}

function find_map_overview_image_in_mod(string $mapId): array {
    // mapId-Format: "<ModName>.<KartenSchlüssel>". Kein zugehöriges ZIP im
    // mods-Ordner ist kein Fehler, sondern schlicht keine Community-Karte.
    $modName = strtok($mapId, '.');
    if (!$modName) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'no_map_id'];
    }

    $zipPath = FS_BASE_DIR . DIRECTORY_SEPARATOR . 'mods' . DIRECTORY_SEPARATOR . $modName . '.zip';
    if (!file_exists($zipPath)) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'no_mod_zip'];
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'zip_open_failed'];
    }

    $entries = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        $lower = strtolower($name);
        if (!overview_filename_matches($lower)) continue;
        $type = overview_file_type($lower);
        if (!$type) continue;
        $stat = $zip->statIndex($i);
        $entries[] = ['name' => $name, 'size' => $stat['size'] ?? 0, 'type' => $type];
    }

    $pick = pick_best_overview_candidate($entries);
    if (!$pick['found']) {
        $zip->close();
        return $pick;
    }

    $imageData = $zip->getFromName($pick['best']['name']);
    $zip->close();

    if ($imageData === false) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'extract_failed'];
    }

    return ['found' => true, 'ddsOnly' => false, 'data' => $imageData, 'sourceName' => $pick['best']['name']];
}

// Offizielle GIANTS-Karte: liegt nicht im Spielstand-mods-Ordner, sondern direkt im
// Installationsverzeichnis des Spiels unter data/maps/<mapId>/. FS_INSTALL_DIR wird
// in config.php automatisch erkannt oder lässt sich dort manuell setzen; ist der
// Ordner nicht bekannt, ist das kein Fehler des Tools, sondern fehlende Information
// – der Nutzer bekommt dafür eine gezielte Meldung statt eines stillen Fehlschlags.
function find_map_overview_image_in_install(string $mapId): array {
    if (!defined('FS_INSTALL_DIR') || FS_INSTALL_DIR === '') {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'no_install_dir'];
    }

    $mapDir = FS_INSTALL_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . $mapId;
    if (!is_dir($mapDir)) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'map_dir_not_found'];
    }

    $entries = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mapDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $lower = strtolower($file->getFilename());
        if (!overview_filename_matches($lower)) continue;
        $type = overview_file_type($lower);
        if (!$type) continue;
        $entries[] = ['name' => $file->getPathname(), 'size' => $file->getSize(), 'type' => $type];
    }

    $pick = pick_best_overview_candidate($entries);
    if (!$pick['found']) {
        return $pick;
    }

    $imageData = @file_get_contents($pick['best']['name']);
    if ($imageData === false) {
        return ['found' => false, 'ddsOnly' => false, 'reason' => 'extract_failed'];
    }

    return ['found' => true, 'ddsOnly' => false, 'data' => $imageData, 'sourceName' => basename($pick['best']['name'])];
}

// -----------------------------------------------------------------
// Exakte Kartengröße ermitteln (unabhängig vom Bildformat-Problem oben): Jede
// FS25-Karte – offiziell oder Mod – muss eine Wurzel-XML mit <map width="..."
// height="..."> besitzen, das ist Teil des Kartenformats selbst (verifiziert an
// mapUS.xml: <map width="2048" height="2048" ...>). Diese Zahl ist genauer als
// die Schätzung anhand der Wegpunkt-Ausdehnung im Frontend und funktioniert auch,
// wenn das eigentliche Kartenbild nur als DDS vorliegt.
// -----------------------------------------------------------------
function extract_map_size_from_xml_string(string $xmlContent): ?array {
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($xmlContent);
    if (!$xml || $xml->getName() !== 'map') return null;
    $w = (int)($xml['width'] ?? 0);
    $h = (int)($xml['height'] ?? 0);
    if ($w <= 0 || $h <= 0) return null;
    return ['width' => $w, 'height' => $h];
}

function find_map_size(string $mapId): ?array {
    if ($mapId === '') return null;
    if (str_contains($mapId, '.')) {
        return find_map_size_in_mod($mapId);
    }
    return find_map_size_in_install($mapId);
}

function find_map_size_in_install(string $mapId): ?array {
    if (!defined('FS_INSTALL_DIR') || FS_INSTALL_DIR === '') return null;
    $xmlPath = FS_INSTALL_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'maps'
        . DIRECTORY_SEPARATOR . $mapId . DIRECTORY_SEPARATOR . $mapId . '.xml';
    if (!file_exists($xmlPath)) return null;
    $content = @file_get_contents($xmlPath);
    if ($content === false) return null;
    return extract_map_size_from_xml_string($content);
}

function find_map_size_in_mod(string $mapId): ?array {
    if (!class_exists('ZipArchive')) return null;
    $modName = strtok($mapId, '.');
    if (!$modName) return null;
    $zipPath = FS_BASE_DIR . DIRECTORY_SEPARATOR . 'mods' . DIRECTORY_SEPARATOR . $modName . '.zip';
    if (!file_exists($zipPath)) return null;

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return null;

    $result = null;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || !preg_match('/\.xml$/i', $name)) continue;
        // Die Kartendefinitions-XML liegt bei jeder FS25-Karte im Wurzelverzeichnis
        // (höchstens einen Unterordner tief) – tiefer verschachtelte XMLs
        // (Fahrzeuge, Geräte usw.) werden so ausgeschlossen.
        if (substr_count($name, '/') > 1) continue;
        $content = $zip->getFromName($name);
        if ($content === false) continue;
        $size = extract_map_size_from_xml_string($content);
        if ($size) { $result = $size; break; }
    }
    $zip->close();
    return $result;
}

// -----------------------------------------------------------------
// Rohe DDS-Kartentextur ausliefern, wenn keine PNG/JPEG-Variante existiert.
// PHP/GD kann DDS nicht dekodieren, ein Browser mit JavaScript aber sehr wohl
// (siehe DXT1-Dekoder im Frontend) – dieser Endpunkt liefert daher einfach die
// unveränderten Rohbytes, die Dekodierung passiert komplett client-seitig.
// -----------------------------------------------------------------
function find_map_overview_dds(string $mapId): ?string {
    if ($mapId === '') return null;
    if (str_contains($mapId, '.')) {
        return find_map_overview_dds_in_mod($mapId);
    }
    return find_map_overview_dds_in_install($mapId);
}

function find_map_overview_dds_in_mod(string $mapId): ?string {
    if (!class_exists('ZipArchive')) return null;
    $modName = strtok($mapId, '.');
    if (!$modName) return null;
    $zipPath = FS_BASE_DIR . DIRECTORY_SEPARATOR . 'mods' . DIRECTORY_SEPARATOR . $modName . '.zip';
    if (!file_exists($zipPath)) return null;

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return null;

    $ddsCandidates = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        $lower = strtolower($name);
        if (!overview_filename_matches($lower) || !str_ends_with($lower, '.dds')) continue;
        $stat = $zip->statIndex($i);
        $ddsCandidates[] = ['name' => $name, 'size' => $stat['size'] ?? 0];
    }
    if (empty($ddsCandidates)) {
        $zip->close();
        return null;
    }
    usort($ddsCandidates, fn($a, $b) => $b['size'] <=> $a['size']);
    $data = $zip->getFromName($ddsCandidates[0]['name']);
    $zip->close();
    return $data !== false ? $data : null;
}

function find_map_overview_dds_in_install(string $mapId): ?string {
    if (!defined('FS_INSTALL_DIR') || FS_INSTALL_DIR === '') return null;
    $mapDir = FS_INSTALL_DIR . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'maps' . DIRECTORY_SEPARATOR . $mapId;
    if (!is_dir($mapDir)) return null;

    $ddsCandidates = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mapDir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $lower = strtolower($file->getFilename());
        if (!overview_filename_matches($lower) || !str_ends_with($lower, '.dds')) continue;
        $ddsCandidates[] = ['path' => $file->getPathname(), 'size' => $file->getSize()];
    }
    if (empty($ddsCandidates)) return null;
    usort($ddsCandidates, fn($a, $b) => $b['size'] <=> $a['size']);
    $data = @file_get_contents($ddsCandidates[0]['path']);
    return $data !== false ? $data : null;
}

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ---------------------------------------------------------------
// Spielstände auflisten (Auswahlmaske)
// ---------------------------------------------------------------
if ($action === 'list_savegames' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $result = [];
    foreach (glob(FS_BASE_DIR . DIRECTORY_SEPARATOR . 'savegame*', GLOB_ONLYDIR) as $dir) {
        $folder = basename($dir);
        if (!preg_match('/^savegame\d+$/', $folder)) continue;

        $careerFile = $dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml';
        $adFile = $dir . DIRECTORY_SEPARATOR . 'AutoDrive_config.xml';
        if (!file_exists($careerFile)) continue;

        $entry = [
            'folder' => $folder,
            'savegameName' => $folder,
            'farmName' => '',
            'manager' => '',
            'mapTitle' => '',
            'saveDate' => '',
            'saveDateSort' => '',
            'hasAutoDrive' => file_exists($adFile),
        ];

        libxml_use_internal_errors(true);
        $career = simplexml_load_file($careerFile);
        if ($career && isset($career->settings)) {
            $s = $career->settings;
            $entry['savegameName'] = (string)($s->savegameName ?? $folder);
            $entry['mapTitle'] = (string)($s->mapTitle ?? '');
            $entry['saveDate'] = (string)($s->saveDateFormatted ?? '');
            $entry['saveDateSort'] = (string)($s->saveDate ?? '');
        }

        $farmInfo = get_farm_info($dir);
        $entry['farmName'] = $farmInfo['farmName'];
        $entry['manager'] = $farmInfo['manager'];

        $result[] = $entry;
    }

    // Neueste zuerst (ISO-Datum sortiert korrekt lexikalisch)
    usort($result, fn($a, $b) => strcmp($b['saveDateSort'], $a['saveDateSort']));

    echo json_encode(['savegames' => $result, 'baseDir' => FS_BASE_DIR]);
    exit;
}

// ---------------------------------------------------------------
// Spielstand auswählen
// ---------------------------------------------------------------
if ($action === 'select_savegame' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $folder = $body['folder'] ?? '';
    $dir = get_general_savegame_dir($folder);

    if (!$dir) {
        http_response_code(404);
        echo json_encode(['error' => 'Spielstand nicht gefunden.']);
        exit;
    }

    $_SESSION['savegame_folder'] = $folder;
    echo json_encode(['success' => true, 'folder' => $folder, 'hasAutoDrive' => get_config_path_for_folder($folder) !== null]);
    exit;
}

// ---------------------------------------------------------------
// Aktuell ausgewählten Spielstand abfragen
// ---------------------------------------------------------------
if ($action === 'current_savegame' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    $dir = $folder ? get_general_savegame_dir($folder) : null;
    echo json_encode([
        'folder' => $dir ? $folder : null,
        'hasAutoDrive' => $dir ? (get_config_path_for_folder($folder) !== null) : false,
    ]);
    exit;
}

// ---------------------------------------------------------------
// Spielstand-Auswahl aufheben (zurück zur Auswahlmaske)
// ---------------------------------------------------------------
if ($action === 'clear_savegame' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    unset($_SESSION['savegame_folder']);
    echo json_encode(['success' => true]);
    exit;
}

// ---------------------------------------------------------------
// Backups auflisten
// ---------------------------------------------------------------
if ($action === 'list_backups' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }

    $files = list_backups_for($folder);
    $result = array_map(function ($f) {
        // Millisekunden-Suffix ist optional: ältere Backups von vor dessen Einführung
        // haben nur Datum+Uhrzeit ohne "_XXX" am Ende.
        preg_match('/_(\d{4}-\d{2}-\d{2}_\d{6})(?:_\d{3})?\.xml$/', $f, $m);
        $ts = $m[1] ?? '';
        $formatted = $ts ? sprintf(
            '%s.%s.%s %s:%s:%s',
            substr($ts, 8, 2), substr($ts, 5, 2), substr($ts, 0, 4),
            substr($ts, 11, 2), substr($ts, 13, 2), substr($ts, 15, 2)
        ) : '';
        return ['file' => basename($f), 'formatted' => $formatted, 'size' => filesize($f)];
    }, $files);

    echo json_encode(['backups' => $result]);
    exit;
}

// ---------------------------------------------------------------
// Backup wiederherstellen
// ---------------------------------------------------------------
if ($action === 'restore_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $configPath = get_selected_config_path();
    if (!$configPath) {
        http_response_code(409);
        echo json_encode(['error' => 'no_autodrive']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $file = basename($body['file'] ?? ''); // basename() verhindert Path-Traversal

    // Millisekunden-Suffix optional (ältere Backups von vor dessen Einführung haben ihn nicht)
    if (!preg_match('/^' . preg_quote($folder, '/') . '_AutoDrive_config_\d{4}-\d{2}-\d{2}_\d{6}(?:_\d{3})?\.xml$/', $file)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiger Backup-Dateiname.']);
        exit;
    }

    $backupPath = BACKUP_DIR . '/' . $file;
    if (!file_exists($backupPath)) {
        http_response_code(404);
        echo json_encode(['error' => 'Backup nicht gefunden.']);
        exit;
    }

    // Sicherheitsnetz: aktuellen Stand vor dem Zurückspielen selbst sichern
    $safetyBackup = make_backup_filename($folder);
    copy($configPath, $safetyBackup);

    copy($backupPath, $configPath);
    prune_old_backups($folder, 20);

    echo json_encode(['success' => true, 'restoredFrom' => $file]);
    exit;
}

// ---------------------------------------------------------------
// Backup manuell löschen
// ---------------------------------------------------------------
if ($action === 'delete_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $file = basename($body['file'] ?? ''); // basename() verhindert Path-Traversal

    // Millisekunden-Suffix optional (ältere Backups von vor dessen Einführung haben ihn nicht)
    if (!preg_match('/^' . preg_quote($folder, '/') . '_AutoDrive_config_\d{4}-\d{2}-\d{2}_\d{6}(?:_\d{3})?\.xml$/', $file)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiger Backup-Dateiname.']);
        exit;
    }

    $path = BACKUP_DIR . '/' . $file;
    if (!file_exists($path)) {
        http_response_code(404);
        echo json_encode(['error' => 'Backup nicht gefunden.']);
        exit;
    }

    @unlink($path);

    echo json_encode(['success' => true]);
    exit;
}

// ---------------------------------------------------------------
// Vollständiges Spielstand-Backup erstellen (ZIP des kompletten Ordners)
// ---------------------------------------------------------------
if ($action === 'create_full_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $dir = get_general_savegame_dir($folder);
    if (!$dir) {
        http_response_code(404);
        echo json_encode(['error' => 'Spielstand nicht gefunden.']);
        exit;
    }
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        $iniPath = php_ini_loaded_file() ?: null;
        $hint = $iniPath
            ? "Bitte in \"$iniPath\" die Zeile \"extension=zip\" aktivieren (führendes Semikolon entfernen) und den Server neu starten."
            : 'Bitte in der php.ini die Zeile "extension=zip" aktivieren (führendes Semikolon entfernen) und den Server neu starten. Den Pfad der geladenen php.ini zeigt "php --ini" im Terminal.';
        echo json_encode(['error' => 'Die PHP-Erweiterung "zip" ist nicht aktiviert. ' . $hint]);
        exit;
    }

    set_time_limit(180); // große Spielstände (Terrain-Caches etc.) können etwas dauern

    $zipPath = make_full_backup_filename($folder);
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        http_response_code(500);
        echo json_encode(['error' => 'ZIP-Datei konnte nicht angelegt werden.']);
        exit;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
        if (!$file->isFile()) continue;
        $localName = substr($file->getPathname(), strlen($dir) + 1);
        $zip->addFile($file->getPathname(), $localName);
    }
    $zip->close();

    prune_old_full_backups($folder, 5); // große Dateien – bewusst weniger Generationen als bei den AutoDrive-Backups

    echo json_encode(['success' => true, 'file' => basename($zipPath), 'size' => filesize($zipPath)]);
    exit;
}

// ---------------------------------------------------------------
// Vollständige Spielstand-Backups auflisten
// ---------------------------------------------------------------
if ($action === 'list_full_backups' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }

    $files = list_full_backups_for($folder);
    $result = array_map(function ($f) {
        preg_match('/_full_(\d{4}-\d{2}-\d{2}_\d{6})_\d{3}\.zip$/', $f, $m);
        $ts = $m[1] ?? '';
        $formatted = $ts ? sprintf(
            '%s.%s.%s %s:%s:%s',
            substr($ts, 8, 2), substr($ts, 5, 2), substr($ts, 0, 4),
            substr($ts, 11, 2), substr($ts, 13, 2), substr($ts, 15, 2)
        ) : '';
        return ['file' => basename($f), 'formatted' => $formatted, 'size' => filesize($f)];
    }, $files);

    echo json_encode(['backups' => $result]);
    exit;
}

// ---------------------------------------------------------------
// Vollständiges Spielstand-Backup manuell löschen
// ---------------------------------------------------------------
if ($action === 'delete_full_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $file = basename($body['file'] ?? '');

    if (!preg_match('/^' . preg_quote($folder, '/') . '_full_\d{4}-\d{2}-\d{2}_\d{6}_\d{3}\.zip$/', $file)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiger Backup-Dateiname.']);
        exit;
    }

    $path = full_backup_dir() . '/' . $file;
    if (!file_exists($path)) {
        http_response_code(404);
        echo json_encode(['error' => 'Backup nicht gefunden.']);
        exit;
    }

    @unlink($path);

    echo json_encode(['success' => true]);
    exit;
}

// ---------------------------------------------------------------
// Vollständiges Spielstand-Backup herunterladen
// ---------------------------------------------------------------
if ($action === 'download_full_backup' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        exit;
    }

    $file = basename($_GET['file'] ?? '');
    if (!preg_match('/^' . preg_quote($folder, '/') . '_full_\d{4}-\d{2}-\d{2}_\d{6}_\d{3}\.zip$/', $file)) {
        http_response_code(400);
        exit;
    }

    $path = full_backup_dir() . '/' . $file;
    if (!file_exists($path)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// ---------------------------------------------------------------
// Vollständige Kursdaten (alle Wegpunkte + Verbindungen) für die Kartenansicht
// ---------------------------------------------------------------
if ($action === 'course_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (empty($_SESSION['savegame_folder'])) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $configPath = get_selected_config_path();
    if (!$configPath) {
        http_response_code(409);
        echo json_encode(['error' => 'no_autodrive']);
        exit;
    }

    $dom = load_dom($configPath);
    $idsRaw = trim($dom->getElementsByTagName('id')->item(0)->textContent ?? '');
    $xsRaw = trim($dom->getElementsByTagName('x')->item(0)->textContent ?? '');
    $ysRaw = trim($dom->getElementsByTagName('y')->item(0)->textContent ?? '');
    $zsRaw = trim($dom->getElementsByTagName('z')->item(0)->textContent ?? '');
    $outRaw = trim($dom->getElementsByTagName('out')->item(0)->textContent ?? '');
    $flagsRaw = trim($dom->getElementsByTagName('flags')->item(0)->textContent ?? '');

    $ids = $idsRaw !== '' ? explode(',', $idsRaw) : [];
    $xs = $xsRaw !== '' ? array_map('floatval', explode(',', $xsRaw)) : [];
    $ys = $ysRaw !== '' ? array_map('floatval', explode(',', $ysRaw)) : [];
    $zs = $zsRaw !== '' ? array_map('floatval', explode(',', $zsRaw)) : [];
    $outParts = $outRaw !== '' ? explode(';', $outRaw) : [];
    $flags = $flagsRaw !== '' ? explode(',', $flagsRaw) : [];

    $idToIdx = array_flip($ids);
    $edges = [];
    $out = [];
    foreach ($ids as $i => $id) {
        $targets = $outParts[$i] ?? '';
        $targetList = $targets !== '' ? explode(',', $targets) : [];
        $out[] = $targetList;
        foreach ($targetList as $t) {
            if (isset($idToIdx[$t]) && $idToIdx[$t] > $i) {
                // nur einmal pro Kante (i<j), Canvas zeichnet ungerichtet
                $edges[] = [$i, $idToIdx[$t]];
            }
        }
    }

    echo json_encode([
        'ids' => $ids,
        'x' => $xs,
        'y' => $ys,
        'out' => $out,
        'flags' => $flags,
        'z' => $zs,
        'edges' => $edges,
    ]);
    exit;
}

// ---------------------------------------------------------------
// Kursdaten speichern (Wegpunkte hinzufügen/verschieben/verbinden/löschen)
// ---------------------------------------------------------------
if ($action === 'save_course' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $configPath = get_selected_config_path();
    if (!$configPath) {
        http_response_code(409);
        echo json_encode(['error' => 'no_autodrive']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['points']) || !is_array($body['points'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Daten.']);
        exit;
    }
    $points = $body['points'];
    if (count($points) < 2) {
        http_response_code(422);
        echo json_encode(['error' => 'Zu wenige Wegpunkte – Speichern abgebrochen.']);
        exit;
    }

    $dom = load_dom($configPath);

    // Sicherheitsnetz: alle bestehenden Marker müssen weiterhin auf existierende
    // Wegpunkt-IDs zeigen. Sonst würde AutoDrive im Spiel abstürzen oder Marker
    // ins Leere zeigen.
    $newIds = [];
    foreach ($points as $p) {
        $newIds[(string)(int)floatval($p['id'])] = true;
    }

    $markerNode = $dom->getElementsByTagName('mapmarker')->item(0);
    $affectedMarkers = [];
    if ($markerNode) {
        foreach ($markerNode->childNodes as $mm) {
            if ($mm->nodeType !== XML_ELEMENT_NODE) continue;
            $mid = (string)(int)floatval($mm->getElementsByTagName('id')->item(0)->textContent ?? '');
            if (!isset($newIds[$mid])) {
                $mname = trim($mm->getElementsByTagName('name')->item(0)->textContent ?? $mid);
                $affectedMarkers[] = $mname;
            }
        }
    }
    if (!empty($affectedMarkers)) {
        http_response_code(422);
        echo json_encode([
            'error' => 'Diese Marker würden auf gelöschte Wegpunkte zeigen: ' . implode(', ', $affectedMarkers) .
                       '. Bitte Marker vorher im Marker-Tab umhängen oder löschen.',
        ]);
        exit;
    }

    // Backup vor dem Schreiben
    $backupFile = make_backup_filename($folder);
    copy($configPath, $backupFile);
    prune_old_backups($folder, 20);

    // Änderungszeitpunkt der Datei vor dem Überschreiben merken: Steam Cloud vergleicht
    // Zeitstempel, um zu entscheiden, ob der lokale Stand "neuer" ist als die Cloud. Da wir
    // die Datei außerhalb des Spiels bearbeiten, würde jedes Schreiben einen Cloud-Konflikt
    // auslösen, obwohl der Spielstand selbst unverändert bleibt. Zeitstempel danach wieder
    // auf den ursprünglichen Wert setzen umgeht das.
    $originalMTime = filemtime($configPath);

    // Arrays aufbauen; incoming wird aus out neu berechnet (garantiert konsistent)
    $ids = [];
    $xs = [];
    $ys = [];
    $zs = [];
    $outLists = [];
    $flagsList = [];
    $idToIdx = [];

    foreach ($points as $i => $p) {
        $id = (string)(int)floatval($p['id']);
        $ids[] = $id;
        $xs[] = (float)$p['x'];
        $ys[] = (float)($p['y'] ?? 0);
        $zs[] = (float)$p['z'];
        $flagsList[] = (string)(int)($p['flags'] ?? 0);
        $idToIdx[$id] = $i;
    }
    foreach ($points as $p) {
        $targets = [];
        foreach (($p['out'] ?? []) as $t) {
            $tid = (string)(int)floatval($t);
            if (isset($idToIdx[$tid])) $targets[] = $tid;
        }
        $outLists[] = $targets;
    }

    $incomingLists = array_fill(0, count($ids), []);
    foreach ($outLists as $i => $targets) {
        foreach ($targets as $tid) {
            $j = $idToIdx[$tid];
            $incomingLists[$j][] = $ids[$i];
        }
    }

    $dom2 = $dom; // gleiche DOM-Instanz, andere Blöcke werden ersetzt
    $waypointsNode = $dom2->getElementsByTagName('waypoints')->item(0);

    $replaceLeaf = function (string $tag, string $value) use ($dom2, $waypointsNode) {
        $node = $waypointsNode->getElementsByTagName($tag)->item(0);
        if (!$node) {
            $node = $dom2->createElement($tag);
            $waypointsNode->appendChild($node);
        }
        while ($node->firstChild) $node->removeChild($node->firstChild);
        $node->appendChild($dom2->createTextNode($value));
    };

    $replaceLeaf('id', implode(',', $ids));
    $replaceLeaf('x', implode(',', $xs));
    $replaceLeaf('y', implode(',', $ys));
    $replaceLeaf('z', implode(',', $zs));
    $replaceLeaf('out', implode(';', array_map(fn($t) => implode(',', $t), $outLists)));
    $replaceLeaf('incoming', implode(';', array_map(fn($t) => implode(',', $t), $incomingLists)));
    $replaceLeaf('flags', implode(',', $flagsList));

    $dom2->save($configPath);
    if ($originalMTime !== false) touch($configPath, $originalMTime);

    echo json_encode(['success' => true, 'backup' => basename($backupFile), 'count' => count($ids)]);
    exit;
}

// ---------------------------------------------------------------
// Kartenhintergrundbild hochladen (Ingame-Screenshot als Kartenbasis)
// ---------------------------------------------------------------
if ($action === 'upload_terrain' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }

    // Ohne die PHP-Erweiterung "gd" schlägt jede einzelne Bildprüfung weiter unten fehl
    // (function_exists() für imagecreatefrompng/-jpeg/-webp ist dann immer false) – das
    // sah bisher aus wie "Format nicht unterstützt", obwohl PNG und JPEG eigentlich gültig
    // waren. Das hier direkt am Anfang klarstellen statt es als Formatfehler zu tarnen.
    if (!extension_loaded('gd')) {
        http_response_code(500);
        $iniPath = php_ini_loaded_file() ?: null;
        $hint = $iniPath
            ? "Bitte in \"$iniPath\" die Zeile \"extension=gd\" aktivieren (führendes Semikolon entfernen) und den Server neu starten."
            : 'Bitte in der php.ini die Zeile "extension=gd" aktivieren (führendes Semikolon entfernen) und den Server neu starten.';
        echo json_encode(['error' => 'Die PHP-Erweiterung "gd" ist nicht aktiviert (wird für die Bildverarbeitung benötigt). ' . $hint]);
        exit;
    }

    if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        $uploadErr = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadErr === UPLOAD_ERR_INI_SIZE || $uploadErr === UPLOAD_ERR_FORM_SIZE) {
            // Besonders relevant bei großen Kartentexturen (z. B. dekodierte 4096×4096-DDS-Bilder
            // können unkomprimiert als PNG 10-15 MB groß sein) – das PHP-Standardlimit von oft nur
            // 2 MB (upload_max_filesize) greift dann VOR unserer eigenen 25-MB-Prüfung weiter unten,
            // und ohne diese Fallunterscheidung sah das bisher wie "kein Bild empfangen" aus.
            http_response_code(413);
            $iniPath = php_ini_loaded_file() ?: null;
            $currentLimit = ini_get('upload_max_filesize') . ' / post_max_size ' . ini_get('post_max_size');
            $hint = $iniPath
                ? "Bitte in \"$iniPath\" die Werte \"upload_max_filesize\" und \"post_max_size\" erhöhen (z. B. auf 32M) und den Server neu starten."
                : 'Bitte in der php.ini die Werte "upload_max_filesize" und "post_max_size" erhöhen (z. B. auf 32M) und den Server neu starten.';
            echo json_encode(['error' => "Datei überschreitet das aktuelle PHP-Upload-Limit (upload_max_filesize $currentLimit). $hint"]);
            exit;
        }
        http_response_code(400);
        echo json_encode(['error' => 'Kein gültiges Bild empfangen.']);
        exit;
    }

    $maxBytes = 25 * 1024 * 1024;
    if ($_FILES['image']['size'] > $maxBytes) {
        http_response_code(413);
        echo json_encode(['error' => 'Datei zu groß (maximal 25 MB).']);
        exit;
    }

    $assetsDir = __DIR__ . '/assets';
    $destPath = $assetsDir . '/terrain_' . $folder . '.png';
    $result = save_terrain_image_from_path($_FILES['image']['tmp_name'], $destPath);

    if (isset($result['error'])) {
        http_response_code(422);
        echo json_encode($result);
        exit;
    }

    echo json_encode($result);
    exit;
}

// ---------------------------------------------------------------
// Exakte Kartengröße (in Metern) ermitteln, unabhängig davon, ob ein nutzbares
// Kartenbild gefunden wird – wird vom Frontend zur genauen Ausrichtung des
// Hintergrundbilds genutzt (statt der Schätzung anhand der Wegpunkt-Ausdehnung).
// ---------------------------------------------------------------
if ($action === 'map_size_info' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $dir = get_general_savegame_dir($folder);
    if (!$dir) {
        http_response_code(404);
        echo json_encode(['error' => 'Spielstand nicht gefunden.']);
        exit;
    }

    $careerFile = $dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml';
    $mapId = '';
    if (file_exists($careerFile)) {
        libxml_use_internal_errors(true);
        $career = simplexml_load_file($careerFile);
        if ($career && isset($career->settings)) {
            $mapId = (string)($career->settings->mapId ?? '');
        }
    }

    echo json_encode(['size' => find_map_size($mapId)]);
    exit;
}

// ---------------------------------------------------------------
// Kartenhintergrundbild automatisch aus den Moddateien laden
// ---------------------------------------------------------------
if ($action === 'load_map_terrain' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }

    if (!extension_loaded('gd')) {
        http_response_code(500);
        $iniPath = php_ini_loaded_file() ?: null;
        $hint = $iniPath
            ? "Bitte in \"$iniPath\" die Zeile \"extension=gd\" aktivieren (führendes Semikolon entfernen) und den Server neu starten."
            : 'Bitte in der php.ini die Zeile "extension=gd" aktivieren (führendes Semikolon entfernen) und den Server neu starten.';
        echo json_encode(['error' => 'Die PHP-Erweiterung "gd" ist nicht aktiviert (wird für die Bildverarbeitung benötigt). ' . $hint]);
        exit;
    }

    $dir = get_general_savegame_dir($folder);
    if (!$dir) {
        http_response_code(404);
        echo json_encode(['error' => 'Spielstand nicht gefunden.']);
        exit;
    }

    $careerFile = $dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml';
    $mapId = '';
    if (file_exists($careerFile)) {
        libxml_use_internal_errors(true);
        $career = simplexml_load_file($careerFile);
        if ($career && isset($career->settings)) {
            $mapId = (string)($career->settings->mapId ?? '');
        }
    }

    $found = find_map_overview_image($mapId);

    if (!$found['found']) {
        $messages = [
            'no_zip_extension' => 'Die PHP-Erweiterung "zip" wird für die automatische Kartensuche benötigt und ist nicht aktiviert.',
            'no_map_id' => 'Im Spielstand konnte keine Karten-ID gefunden werden.',
            'no_mod_zip' => 'Für diese Karte wurde keine Mod-Datei im "mods"-Ordner gefunden.',
            'zip_open_failed' => 'Die Mod-Datei der Karte konnte nicht geöffnet werden.',
            'no_candidate' => 'Es wurde kein Kartenbild gefunden.',
            'dds_only' => 'Es wurde ein Kartenbild gefunden, aber nur im DDS-Format – das kann dieses Tool nicht lesen (nur PNG/JPEG werden unterstützt).',
            'extract_failed' => 'Das gefundene Kartenbild konnte nicht gelesen werden.',
            'no_install_dir' => 'Das ist eine offizielle GIANTS-Karte ohne Mod-Datei – dafür müsste der Installationsordner des Spiels bekannt sein. Der wurde automatisch nicht gefunden. Trage ihn in config.php unter FS_INSTALL_DIR_OVERRIDE manuell ein, z. B. define(\'FS_INSTALL_DIR_OVERRIDE\', \'D:\\\\SteamLibrary\\\\steamapps\\\\common\\\\Farming Simulator 25\');.',
            'map_dir_not_found' => 'Im Installationsordner des Spiels wurde kein Datenordner für diese Karte gefunden.',
        ];
        $reason = $found['reason'] ?? 'no_candidate';
        http_response_code(404);
        echo json_encode([
            'error' => ($messages[$reason] ?? 'Kein automatisch nutzbares Kartenbild gefunden.') . ' Bitte manuell ein Bild hochladen.',
            'ddsAvailable' => $found['ddsOnly'] ?? false,
        ]);
        exit;
    }

    // Extrahierte Bilddaten in eine temporäre Datei schreiben, damit dieselbe
    // Verarbeitung wie beim manuellen Upload greifen kann (Formatprüfung, Downscale).
    $tmpFile = tempnam(sys_get_temp_dir(), 'mapimg_');
    file_put_contents($tmpFile, $found['data']);

    $assetsDir = __DIR__ . '/assets';
    $destPath = $assetsDir . '/terrain_' . $folder . '.png';
    $result = save_terrain_image_from_path($tmpFile, $destPath);
    @unlink($tmpFile);

    if (isset($result['error'])) {
        http_response_code(422);
        echo json_encode($result);
        exit;
    }

    $result['source'] = $found['sourceName'];
    echo json_encode($result);
    exit;
}

// ---------------------------------------------------------------
// Rohe DDS-Kartentextur ausliefern (für client-seitige Dekodierung im Browser)
// ---------------------------------------------------------------
if ($action === 'fetch_map_dds' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        exit;
    }
    $dir = get_general_savegame_dir($folder);
    if (!$dir) {
        http_response_code(404);
        exit;
    }

    $careerFile = $dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml';
    $mapId = '';
    if (file_exists($careerFile)) {
        libxml_use_internal_errors(true);
        $career = simplexml_load_file($careerFile);
        if ($career && isset($career->settings)) {
            $mapId = (string)($career->settings->mapId ?? '');
        }
    }

    $ddsData = find_map_overview_dds($mapId);
    if ($ddsData === null) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . strlen($ddsData));
    echo $ddsData;
    exit;
}

// ---------------------------------------------------------------
// Systemcheck: prüft PHP-Erweiterungen, Upload-Limits, Schreibrechte und Pfade auf
// einen Blick – entstanden aus mehreren Fällen, in denen genau solche Dinge (gd, zip,
// Upload-Limits, FS_INSTALL_DIR) erst beim Ausprobieren aufgefallen sind statt vorher.
// ---------------------------------------------------------------
if ($action === 'system_check' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $checks = [];

    $checks[] = [
        'label' => 'PHP-Version',
        'status' => version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'warn',
        'detail' => PHP_VERSION,
    ];

    $checks[] = [
        'label' => 'PHP-Erweiterung "gd" (Bildverarbeitung)',
        'status' => extension_loaded('gd') ? 'ok' : 'error',
        'detail' => extension_loaded('gd') ? 'aktiviert' : 'fehlt – Kartenbild-Upload funktioniert nicht',
    ];

    $checks[] = [
        'label' => 'PHP-Erweiterung "zip" (Backups, Mod-Kartensuche)',
        'status' => class_exists('ZipArchive') ? 'ok' : 'error',
        'detail' => class_exists('ZipArchive') ? 'aktiviert' : 'fehlt – vollständige Backups und automatische Kartensuche funktionieren nicht',
    ];

    $checks[] = [
        'label' => 'PHP-Erweiterung "mbstring"',
        'status' => extension_loaded('mbstring') ? 'ok' : 'info',
        'detail' => extension_loaded('mbstring') ? 'aktiviert' : 'nicht aktiviert (Tool kommt bewusst ohne sie aus, kein Handlungsbedarf)',
    ];

    $uploadMax = ini_get('upload_max_filesize');
    $postMax = ini_get('post_max_size');
    $uploadBytes = (int)$uploadMax * (str_contains(strtoupper($uploadMax), 'M') ? 1024 * 1024 : 1);
    $checks[] = [
        'label' => 'Upload-Limit (upload_max_filesize / post_max_size)',
        'status' => $uploadBytes >= 8 * 1024 * 1024 ? 'ok' : 'warn',
        'detail' => "$uploadMax / $postMax" . ($uploadBytes < 8 * 1024 * 1024 ? ' – für große Kartenbilder ggf. zu klein, empfohlen mind. 8M' : ''),
    ];

    $iniPath = php_ini_loaded_file();
    $checks[] = [
        'label' => 'Geladene php.ini',
        'status' => $iniPath ? 'ok' : 'info',
        'detail' => $iniPath ?: 'keine php.ini geladen (nur Standardwerte aktiv)',
    ];

    $checks[] = [
        'label' => 'Spielstand-Ordner (FS_BASE_DIR)',
        'status' => is_dir(FS_BASE_DIR) && is_readable(FS_BASE_DIR) ? 'ok' : 'error',
        'detail' => FS_BASE_DIR,
    ];

    $checks[] = [
        'label' => 'Backup-Ordner beschreibbar',
        'status' => is_dir(BACKUP_DIR) && is_writable(BACKUP_DIR) ? 'ok' : 'error',
        'detail' => BACKUP_DIR,
    ];

    $modsDir = FS_BASE_DIR . DIRECTORY_SEPARATOR . 'mods';
    $checks[] = [
        'label' => 'Mods-Ordner gefunden',
        'status' => is_dir($modsDir) ? 'ok' : 'info',
        'detail' => is_dir($modsDir) ? $modsDir : 'nicht gefunden (nur relevant für automatische Kartenbild-Suche bei Mod-Karten)',
    ];

    $installDir = defined('FS_INSTALL_DIR') ? FS_INSTALL_DIR : '';
    $checks[] = [
        'label' => 'Spiel-Installationsordner (FS_INSTALL_DIR)',
        'status' => $installDir !== '' ? 'ok' : 'info',
        'detail' => $installDir !== '' ? $installDir : 'nicht automatisch gefunden (nur relevant für automatische Kartenbild-Suche bei offiziellen Karten ohne Mod-Datei) – manuell setzbar über FS_INSTALL_DIR_OVERRIDE in config.php',
    ];

    $checks[] = [
        'label' => 'Zeitzone',
        'status' => 'ok',
        'detail' => date_default_timezone_get() . ' · Serverzeit: ' . date('d.m.Y H:i:s'),
    ];

    echo json_encode(['checks' => $checks]);
    exit;
}

// ---------------------------------------------------------------
// Kartenhintergrundbild entfernen
// ---------------------------------------------------------------
if ($action === 'delete_terrain' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }

    $path = __DIR__ . '/assets/terrain_' . $folder . '.png';
    if (file_exists($path)) @unlink($path);

    echo json_encode(['success' => true]);
    exit;
}

// ---------------------------------------------------------------
// Hof-Übersicht (Startseite)
// ---------------------------------------------------------------
if ($action === 'farm_overview' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $dir = FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder;

    $farmInfo = get_farm_info($dir);

    $playTime = null;
    $mapTitle = '';
    $lastSaved = '';
    $careerFile = $dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml';
    if (file_exists($careerFile)) {
        libxml_use_internal_errors(true);
        $career = simplexml_load_file($careerFile);
        if ($career && isset($career->statistics)) {
            $playTime = (float)($career->statistics->playTime ?? 0);
        }
        if ($career && isset($career->settings)) {
            $mapTitle = (string)($career->settings->mapTitle ?? '');
            $lastSaved = (string)($career->settings->saveDateFormatted ?? '');
        }
    }

    $currentDay = 0;
    $envFile = $dir . DIRECTORY_SEPARATOR . 'environment.xml';
    if (file_exists($envFile)) {
        libxml_use_internal_errors(true);
        $env = simplexml_load_file($envFile);
        if ($env) $currentDay = (int)($env->currentDay ?? 0);
    }
    $season = get_current_season($dir, $currentDay);

    $fields = parse_fields($dir);
    $ownedIds = $farmInfo['farmId'] ? get_owned_field_ids($dir, $farmInfo['farmId']) : [];
    $ownedFields = array_filter($fields, fn($f) => isset($ownedIds[$f['id']]));
    $harvestReadyCount = count(array_filter($ownedFields, fn($f) => $f['groundStatus'] === GROUND_STATUS_READY));

    $vehicleCount = $farmInfo['farmId'] ? count_vehicles_for_farm($dir, $farmInfo['farmId']) : 0;

    // Schnellzugriff-Daten, damit die Startseite nicht nur Zahlen, sondern auch
    // konkrete nächste Schritte zeigt.
    $harvestReadyFields = array_values(array_map(
        fn($f) => ['id' => $f['id'], 'fruitTypeLabel' => fruit_type_label($f['fruitType'])],
        array_filter($ownedFields, fn($f) => $f['groundStatus'] === GROUND_STATUS_READY)
    ));

    $vehicles = $farmInfo['farmId'] ? parse_vehicles($dir, $farmInfo['farmId']) : [];
    $vehiclesNeedingAttention = array_values(array_map(
        fn($v) => ['name' => $v['name'], 'wear' => $v['wear'], 'dirt' => $v['dirt']],
        array_filter($vehicles, fn($v) => $v['wear'] > 0.5 || $v['dirt'] > 0.5)
    ));
    usort($vehiclesNeedingAttention, fn($a, $b) => max($b['wear'], $b['dirt']) <=> max($a['wear'], $a['dirt']));

    $missions = parse_missions($dir, $currentDay);
    $missionsToday = count(array_filter($missions, fn($m) => $m['daysLeft'] === 0));

    echo json_encode([
        'farmName' => $farmInfo['farmName'],
        'manager' => $farmInfo['manager'],
        'mapTitle' => $mapTitle,
        'money' => $farmInfo['money'],
        'loan' => $farmInfo['loan'],
        'playTimeHours' => $playTime !== null ? round($playTime / 60, 1) : null,
        'currentDay' => $currentDay,
        'season' => $season,
        'fieldCount' => count($ownedFields),
        'harvestReadyCount' => $harvestReadyCount,
        'vehicleCount' => $vehicleCount,
        'harvestReadyFields' => $harvestReadyFields,
        'vehiclesNeedingAttention' => array_slice($vehiclesNeedingAttention, 0, 5),
        'missionsTodayCount' => $missionsToday,
        'missionsTotalCount' => count($missions),
        'weatherForecast' => get_weather_forecast($dir, $currentDay, 5),
        'lastSaved' => $lastSaved,
    ]);
    exit;
}

// ---------------------------------------------------------------
// Feld-Dashboard
// ---------------------------------------------------------------
if ($action === 'fields_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $dir = FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder;

    $careerFile = $dir . DIRECTORY_SEPARATOR . 'careerSavegame.xml';
    $limeRequired = true;
    if (file_exists($careerFile)) {
        libxml_use_internal_errors(true);
        $career = simplexml_load_file($careerFile);
        if ($career && isset($career->settings->limeRequired)) {
            $limeRequired = ((string)$career->settings->limeRequired) === 'true';
        }
    }

    $farmInfo = get_farm_info($dir);
    $ownedIds = $farmInfo['farmId'] ? get_owned_field_ids($dir, $farmInfo['farmId']) : [];

    $fields = array_values(array_filter(parse_fields($dir), fn($f) => isset($ownedIds[$f['id']])));

    foreach ($fields as &$f) {
        $f['steps'] = suggest_field_steps($f, $limeRequired);
        // Fruchtart wird immer so gezeigt, wie sie tatsächlich im Spielstand steht –
        // UNKNOWN/FALLOW (kein Bewuchs) wird als "–" dargestellt, alles andere unverändert
        // übersetzt. Keine Unterdrückung/Heuristik mehr, die Daten anzweifelt.
        $f['fruitTypeLabel'] = in_array($f['fruitType'], ['UNKNOWN', 'FALLOW'], true) ? null : fruit_type_label($f['fruitType']);
        $f['groundStatusLabel'] = GROUND_STATUS_LABELS[$f['groundStatus']];
    }
    unset($f);

    // Nach Feldnummer sortieren (numerisch), damit die Reihenfolge stabil und nachvollziehbar ist
    usort($fields, fn($a, $b) => (int)$a['id'] - (int)$b['id']);

    echo json_encode(['fields' => $fields]);
    exit;
}

function get_environment_info(string $dir): array {
    $currentDay = 0;
    $daysPerPeriod = 1;
    $envFile = $dir . DIRECTORY_SEPARATOR . 'environment.xml';
    if (file_exists($envFile)) {
        libxml_use_internal_errors(true);
        $env = simplexml_load_file($envFile);
        if ($env) {
            $currentDay = (int)($env->currentDay ?? 0);
            $daysPerPeriod = (int)($env->daysPerPeriod ?? 1);
        }
    }
    return [$currentDay, $daysPerPeriod];
}

// ---------------------------------------------------------------
// Fuhrpark-Dashboard
// ---------------------------------------------------------------
if ($action === 'vehicles_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $dir = FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder;
    $farmInfo = get_farm_info($dir);
    $vehicles = $farmInfo['farmId'] ? parse_vehicles($dir, $farmInfo['farmId']) : [];

    // Standardsortierung: höchster Verschleiß zuerst (dringendster Wartungsbedarf)
    usort($vehicles, fn($a, $b) => $b['wear'] <=> $a['wear']);

    $totalDiesel = 0.0;
    foreach ($vehicles as $v) {
        foreach ($v['fuel'] as $f) {
            if ($f['fillType'] === 'DIESEL') $totalDiesel += $f['liters'];
        }
    }

    echo json_encode([
        'vehicles' => $vehicles,
        'totalCount' => count($vehicles),
        'totalValue' => array_sum(array_column($vehicles, 'price')),
        'needsRepairCount' => count(array_filter($vehicles, fn($v) => $v['wear'] > 0.5)),
        'needsWashCount' => count(array_filter($vehicles, fn($v) => $v['dirt'] > 0.5)),
        'totalDieselLiters' => round($totalDiesel, 1),
    ]);
    exit;
}

// ---------------------------------------------------------------
// Tierbestände
// ---------------------------------------------------------------
if ($action === 'animals_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $dir = FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder;
    $farmInfo = get_farm_info($dir);
    $husbandries = $farmInfo['farmId'] ? parse_husbandries($dir, $farmInfo['farmId']) : [];
    $beehives = $farmInfo['farmId'] ? parse_beehives($dir, $farmInfo['farmId']) : ['hiveCount' => 0, 'pendingHoneyLiters' => 0.0, 'hasSpawner' => false];

    echo json_encode([
        'husbandries' => $husbandries,
        'barnCount' => count($husbandries),
        'totalAnimals' => array_sum(array_column($husbandries, 'totalAnimals')),
        'beehives' => $beehives,
    ]);
    exit;
}

// ---------------------------------------------------------------
// Produktionsketten
// ---------------------------------------------------------------
if ($action === 'production_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $dir = FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder;
    $farmInfo = get_farm_info($dir);
    $points = $farmInfo['farmId'] ? parse_production_points($dir, $farmInfo['farmId']) : [];

    echo json_encode([
        'productionPoints' => $points,
        'pointCount' => count($points),
    ]);
    exit;
}

// ---------------------------------------------------------------
// Hofnamen ändern (im Singleplayer im Spiel selbst nicht möglich, steht dort
// immer als "Mein Hof" o. ä. fest – der Name lässt sich aber direkt in farms.xml
// ändern, ohne dass das Spiel etwas dagegen hat)
// ---------------------------------------------------------------
if ($action === 'update_farm_name' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $dir = get_general_savegame_dir($folder);
    if (!$dir) {
        http_response_code(404);
        echo json_encode(['error' => 'Spielstand nicht gefunden.']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $name = trim($body['name'] ?? '');
    if ($name === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Hofname darf nicht leer sein.']);
        exit;
    }
    if (strlen($name) > 96) {
        http_response_code(422);
        echo json_encode(['error' => 'Hofname darf maximal 64 Zeichen lang sein.']);
        exit;
    }

    $farmsFile = $dir . DIRECTORY_SEPARATOR . 'farms.xml';
    if (!file_exists($farmsFile)) {
        http_response_code(404);
        echo json_encode(['error' => 'farms.xml nicht gefunden.']);
        exit;
    }

    $dom = new DOMDocument('1.0', 'utf-8');
    $dom->preserveWhiteSpace = true;
    $dom->formatOutput = false;
    libxml_use_internal_errors(true);
    if (!$dom->load($farmsFile, LIBXML_PARSEHUGE)) {
        http_response_code(500);
        echo json_encode(['error' => 'farms.xml konnte nicht gelesen werden.']);
        exit;
    }

    // Gleiche Logik wie get_farm_info(): erste echte Farm (farmId != 0, das ist die
    // Umwelt-/Vorbesitzer-Sammelfarm)
    $targetFarm = null;
    foreach ($dom->getElementsByTagName('farm') as $farmNode) {
        if ($farmNode->getAttribute('farmId') !== '0') { $targetFarm = $farmNode; break; }
    }
    if (!$targetFarm) {
        http_response_code(404);
        echo json_encode(['error' => 'Keine eigene Farm in diesem Spielstand gefunden.']);
        exit;
    }

    // Backup vor dem Schreiben, gleiches Sicherheitsnetz wie bei den AutoDrive-Speicherungen
    $backupFile = make_farms_backup_filename($folder);
    copy($farmsFile, $backupFile);
    prune_old_farms_backups($folder, 10);

    // Zeitstempel erhalten, damit Steam Cloud keinen falschen Synchronisationskonflikt meldet
    $originalMTime = filemtime($farmsFile);

    $targetFarm->setAttribute('name', $name);
    $dom->save($farmsFile);
    if ($originalMTime !== false) touch($farmsFile, $originalMTime);

    echo json_encode(['success' => true, 'name' => $name]);
    exit;
}

// ---------------------------------------------------------------
// Marktpreise / Verkaufsplaner
// ---------------------------------------------------------------
if ($action === 'market_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $dir = FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder;
    [$currentDay, $daysPerPeriod] = get_environment_info($dir);

    $farmInfo = get_farm_info($dir);
    $ownedIds = $farmInfo['farmId'] ? get_owned_field_ids($dir, $farmInfo['farmId']) : [];
    $ownedFruitTypes = [];
    foreach (parse_fields($dir) as $f) {
        if (isset($ownedIds[$f['id']])) $ownedFruitTypes[$f['fruitType']] = true;
    }

    $market = parse_market_data($dir, $currentDay, $daysPerPeriod);
    foreach ($market as &$m) {
        $m['isOwnCrop'] = isset($ownedFruitTypes[$m['fruitType']]);
    }
    unset($m);

    echo json_encode([
        'currentPeriodLabel' => MARKET_PERIOD_LABELS_DE[MARKET_PERIOD_ORDER[get_current_period_index($currentDay, $daysPerPeriod)]],
        'market' => $market,
    ]);
    exit;
}

// ---------------------------------------------------------------
// Vertrags-Feed
// ---------------------------------------------------------------
if ($action === 'missions_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $folder = $_SESSION['savegame_folder'] ?? null;
    if (!$folder) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $dir = FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder;
    [$currentDay,] = get_environment_info($dir);

    $missions = parse_missions($dir, $currentDay);

    echo json_encode(['missions' => $missions, 'currentDay' => $currentDay]);
    exit;
}

// ---------------------------------------------------------------
// Marker lesen
// ---------------------------------------------------------------
if ($action === 'markers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (empty($_SESSION['savegame_folder'])) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $configPath = get_selected_config_path();
    if (!$configPath) {
        http_response_code(409);
        echo json_encode(['error' => 'no_autodrive']);
        exit;
    }

    $dom = load_dom($configPath);
    $markerNode = $dom->getElementsByTagName('mapmarker')->item(0);
    $result = [];
    $groups = [];

    if ($markerNode) {
        foreach ($markerNode->childNodes as $mm) {
            if ($mm->nodeType !== XML_ELEMENT_NODE) continue;
            $id = trim($mm->getElementsByTagName('id')->item(0)->textContent ?? '');
            $name = trim($mm->getElementsByTagName('name')->item(0)->textContent ?? '');
            $groupNode = $mm->getElementsByTagName('group')->item(0);
            $group = $groupNode ? trim($groupNode->textContent) : '';
            $result[] = ['key' => $mm->nodeName, 'id' => $id, 'name' => $name, 'group' => $group];
            if ($group !== '') $groups[$group] = true;
        }
    }

    $folder = $_SESSION['savegame_folder'];
    $farmInfo = get_farm_info(FS_BASE_DIR . DIRECTORY_SEPARATOR . $folder);

    echo json_encode([
        'markers' => $result,
        'groups' => array_keys($groups),
        'mapName' => $dom->getElementsByTagName('MapName')->item(0)->textContent ?? '',
        'farmName' => $farmInfo['farmName'],
        'manager' => $farmInfo['manager'],
    ]);
    exit;
}

// ---------------------------------------------------------------
// Marker speichern
// ---------------------------------------------------------------
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['savegame_folder'])) {
        http_response_code(409);
        echo json_encode(['error' => 'no_savegame_selected']);
        exit;
    }
    $configPath = get_selected_config_path();
    if (!$configPath) {
        http_response_code(409);
        echo json_encode(['error' => 'no_autodrive']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body) || !isset($body['markers'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige Daten.']);
        exit;
    }

    $dom = load_dom($configPath);
    $validIds = get_valid_waypoint_ids($dom);

    foreach ($body['markers'] as $m) {
        $id = (string)(int)floatval($m['id']);
        if (!isset($validIds[$id])) {
            http_response_code(422);
            echo json_encode(['error' => "Wegpunkt-ID {$m['id']} existiert nicht im Spielstand."]);
            exit;
        }
        if (trim($m['name']) === '') {
            http_response_code(422);
            echo json_encode(['error' => 'Marker-Name darf nicht leer sein.']);
            exit;
        }
    }

    $folder = $_SESSION['savegame_folder'];
    $backupFile = make_backup_filename($folder);
    copy($configPath, $backupFile);
    prune_old_backups($folder, 20);

    // Siehe Kommentar bei save_course: Zeitstempel erhalten, damit Steam Cloud beim
    // nächsten Spielstart nicht fälschlich einen Synchronisationskonflikt meldet.
    $originalMTime = filemtime($configPath);

    $markerNode = $dom->getElementsByTagName('mapmarker')->item(0);
    $autoDriveRoot = $dom->getElementsByTagName('AutoDrive')->item(0);

    if (!$markerNode) {
        $markerNode = $dom->createElement('mapmarker');
        $autoDriveRoot->appendChild($markerNode);
    }

    while ($markerNode->firstChild) {
        $markerNode->removeChild($markerNode->firstChild);
    }

    $i = 1;
    foreach ($body['markers'] as $m) {
        $mm = $dom->createElement('mm' . $i);
        $idEl = $dom->createElement('id', (string)(int)floatval($m['id']) . '.000000');
        $nameEl = $dom->createElement('name');
        $nameEl->appendChild($dom->createTextNode($m['name']));
        $mm->appendChild($idEl);
        $mm->appendChild($nameEl);
        if (!empty($m['group'])) {
            $groupEl = $dom->createElement('group');
            $groupEl->appendChild($dom->createTextNode($m['group']));
            $mm->appendChild($groupEl);
        }
        $markerNode->appendChild($mm);
        $i++;
    }

    $dom->save($configPath);
    if ($originalMTime !== false) touch($configPath, $originalMTime);

    echo json_encode(['success' => true, 'backup' => basename($backupFile), 'count' => count($body['markers'])]);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Unbekannte Aktion.']);
