<?php
declare(strict_types=1);

require dirname(__DIR__) . '/production_data.php';

function expect_production_test(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$points = normalize_live_production_points([
    [
        'name' => 'Mittleres Foliengewächshaus',
        'farmId' => 1,
        'productions' => [
            [
                'id' => 'tomato',
                'name' => 'Tomaten',
                'enabled' => true,
                'status' => 2,
                'cyclesPerHour' => 44,
                'inputs' => [['fillType' => 'WATER', 'amount' => 1]],
                'outputs' => [['fillType' => 'TOMATO', 'amount' => 1]],
            ],
            [
                'id' => 'strawberry',
                'name' => 'Erdbeeren',
                'enabled' => false,
                'status' => 0,
            ],
        ],
        'storages' => [
            [
                'fillType' => 'WATER',
                'title' => 'Wasser',
                'role' => 'input',
                'level' => 6800,
                'capacity' => 10000,
                'percent' => 68,
            ],
            [
                'fillType' => 'TOMATO',
                'title' => 'Tomaten',
                'role' => 'output',
                'level' => 1250,
                'capacity' => 5000,
                'percent' => 25,
            ],
            [
                'fillType' => 'STRAWBERRY',
                'title' => 'Erdbeeren',
                'role' => 'output',
                'level' => 400,
                'capacity' => 5000,
                'percent' => 8,
            ],
        ],
    ],
]);

expect_production_test(count($points) === 1, 'Produktionsanlage fehlt.');
expect_production_test($points[0]['activeCount'] === 1, 'Aktivzähler ist falsch.');
expect_production_test(count($points[0]['productions']) === 1, 'Es dürfen nur aktive Produktionsketten ausgegeben werden.');
expect_production_test($points[0]['productions'][0]['label'] === 'Tomaten', 'Produktionsname fehlt.');
expect_production_test($points[0]['productions'][0]['enabled'] === true, 'Aktive Produktion wurde nicht erkannt.');
expect_production_test($points[0]['productions'][0]['status'] === '2', 'Produktionsstatus wurde nicht stabil normalisiert.');
expect_production_test($points[0]['water']['fillType'] === 'WATER', 'Wasserbestand fehlt.');
expect_production_test($points[0]['water']['level'] === 6800, 'Wassermenge ist falsch.');
expect_production_test(count($points[0]['inputStorages']) === 1, 'Aktiver Betriebsstoff fehlt.');
expect_production_test(count($points[0]['outputStorages']) === 1, 'Es darf nur das Produkt einer aktiven Produktion erscheinen.');
expect_production_test($points[0]['outputStorages'][0]['fillType'] === 'TOMATO', 'Produzierte Tomaten fehlen.');
expect_production_test($points[0]['outputStorages'][0]['level'] === 1250, 'Produzierte Tomatenmenge ist falsch.');

echo "production_data_test: ok\n";
