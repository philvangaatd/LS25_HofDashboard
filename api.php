<?php
// Ohne explizite Zeitzone verwendet PHP je nach Serverkonfiguration UTC, wodurch
// Backup-Zeitstempel von der tatsächlichen lokalen Zeit abweichen können.
date_default_timezone_set('Europe/Berlin');

require __DIR__ . '/config.php';
require __DIR__ . '/app/ApiResponseService.php';
require __DIR__ . '/app/SystemCheckController.php';
require __DIR__ . '/app/LiveDataService.php';
require __DIR__ . '/app/SavegameService.php';
require __DIR__ . '/app/SavegameController.php';
require __DIR__ . '/app/BackupService.php';
require __DIR__ . '/app/MapAssetService.php';
require __DIR__ . '/app/TerrainController.php';
require __DIR__ . '/app/AutoDriveService.php';
require __DIR__ . '/app/AutoDriveCourseController.php';
require __DIR__ . '/app/AutoDriveMarkerController.php';
require __DIR__ . '/app/AutoDriveBackupController.php';
require __DIR__ . '/app/FullBackupController.php';
require __DIR__ . '/app/UserSettingsController.php';
require __DIR__ . '/app/DashboardDataController.php';
require __DIR__ . '/app/FarmController.php';
require __DIR__ . '/user_settings.php';
require __DIR__ . '/production_data.php';

header('Content-Type: application/json; charset=utf-8');

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
// Echte Kraftstoffarten in <fillUnit>-Tanks// Echte Kraftstoffarten in <fillUnit>-Tanks (nicht ALLE fillType-Werte dort sind
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

// -----------------------------------------------------------------
// Tierbestände (Herden/Ställe aus placeables.xml)// -----------------------------------------------------------------
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

        // Flüssige Lagerstände (Milch, Wolle usw.) direkt aus fillUnit-Elementen lesen.
        // Wir schließen Futter- und Betriebsstoffe aus (werden in anderer Anzeige oder gar nicht benötigt).
        $STORAGE_EXCLUDE = ['GRASS','FORAGE','DRYGRASS','WATER','STRAW','MIXEDRATION',
                            'TREETRUNKPOPLAR','WOODCHIPS','CHAFF','SILAGE','LIQUIDMANURE',
                            'MANURE','DIGESTATE','DIESEL','DEF'];
        $storageFills = [];
        $seenFillTypes = [];
        foreach ($p->getElementsByTagName('fillUnit') as $fu) {
            $ft  = $fu->getAttribute('fillType');
            $lvl = (float)$fu->getAttribute('fillLevel');
            $cap = (float)$fu->getAttribute('capacity');
            if ($ft === '' || $cap <= 0) continue;
            if (in_array($ft, $STORAGE_EXCLUDE)) continue;
            if (isset($seenFillTypes[$ft])) continue;  // Duplikate überspringen
            $seenFillTypes[$ft] = true;
            $storageFills[] = [
                'fillType' => $ft,
                'label'    => fill_type_label($ft),
                'level'    => round($lvl, 1),
                'capacity' => round($cap, 1),
                'percent'  => round(min(100, $lvl / $cap * 100)),
            ];
        }

        $result[] = [
            'uniqueId' => $p->getAttribute('uniqueId'),
            'name' => readable_barn_name($p->getAttribute('filename')),
            'totalAnimals' => $total,
            'species' => $speciesList,
            'meadow' => $meadow,
            'hasBreedingData' => $anyBreedingData,
            'pendingProduct' => $pendingProduct,
            'storageFills' => $storageFills,
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
    // + 12) % 12 stellt sicher dass das Ergebnis immer 0-11 ist, auch wenn currentDay = 0
    return (((int)(floor(($currentDay - 1) / $daysPerPeriod)) % 12) + 12) % 12;
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

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

// ---------------------------------------------------------------
// Spielstandsbezogene Benutzereinstellungen
// ---------------------------------------------------------------
if ($action === 'user_settings') {
    handle_user_settings();
    exit;
}

// ---------------------------------------------------------------
// Spielstände auflisten (Auswahlmaske)
// ---------------------------------------------------------------
if ($action === 'list_savegames' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_savegames_list();
    exit;
}

// ---------------------------------------------------------------
// Spielstand auswählen
// ---------------------------------------------------------------
if ($action === 'select_savegame' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_savegame_select();
    exit;
}

// ---------------------------------------------------------------
// Aktuell ausgewählten Spielstand abfragen
// ---------------------------------------------------------------
if ($action === 'current_savegame' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_current_savegame();
    exit;
}

// ---------------------------------------------------------------
// Spielstand-Auswahl aufheben (zurück zur Auswahlmaske)
// ---------------------------------------------------------------
if ($action === 'clear_savegame' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_savegame_clear();
    exit;
}

// ---------------------------------------------------------------
// Backups auflisten
// ---------------------------------------------------------------
if ($action === 'list_backups' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_autodrive_backups_list();
    exit;
}

// ---------------------------------------------------------------
// Backup wiederherstellen
// ---------------------------------------------------------------
if ($action === 'restore_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_autodrive_backup_restore();
    exit;
}

// ---------------------------------------------------------------
// Backup manuell löschen
// ---------------------------------------------------------------
if ($action === 'delete_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_autodrive_backup_delete();
    exit;
}

// ---------------------------------------------------------------
// Vollständiges Spielstand-Backup erstellen (ZIP des kompletten Ordners)
// ---------------------------------------------------------------
if ($action === 'create_full_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_full_backup_create();
    exit;
}

// ---------------------------------------------------------------
// Vollständige Spielstand-Backups auflisten
// ---------------------------------------------------------------
if ($action === 'list_full_backups' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_full_backups_list();
    exit;
}

// ---------------------------------------------------------------
// Vollständiges Spielstand-Backup manuell löschen
// ---------------------------------------------------------------
if ($action === 'delete_full_backup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_full_backup_delete();
    exit;
}

// ---------------------------------------------------------------
// Vollständiges Spielstand-Backup herunterladen
// ---------------------------------------------------------------
if ($action === 'download_full_backup' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_full_backup_download();
    exit;
}

// ---------------------------------------------------------------
// Vollständige Kursdaten (alle Wegpunkte + Verbindungen) für die Kartenansicht
// ---------------------------------------------------------------
if ($action === 'course_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_autodrive_course_data();
    exit;
}

// ---------------------------------------------------------------
// Kartenhintergrundbild hochladen (Ingame-Screenshot als Kartenbasis)
// ---------------------------------------------------------------
if ($action === 'upload_terrain' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_terrain_upload();
    exit;
}

// ---------------------------------------------------------------
// Exakte Kartengröße (in Metern) ermitteln, unabhängig davon, ob ein nutzbares
// Kartenbild gefunden wird – wird vom Frontend zur genauen Ausrichtung des
// Hintergrundbilds genutzt (statt der Schätzung anhand der Wegpunkt-Ausdehnung).
// ---------------------------------------------------------------
if ($action === 'map_size_info' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_map_size_info();
    exit;
}

// ---------------------------------------------------------------
// Kartenhintergrundbild automatisch aus den Moddateien laden
// ---------------------------------------------------------------
if ($action === 'load_map_terrain' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_load_map_terrain();
    exit;
}

// ---------------------------------------------------------------
// Rohe DDS-Kartentextur ausliefern (für client-seitige Dekodierung im Browser)
// ---------------------------------------------------------------
if ($action === 'fetch_map_dds' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_fetch_map_dds();
    exit;
}

// ---------------------------------------------------------------
// Systemcheck: prüft PHP-Erweiterungen, Upload-Limits, Schreibrechte und Pfade auf
// einen Blick – entstanden aus mehreren Fällen, in denen genau solche Dinge (gd, zip,
// Upload-Limits, FS_INSTALL_DIR) erst beim Ausprobieren aufgefallen sind statt vorher.
// ---------------------------------------------------------------
if ($action === 'system_check' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_system_check();
    exit;
}

// ---------------------------------------------------------------
// Persistentes Kartenhintergrundbild ausliefern. Benutzerbilder werden im
// App-Datenordner bevorzugt; mitgelieferte Bilder dienen als Fallback.
// ---------------------------------------------------------------
if ($action === 'terrain_image' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_terrain_image();
    exit;
}

// ---------------------------------------------------------------
// Kartenhintergrundbild entfernen
// ---------------------------------------------------------------
if ($action === 'delete_terrain' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_terrain_delete();
    exit;
}

// ---------------------------------------------------------------
// Hof-Übersicht (Startseite)
// ---------------------------------------------------------------
if ($action === 'farm_overview' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_farm_overview();
    exit;
}

// ---------------------------------------------------------------
// Feld-Dashboard – kanonische Live-Daten aus Lua
// ---------------------------------------------------------------
if ($action === 'fields_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_fields_data();
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
// Fuhrpark-Dashboard – kanonische Live-Daten aus Lua
// ---------------------------------------------------------------
if ($action === 'vehicles_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_vehicles_data();
    exit;
}

// ---------------------------------------------------------------
// Tierbestände – kanonische Live-Daten aus Lua
// ---------------------------------------------------------------
if ($action === 'animals_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_animals_data();
    exit;
}

// ---------------------------------------------------------------
// Produktionsketten
// ---------------------------------------------------------------
if ($action === 'production_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_production_data();
    exit;
}

// ---------------------------------------------------------------
// Hofnamen ändern (im Singleplayer im Spiel selbst nicht möglich, steht dort
// immer als "Mein Hof" o. ä. fest – der Name lässt sich aber direkt in farms.xml
// ändern, ohne dass das Spiel etwas dagegen hat)
// ---------------------------------------------------------------
if ($action === 'update_farm_name' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_farm_name_update();
    exit;
}

// ---------------------------------------------------------------
// Marktpreise / Verkaufsplaner – echte Livepreise je Verkaufsstation
// ---------------------------------------------------------------
if ($action === 'market_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_market_data();
    exit;
}

// ---------------------------------------------------------------
// Vertrags-Feed
// ---------------------------------------------------------------
if ($action === 'missions_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_missions_data();
    exit;
}

// ---------------------------------------------------------------
// Marker lesen
// ---------------------------------------------------------------
if ($action === 'markers' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    handle_autodrive_markers_get();
    exit;
}

// ---------------------------------------------------------------
// Marker speichern
// ---------------------------------------------------------------
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_autodrive_markers_save();
    exit;
}

// ---------------------------------------------------------------
// Live-Daten aus Mod-Export lesen (modSettings/LS25HofDashboard/liveData.json)
// ---------------------------------------------------------------
if ($action === 'live_data') {
    handle_live_data();
    exit;
}

api_json_error('Unbekannte Aktion.', 404);
