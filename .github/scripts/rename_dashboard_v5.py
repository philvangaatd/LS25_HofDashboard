from pathlib import Path
import re

ROOT = Path('.')
files = {
    'api.php': ROOT / 'api.php',
    'index.html': ROOT / 'index.html',
    'README.md': ROOT / 'README.md',
    'config.php': ROOT / 'config.php',
}
texts = {name: path.read_text(encoding='utf-8') for name, path in files.items()}

# ---------------------------------------------------------------------
# api.php: alter Live-Pfad bleibt nur als read-only Legacy-Fallback.
# ---------------------------------------------------------------------
api = texts['api.php']
needle = 'function get_live_data_file_path(): string'
start = api.find(needle)
if start < 0:
    raise SystemExit('get_live_data_file_path() not found')
brace = api.find('{', start)
if brace < 0:
    raise SystemExit('opening brace for get_live_data_file_path() not found')

depth = 0
end = None
for i in range(brace, len(api)):
    ch = api[i]
    if ch == '{':
        depth += 1
    elif ch == '}':
        depth -= 1
        if depth == 0:
            end = i + 1
            break
if end is None:
    raise SystemExit('closing brace for get_live_data_file_path() not found')

original_func = api[start:end]
if 'AutoDriveFlurkarte' not in original_func:
    raise SystemExit('legacy path token not found in get_live_data_file_path()')

legacy_func = original_func.replace(
    'function get_live_data_file_path(): string',
    'function get_legacy_live_data_file_path(): string',
    1,
)

new_func = r'''function get_live_data_file_path(): string
{
    $legacyPath = get_legacy_live_data_file_path();
    $needle = DIRECTORY_SEPARATOR . 'AutoDriveFlurkarte' . DIRECTORY_SEPARATOR;
    $replacement = DIRECTORY_SEPARATOR . 'LS25HofDashboard' . DIRECTORY_SEPARATOR;
    $primaryPath = str_replace($needle, $replacement, $legacyPath);

    if (is_file($primaryPath)) {
        return $primaryPath;
    }

    // Übergangs-Fallback für Installationen vor v5.0.0. Sobald die neue Mod
    // mindestens einmal exportiert hat, wird automatisch ausschließlich der
    // neue LS25HofDashboard-Pfad verwendet.
    if (is_file($legacyPath)) {
        return $legacyPath;
    }

    return $primaryPath;
}'''

api = api[:start] + legacy_func + '\n\n' + new_func + api[end:]

# Branding-/Repository-Rename. AutoDrive als fachlicher Funktionsname bleibt bestehen.
common_replacements = [
    ('FS25_AutoDriveFlurkarte', 'FS25_HofDashboard'),
    ('AutoDrive Flurkarte - Live Export', 'LS25 Hof-Dashboard - Live Connector'),
    ('AutoDrive Flurkarte – Live Export', 'LS25 Hof-Dashboard – Live Connector'),
    ('LS25_Dashboard_Mod', 'LS25_HofDashboardMod'),
    ('LS25_Dashboard', 'LS25_HofDashboard'),
    ('C:\\Projekte\\LS25\\AutoDriveMod', 'C:\\Projekte\\LS25\\HofDashboardMod'),
    ('C:\\Projekte\\LS25\\AutoDrive', 'C:\\Projekte\\LS25\\HofDashboard'),
]

for old, new in common_replacements:
    api = api.replace(old, new)
texts['api.php'] = api

for name in ('index.html', 'README.md', 'config.php'):
    text = texts[name]
    for old, new in common_replacements:
        text = text.replace(old, new)

    # Sichtbare/ dokumentierte Mod-Pfade auf v5 umstellen. Der alte Pfad bleibt
    # ausschließlich in api.php als Legacy-Fallback erhalten.
    text = text.replace('modSettings/AutoDriveFlurkarte/liveData.json', 'modSettings/LS25HofDashboard/liveData.json')
    text = text.replace('modSettings\\AutoDriveFlurkarte\\liveData.json', 'modSettings\\LS25HofDashboard\\liveData.json')
    text = text.replace('modSettings/AutoDriveFlurkarte', 'modSettings/LS25HofDashboard')
    text = text.replace('modSettings\\AutoDriveFlurkarte', 'modSettings\\LS25HofDashboard')
    texts[name] = text

# README: kurze v5-Migrationsnotiz ergänzen, falls noch nicht vorhanden.
readme = texts['README.md']
if '## Migration auf v5' not in readme:
    marker = '## Architektur'
    note = '''## Migration auf v5\n\nSeit v5 heißt der Live-Connector **FS25_HofDashboard** und schreibt nach:\n\n```text\nmodSettings/LS25HofDashboard/liveData.json\n```\n\nDas Dashboard bevorzugt diesen neuen Pfad. Für die Übergangsphase kann es eine bereits vorhandene v4-Datei aus dem alten Settings-Verzeichnis weiterhin lesen. Sobald v5 einmal erfolgreich exportiert hat, wird automatisch der neue Pfad verwendet.\n\n'''
    if marker in readme:
        readme = readme.replace(marker, note + marker, 1)
    else:
        readme = note + readme
texts['README.md'] = readme

# UI-Hinweise mit dem neuen Connector-Namen vereinheitlichen.
index = texts['index.html']
index = index.replace('über FS25_HofDashboard aktualisiert', 'über FS25_HofDashboard aktualisiert')
index = index.replace('FS25 AutoDrive Flurkarte', 'LS25 Hof-Dashboard Live Connector')
texts['index.html'] = index

# ---------------------------------------------------------------------
# Vertragschecks
# ---------------------------------------------------------------------
if "'LS25HofDashboard'" not in texts['api.php']:
    raise SystemExit('new settings path missing in api.php')
if 'get_legacy_live_data_file_path' not in texts['api.php']:
    raise SystemExit('legacy fallback helper missing')
if texts['api.php'].count('AutoDriveFlurkarte') < 1:
    raise SystemExit('legacy AutoDriveFlurkarte fallback disappeared unexpectedly')

for name in ('index.html', 'README.md', 'config.php'):
    if 'FS25_AutoDriveFlurkarte' in texts[name]:
        raise SystemExit(f'old mod id still present in {name}')
    if 'LS25_Dashboard_Mod' in texts[name] or 'LS25_Dashboard' in texts[name]:
        raise SystemExit(f'old repository name still present in {name}')

# AutoDrive feature must still exist; this guards against an accidental blanket rename.
if 'AutoDrive' not in texts['index.html'] or 'AutoDrive' not in texts['api.php']:
    raise SystemExit('AutoDrive feature references disappeared unexpectedly')

for name, path in files.items():
    path.write_text(texts[name], encoding='utf-8')
