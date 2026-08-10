<?php
declare(strict_types=1);

/**
 * Normalisiert die von der Live-Mod exportierten Produktionsanlagen für das
 * Frontend. Der Vertrag bleibt damit an einer Stelle definiert und kann ohne
 * laufenden LS25-Prozess getestet werden.
 */
function normalize_live_production_points(array $liveProductionPoints): array
{
    $points = [];

    foreach ($liveProductionPoints as $livePoint) {
        if (!is_array($livePoint)) {
            continue;
        }

        $productions = [];
        $liveProductions = is_array($livePoint['productions'] ?? null)
            ? $livePoint['productions']
            : [];

        foreach ($liveProductions as $liveProduction) {
            if (!is_array($liveProduction)) {
                continue;
            }

            $id = trim((string)($liveProduction['id'] ?? ''));
            $name = trim((string)($liveProduction['name'] ?? ''));
            $enabledValue = $liveProduction['enabled'] ?? false;
            $enabled = $enabledValue === true
                || $enabledValue === 1
                || $enabledValue === '1'
                || $enabledValue === 'true';

            $productions[] = [
                'id' => $id,
                'name' => $name,
                'label' => $name !== '' ? $name : ($id !== '' ? $id : 'Produktion'),
                'enabled' => $enabled,
                'status' => (string)($liveProduction['status'] ?? ''),
                'cyclesPerHour' => (float)($liveProduction['cyclesPerHour'] ?? 0),
                'inputs' => is_array($liveProduction['inputs'] ?? null)
                    ? array_values($liveProduction['inputs'])
                    : [],
                'outputs' => is_array($liveProduction['outputs'] ?? null)
                    ? array_values($liveProduction['outputs'])
                    : [],
            ];
        }

        $storages = [];
        $liveStorages = is_array($livePoint['storages'] ?? null)
            ? $livePoint['storages']
            : [];
        foreach ($liveStorages as $liveStorage) {
            if (!is_array($liveStorage)) {
                continue;
            }

            $storages[] = [
                'fillType' => (string)($liveStorage['fillType'] ?? ''),
                'title' => (string)($liveStorage['title'] ?? ''),
                'level' => (int)($liveStorage['level'] ?? 0),
                'capacity' => (int)($liveStorage['capacity'] ?? 0),
                'percent' => (int)($liveStorage['percent'] ?? 0),
            ];
        }

        $points[] = [
            'name' => (string)($livePoint['name'] ?? ''),
            'farmId' => (int)($livePoint['farmId'] ?? 0),
            'productions' => $productions,
            'activeCount' => count(array_filter(
                $productions,
                static fn(array $production): bool => $production['enabled']
            )),
            'storages' => $storages,
        ];
    }

    return $points;
}
