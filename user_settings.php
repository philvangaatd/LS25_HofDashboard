<?php
declare(strict_types=1);

/**
 * Standardwerte für spielstandsbezogene Nutzerentscheidungen.
 */
function default_user_settings(): array
{
    return [
        'schemaVersion' => 1,
        'terrainAlign' => [
            'offsetX' => 0.0,
            'offsetZ' => 0.0,
            'scale' => 1.0,
        ],
        'priceAlerts' => [],
        'fieldTasks' => [],
    ];
}

function user_settings_path(string $folder): ?string
{
    if (!preg_match('/^savegame\d+$/', $folder)) {
        return null;
    }

    return USER_SETTINGS_DIR . DIRECTORY_SEPARATOR . $folder . '.json';
}

function sanitize_user_setting_number(
    mixed $value,
    float $minimum,
    float $maximum,
    float $fallback
): float {
    if (!is_numeric($value)) {
        return $fallback;
    }

    $number = (float)$value;
    if (!is_finite($number)) {
        return $fallback;
    }

    return max($minimum, min($maximum, $number));
}

/**
 * Begrenzt die vom lokalen Frontend übergebenen Daten auf den bekannten Vertrag.
 */
function sanitize_user_settings(mixed $raw): array
{
    $settings = default_user_settings();
    if (!is_array($raw)) {
        return $settings;
    }

    $terrain = is_array($raw['terrainAlign'] ?? null) ? $raw['terrainAlign'] : [];
    $settings['terrainAlign'] = [
        'offsetX' => sanitize_user_setting_number($terrain['offsetX'] ?? null, -1000000.0, 1000000.0, 0.0),
        'offsetZ' => sanitize_user_setting_number($terrain['offsetZ'] ?? null, -1000000.0, 1000000.0, 0.0),
        'scale' => sanitize_user_setting_number($terrain['scale'] ?? null, 0.01, 100.0, 1.0),
    ];

    $priceAlerts = is_array($raw['priceAlerts'] ?? null) ? $raw['priceAlerts'] : [];
    foreach (array_slice($priceAlerts, 0, 500, true) as $fruitType => $value) {
        if (!is_string($fruitType)
            || !preg_match('/^[A-Za-z0-9_.:-]{1,80}$/', $fruitType)
            || !is_numeric($value)) {
            continue;
        }

        $price = (float)$value;
        if (is_finite($price) && $price > 0 && $price <= 1000000000) {
            $settings['priceAlerts'][$fruitType] = $price;
        }
    }

    $fieldTasks = is_array($raw['fieldTasks'] ?? null) ? $raw['fieldTasks'] : [];
    foreach (array_slice($fieldTasks, 0, 2000, true) as $taskKey => $completed) {
        if (is_string($taskKey)
            && mb_strlen($taskKey, 'UTF-8') <= 300
            && $completed === true) {
            $settings['fieldTasks'][$taskKey] = true;
        }
    }

    return $settings;
}

function load_user_settings(string $folder): array
{
    $path = user_settings_path($folder);
    if ($path === null || !is_file($path)) {
        return default_user_settings();
    }

    $json = file_get_contents($path);
    if ($json === false || trim($json) === '') {
        return default_user_settings();
    }

    try {
        return sanitize_user_settings(json_decode($json, true, 32, JSON_THROW_ON_ERROR));
    } catch (JsonException) {
        return default_user_settings();
    }
}

function save_user_settings(string $folder, mixed $raw): array
{
    $path = user_settings_path($folder);
    if ($path === null) {
        throw new InvalidArgumentException('Ungültiger Spielstand-Ordner.');
    }

    $settings = sanitize_user_settings($raw);
    $json = json_encode(
        $settings,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL;

    if (file_put_contents($path, $json, LOCK_EX) === false) {
        throw new RuntimeException('Benutzereinstellungen konnten nicht gespeichert werden.');
    }

    return $settings;
}
