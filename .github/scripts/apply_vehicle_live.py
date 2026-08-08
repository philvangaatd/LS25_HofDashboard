from pathlib import Path

api_path = Path('api.php')
index_path = Path('index.html')
api = api_path.read_text(encoding='utf-8')
index = index_path.read_text(encoding='utf-8')


def replace_between(text: str, start: str, end: str, replacement: str, label: str) -> str:
    a = text.find(start)
    if a < 0:
        raise RuntimeError(f'{label}: start marker missing')
    b = text.find(end, a)
    if b < 0:
        raise RuntimeError(f'{label}: end marker missing')
    return text[:a] + replacement + text[b:]


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f'{label}: expected one match, got {count}')
    return text.replace(old, new, 1)


# ---------------------------------------------------------------------------
# PHP: Der Fuhrpark kommt vollständig aus liveData.json. Keine vehicles.xml-Basis,
# kein UniqueId-/Namens-Merge und keine Kapazitätssuche in Fahrzeug-/Mod-XMLs mehr.
# ---------------------------------------------------------------------------
new_vehicle_api = r'''// ---------------------------------------------------------------
// Fuhrpark-Dashboard – kanonische Live-Daten aus Lua
// ---------------------------------------------------------------
if ($action === 'vehicles_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $liveData = get_live_mod_data();
    if (($liveData['status'] ?? 'error') === 'no_mod') {
        echo json_encode(['error' => 'Mod nicht aktiv. FS25_AutoDriveFlurkarte aktivieren und Spiel starten.']);
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
    exit;
}

'''
api = replace_between(
    api,
    '// ---------------------------------------------------------------\n// Fuhrpark-Dashboard',
    '// ---------------------------------------------------------------\n// Tierbestände',
    new_vehicle_api,
    'vehicles_data endpoint',
)

# Tote XML-Fuhrpark-Helfer entfernen. Übersetzungen für FillTypes bleiben bestehen,
# weil sie auch von Tierhaltung und anderen Bereichen genutzt werden.
api = replace_between(
    api,
    'function readable_vehicle_name(string $filename): string {',
    '// Echte Kraftstoffarten in <fillUnit>-Tanks',
    '// Echte Kraftstoffarten in <fillUnit>-Tanks',
    'legacy vehicle name/classification helpers',
)
api = replace_between(
    api,
    '// Tankkapazitäten stehen NICHT im Spielstand',
    '// -----------------------------------------------------------------\n// Tierbestände (Herden/Ställe aus placeables.xml)',
    '// -----------------------------------------------------------------\n// Tierbestände (Herden/Ställe aus placeables.xml)',
    'legacy vehicle XML parser',
)

for obsolete in ['parse_vehicles(', 'find_vehicle_fill_capacities(', 'readable_vehicle_name(', 'classify_vehicle_type(']:
    if obsolete in api:
        raise RuntimeError(f'obsolete vehicle helper still present: {obsolete}')

# ---------------------------------------------------------------------------
# Frontend: ausschließlich normalisierte Live-Fahrzeuge und Live-FillUnits rendern.
# ---------------------------------------------------------------------------
new_vehicle_frontend = r'''let vehiclesCache = [];

function barClass(value) {
    if (value > 0.7) return 'bad';
    if (value > 0.4) return 'warn';
    return 'ok';
}

async function loadVehiclesData() {
    const container = document.getElementById('vehiclesContainer');
    container.innerHTML = '<div class="empty-note">Lade Live-Fuhrpark …</div>';
    const res = await fetch('api.php?action=vehicles_data');
    const data = await res.json();
    if (data.error) {
        container.innerHTML = `<div class="empty-note">${escapeHtml(data.error)}</div>`;
        return;
    }
    vehiclesCache = Array.isArray(data.vehicles) ? data.vehicles : [];

    const counts = data.categoryCounts || {};
    document.getElementById('vehicleStatGrid').innerHTML = `
        <div class="stat-card">
            <div class="stat-label">Fuhrpark</div>
            <div class="stat-value">${data.totalCount}</div>
            <div class="stat-sub">${counts.VEHICLE || 0} Fahrzeuge · ${counts.TRAILER || 0} Anhänger · ${counts.IMPLEMENT || 0} Anbauteile</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Shopwert</div>
            <div class="stat-value">${Math.round(data.totalShopValue || 0).toLocaleString('de-DE')} €</div>
        </div>
        <div class="stat-card ${data.needsRepairCount > 0 ? 'stat-highlight' : ''}">
            <div class="stat-label">Wartung empfohlen</div>
            <div class="stat-value ${data.needsRepairCount > 0 ? 'stat-warn' : ''}">${data.needsRepairCount}</div>
        </div>
        <div class="stat-card ${data.needsWashCount > 0 ? 'stat-highlight' : ''}">
            <div class="stat-label">Waschen empfohlen</div>
            <div class="stat-value">${data.needsWashCount}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">⛽ Diesel gesamt</div>
            <div class="stat-value">${Math.round(data.totalDieselLiters || 0).toLocaleString('de-DE')} L</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">AdBlue gesamt</div>
            <div class="stat-value">${Math.round(data.totalAdBlueLiters || 0).toLocaleString('de-DE')} L</div>
        </div>
    `;
    renderVehicles();
}

const VEHICLE_TYPE_ICON = { VEHICLE: '🚜 Fahrzeug', TRAILER: '🚛 Anhänger', IMPLEMENT: '🔧 Anbauteil' };
let vehicleTypeFilter = 'ALL';

function setVehicleTypeFilter(type) {
    vehicleTypeFilter = type;
    document.querySelectorAll('#vehicleTypeSwitch .mode-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.type === type);
    });
    renderVehicles();
}

function vehicleFillLabel(fillUnit) {
    if (fillUnit.fillType && fillUnit.fillType !== 'UNKNOWN' && fillUnit.title && fillUnit.title !== 'Leer') {
        return fillUnit.title;
    }
    const supported = (fillUnit.supportedFillTypes || []).filter(ft => ft && ft.name !== 'UNKNOWN');
    if (supported.length === 1) return supported[0].title || supported[0].name;
    return fillUnit.kind === 'FUEL' ? 'Kraftstoff' : 'Laderaum';
}

function formatVehicleLiters(value) {
    return Number(value || 0).toLocaleString('de-DE', { maximumFractionDigits: 1 });
}

function renderVehicleFillUnits(vehicle) {
    const fillUnits = Array.isArray(vehicle.fillUnits) ? vehicle.fillUnits : [];
    if (fillUnits.length === 0) return '';

    return `<div class="vehicle-fill-section">
        ${fillUnits.map(fillUnit => {
            const percent = Math.max(0, Math.min(100, Number(fillUnit.percent || 0)));
            const label = vehicleFillLabel(fillUnit);
            const fillClass = fillUnit.kind === 'FUEL' ? `bar-fill ${barClass(1 - percent / 100)}` : 'bar-fill-progress';
            return `
                <div class="vehicle-fill-unit">
                    <div class="bar-row">
                        <span class="bar-label">${escapeHtml(label)}</span>
                        <div class="bar-track"><div class="${fillClass}" style="width:${percent}%"></div></div>
                        <span class="bar-value">${percent.toFixed(0)}%</span>
                    </div>
                    <div class="vehicle-fill-meta">${formatVehicleLiters(fillUnit.liters)} / ${formatVehicleLiters(fillUnit.capacity)} L${fillUnit.fillType === 'UNKNOWN' ? ' · leer' : ''}</div>
                </div>
            `;
        }).join('')}
    </div>`;
}

function renderVehicles() {
    const filter = document.getElementById('vehicleFilterInput').value.toLowerCase();
    const sortBy = document.getElementById('vehicleSortSelect').value;
    const container = document.getElementById('vehiclesContainer');

    const typeFiltered = vehicleTypeFilter === 'ALL'
        ? vehiclesCache
        : vehiclesCache.filter(v => v.vehicleType === vehicleTypeFilter);

    const sorted = typeFiltered.slice().sort((a, b) => {
        if (sortBy === 'name') return String(a.name || '').localeCompare(String(b.name || ''), 'de');
        if (sortBy === 'price') return Number(b.shopPrice || 0) - Number(a.shopPrice || 0);
        if (sortBy === 'hours') return Number(b.operatingHours || 0) - Number(a.operatingHours || 0);
        if (sortBy === 'dirt') return Number(b.dirt || 0) - Number(a.dirt || 0);
        return Number(b.wear || 0) - Number(a.wear || 0);
    });

    const visible = sorted.filter(v => {
        if (!filter) return true;
        const fillText = (v.fillUnits || []).map(f => `${f.title || ''} ${f.fillType || ''}`).join(' ');
        return `${v.name || ''} ${v.brand || ''} ${v.model || ''} ${fillText}`.toLowerCase().includes(filter);
    });

    if (visible.length === 0) {
        container.innerHTML = '<div class="empty-note">Keine Fahrzeuge gefunden.</div>';
        return;
    }

    container.innerHTML = visible.map(v => {
        const wear = Number(v.wear || 0);
        const dirt = Number(v.dirt || 0);
        const needsAttention = wear > 0.5 || dirt > 0.5;
        const shopPrice = Math.round(Number(v.shopPrice || 0)).toLocaleString('de-DE');
        const hours = Number(v.operatingHours || 0).toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

        return `
            <div class="vehicle-card ${needsAttention ? 'needs-attention' : ''}">
                <div class="vehicle-card-header">
                    <span class="vehicle-name">${escapeHtml(v.name || v.model || 'Unbekannt')}</span>
                    <span class="vehicle-type-badge">${VEHICLE_TYPE_ICON[v.vehicleType] || escapeHtml(v.vehicleType)}</span>
                </div>
                <div class="vehicle-meta">Shoppreis ${shopPrice} € · ${hours} Bh</div>
                <div class="bar-row">
                    <span class="bar-label">Verschleiß</span>
                    <div class="bar-track"><div class="bar-fill ${barClass(wear)}" style="width:${(wear * 100).toFixed(0)}%"></div></div>
                    <span class="bar-value">${(wear * 100).toFixed(0)}%</span>
                </div>
                <div class="bar-row">
                    <span class="bar-label">Dreck</span>
                    <div class="bar-track"><div class="bar-fill ${barClass(dirt)}" style="width:${(dirt * 100).toFixed(0)}%"></div></div>
                    <span class="bar-value">${(dirt * 100).toFixed(0)}%</span>
                </div>
                ${renderVehicleFillUnits(v)}
            </div>
        `;
    }).join('');
}

// =================================================================
// Tierbestände
// =================================================================
'''
index = replace_between(
    index,
    'let vehiclesCache = [];',
    '// =================================================================\n// Tierbestände\n// =================================================================',
    new_vehicle_frontend,
    'vehicle frontend block',
)

old_vehicle_css = '''    .vehicle-meta { font-family: var(--font-mono); font-size: 11px; color: var(--muted); margin-bottom: 10px; }
    .bar-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; font-size: 12px; }'''
new_vehicle_css = '''    .vehicle-meta { font-family: var(--font-mono); font-size: 11px; color: var(--muted); margin-bottom: 10px; }
    .vehicle-card .bar-label { width: 92px; }
    .vehicle-fill-section { margin-top: 10px; padding-top: 9px; border-top: 1px solid var(--ink-800); }
    .vehicle-fill-unit + .vehicle-fill-unit { margin-top: 8px; }
    .vehicle-fill-meta {
        margin: -3px 46px 6px 100px;
        text-align: right;
        color: var(--muted);
        font-family: var(--font-mono);
        font-size: 10.5px;
    }
    .bar-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; font-size: 12px; }'''
index = replace_once(index, old_vehicle_css, new_vehicle_css, 'vehicle fill CSS')

api_path.write_text(api, encoding='utf-8')
index_path.write_text(index, encoding='utf-8')
