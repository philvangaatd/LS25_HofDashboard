from pathlib import Path

path = Path('api.php')
text = path.read_text(encoding='utf-8')

start = text.find('function count_vehicles_for_farm(string $savegameDir, string $farmId): int {')
if start < 0:
    raise RuntimeError('count_vehicles_for_farm not found')
end_marker = '// Maximale Wachstumsstufe je Fruchtart'
end = text.find(end_marker, start)
if end < 0:
    raise RuntimeError('end marker not found')
text = text[:start] + text[end:]

old = '''    // Fahrzeuge aus XML zählen (zuverlässig), Warnungen aus Live-Daten
    $xmlFolderOv = $_SESSION['savegame_folder'] ?? null;
    $xmlVehicleCount = 0;
    if ($xmlFolderOv) {
        $xmlDirOv = FS_BASE_DIR . DIRECTORY_SEPARATOR . $xmlFolderOv;
        $xmlFarmOv = get_farm_info($xmlDirOv);
        $xmlVehicleCount = $xmlFarmOv['farmId'] ? count_vehicles_for_farm($xmlDirOv, $xmlFarmOv['farmId']) : 0;
    }

    // Fahrzeuge mit Wartungsbedarf'''
new = '''    // Fuhrpark vollständig aus demselben Lua-Live-Export wie der Fuhrpark-Tab.
    $vehicleCount = count($vehicles);

    // Fahrzeuge mit Wartungsbedarf'''
if text.count(old) != 1:
    raise RuntimeError(f'overview XML count block matches: {text.count(old)}')
text = text.replace(old, new, 1)

old_count = "        'vehicleCount'           => $xmlVehicleCount,"
new_count = "        'vehicleCount'           => $vehicleCount,"
if text.count(old_count) != 1:
    raise RuntimeError('overview vehicleCount assignment not found exactly once')
text = text.replace(old_count, new_count, 1)

if 'count_vehicles_for_farm(' in text:
    raise RuntimeError('legacy XML vehicle counter still present')

path.write_text(text, encoding='utf-8')
