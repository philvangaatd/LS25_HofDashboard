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
        'storages' => [],
    ],
]);

expect_production_test(count($points) === 1, 'Produktionsanlage fehlt.');
expect_production_test($points[0]['activeCount'] === 1, 'Aktivzähler ist falsch.');
expect_production_test(count($points[0]['productions']) === 2, 'Produktionsketten fehlen.');
expect_production_test($points[0]['productions'][0]['label'] === 'Tomaten', 'Produktionsname fehlt.');
expect_production_test($points[0]['productions'][0]['enabled'] === true, 'Aktive Produktion wurde nicht erkannt.');
expect_production_test($points[0]['productions'][1]['enabled'] === false, 'Inaktive Produktion wurde falsch aktiviert.');
expect_production_test($points[0]['productions'][0]['status'] === '2', 'Produktionsstatus wurde nicht stabil normalisiert.');

echo "production_data_test: ok\n";
