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
        $inputFillTypes = [];
        $outputFillTypes = [];
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

            if (!$enabled) {
                continue;
            }

            $inputs = is_array($liveProduction['inputs'] ?? null)
                ? array_values(array_filter(
                    $liveProduction['inputs'],
                    static fn(mixed $input): bool => is_array($input)
                ))
                : [];
            $outputs = is_array($liveProduction['outputs'] ?? null)
                ? array_values(array_filter(
                    $liveProduction['outputs'],
                    static fn(mixed $output): bool => is_array($output)
                ))
                : [];

            foreach ($inputs as $input) {
                $fillType = trim((string)($input['fillType'] ?? ''));
                if ($fillType !== '') {
                    $inputFillTypes[strtoupper($fillType)] = $fillType;
                }
            }
            foreach ($outputs as $output) {
                $fillType = trim((string)($output['fillType'] ?? ''));
                if ($fillType !== '') {
                    $outputFillTypes[strtoupper($fillType)] = $fillType;
                }
            }

            $productions[] = [
                'id' => $id,
                'name' => $name,
                'label' => $name !== '' ? $name : ($id !== '' ? $id : 'Produktion'),
                'enabled' => true,
                'status' => (string)($liveProduction['status'] ?? ''),
                'cyclesPerHour' => (float)($liveProduction['cyclesPerHour'] ?? 0),
                'inputs' => $inputs,
                'outputs' => $outputs,
            ];
        }

        if ($productions === []) {
            continue;
        }

        $storagesByFillType = [];
        $liveStorages = is_array($livePoint['storages'] ?? null)
            ? $livePoint['storages']
            : [];
        foreach ($liveStorages as $liveStorage) {
            if (!is_array($liveStorage)) {
                continue;
            }

            $fillType = trim((string)($liveStorage['fillType'] ?? ''));
            $fillTypeKey = strtoupper($fillType);
            $isInput = isset($inputFillTypes[$fillTypeKey]);
            $isOutput = isset($outputFillTypes[$fillTypeKey]);
            if ($fillTypeKey === '' || (!$isInput && !$isOutput)) {
                continue;
            }

            $level = max(0, (int)($liveStorage['level'] ?? 0));
            $capacity = max(0, (int)($liveStorage['capacity'] ?? 0));
            $percent = $capacity > 0
                ? (int)round(min(100, $level / $capacity * 100))
                : 0;

            $storagesByFillType[$fillTypeKey] = [
                'fillType' => $fillType,
                'title' => trim((string)($liveStorage['title'] ?? '')),
                'role' => $isInput && $isOutput ? 'input_output' : ($isInput ? 'input' : 'output'),
                'level' => $level,
                'capacity' => $capacity,
                'percent' => $percent,
            ];
        }

        foreach ($inputFillTypes + $outputFillTypes as $fillTypeKey => $fillType) {
            if (!isset($storagesByFillType[$fillTypeKey])) {
                $storagesByFillType[$fillTypeKey] = [
                    'fillType' => $fillType,
                    'title' => '',
                    'role' => isset($inputFillTypes[$fillTypeKey])
                        && isset($outputFillTypes[$fillTypeKey])
                            ? 'input_output'
                            : (isset($inputFillTypes[$fillTypeKey]) ? 'input' : 'output'),
                    'level' => 0,
                    'capacity' => 0,
                    'percent' => 0,
                ];
            }
        }

        $storages = array_values($storagesByFillType);
        usort($storages, static function (array $left, array $right): int {
            $leftLabel = $left['title'] !== '' ? $left['title'] : $left['fillType'];
            $rightLabel = $right['title'] !== '' ? $right['title'] : $right['fillType'];
            return strnatcasecmp($leftLabel, $rightLabel);
        });

        $inputStorages = array_values(array_filter(
            $storages,
            static fn(array $storage): bool => $storage['role'] === 'input'
                || $storage['role'] === 'input_output'
        ));
        $outputStorages = array_values(array_filter(
            $storages,
            static fn(array $storage): bool => $storage['role'] === 'output'
                || $storage['role'] === 'input_output'
        ));
        $water = null;
        foreach ($inputStorages as $storage) {
            if (strtoupper($storage['fillType']) === 'WATER') {
                $water = $storage;
                break;
            }
        }

        $points[] = [
            'name' => (string)($livePoint['name'] ?? ''),
            'farmId' => (int)($livePoint['farmId'] ?? 0),
            'productions' => $productions,
            'activeCount' => count($productions),
            'storages' => $storages,
            'inputStorages' => $inputStorages,
            'outputStorages' => $outputStorages,
            'water' => $water,
        ];
    }

    return $points;
}
