from pathlib import Path
import re

api_path = Path('api.php')
index_path = Path('index.html')
api = api_path.read_text(encoding='utf-8')
index = index_path.read_text(encoding='utf-8')

new_endpoint = r'''// ---------------------------------------------------------------
// Tierbestände – kanonische Live-Daten aus Lua
// ---------------------------------------------------------------
if ($action === 'animals_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $liveData = get_live_mod_data();

    if (($liveData['status'] ?? 'error') === 'no_mod') {
        echo json_encode(['error' => 'Mod nicht aktiv. FS25_AutoDriveFlurkarte aktivieren und Spiel starten.']);
        exit;
    }
    if (($liveData['status'] ?? 'error') === 'error') {
        echo json_encode(['error' => $liveData['message'] ?? 'Live-Tierdaten konnten nicht gelesen werden.']);
        exit;
    }

    $playerFarmId = (int)($liveData['farm']['farmId'] ?? 0);
    $husbandries = is_array($liveData['animals'] ?? null) ? array_values($liveData['animals']) : [];
    if ($playerFarmId > 0) {
        $husbandries = array_values(array_filter(
            $husbandries,
            fn($barn) => (int)($barn['farmId'] ?? 0) === $playerFarmId
        ));
    }

    $clampFactor = static fn($value): float => round(max(0.0, min(1.0, (float)$value)), 3);
    $clampPercent = static fn($value): int => (int)max(0, min(100, round((float)$value)));
    $normalized = [];

    foreach ($husbandries as $barn) {
        $clusters = [];
        foreach (($barn['clusters'] ?? []) as $cluster) {
            $clusters[] = [
                'subTypeIndex' => (int)($cluster['subTypeIndex'] ?? 0),
                'subType' => (string)($cluster['subType'] ?? ''),
                'breedTitle' => (string)($cluster['breedTitle'] ?? $cluster['subType'] ?? 'Unbekannt'),
                'ageMonths' => round(max(0.0, (float)($cluster['ageMonths'] ?? 0)), 1),
                'numAnimals' => max(0, (int)($cluster['numAnimals'] ?? 0)),
                'health' => $clampFactor($cluster['health'] ?? 0),
                'reproduction' => $clampFactor($cluster['reproduction'] ?? 0),
                'isPregnant' => (bool)($cluster['isPregnant'] ?? false),
                'isParent' => (bool)($cluster['isParent'] ?? false),
            ];
        }

        $normalizeResource = static function($resource) use ($clampPercent): array {
            $resource = is_array($resource) ? $resource : [];
            return array_merge($resource, [
                'enabled' => (bool)($resource['enabled'] ?? false),
                'level' => round(max(0.0, (float)($resource['level'] ?? 0)), 1),
                'capacity' => round(max(0.0, (float)($resource['capacity'] ?? 0)), 1),
                'percent' => $clampPercent($resource['percent'] ?? 0),
            ]);
        };

        $food = $normalizeResource($barn['food'] ?? []);
        $food['fillTypes'] = is_array($food['fillTypes'] ?? null) ? array_values($food['fillTypes']) : [];
        $food['groups'] = is_array($food['groups'] ?? null) ? array_values($food['groups']) : [];
        $water = $normalizeResource($barn['water'] ?? []);
        $water['automatic'] = (bool)($water['automatic'] ?? false);
        $water['litersPerHour'] = round(max(0.0, (float)($water['litersPerHour'] ?? 0)), 2);
        $straw = $normalizeResource($barn['straw'] ?? []);
        $straw['litersPerHour'] = round(max(0.0, (float)($straw['litersPerHour'] ?? 0)), 2);
        $meadow = $normalizeResource($barn['meadow'] ?? []);
        $meadow['fillTypes'] = is_array($meadow['fillTypes'] ?? null) ? array_values($meadow['fillTypes']) : [];

        $outputs = [];
        foreach (($barn['outputs'] ?? []) as $output) {
            $capacity = max(0.0, (float)($output['capacity'] ?? 0));
            $level = max(0.0, (float)($output['level'] ?? 0));
            $outputs[] = [
                'kind' => strtoupper((string)($output['kind'] ?? 'PRODUCT')),
                'fillType' => strtoupper((string)($output['fillType'] ?? 'UNKNOWN')),
                'title' => (string)($output['title'] ?? $output['fillType'] ?? 'Produkt'),
                'level' => round($level, 1),
                'capacity' => round($capacity, 1),
                'percent' => $capacity > 0 ? (int)min(100, max(0, round($level / $capacity * 100))) : 0,
                'pendingLiters' => round(max(0.0, (float)($output['pendingLiters'] ?? 0)), 1),
                'litersPerHour' => round(max(0.0, (float)($output['litersPerHour'] ?? 0)), 2),
                'palletLimitReached' => (bool)($output['palletLimitReached'] ?? false),
            ];
        }

        $normalized[] = [
            'uniqueId' => (string)($barn['uniqueId'] ?? ''),
            'name' => (string)($barn['name'] ?? 'Tierhaltung'),
            'farmId' => (int)($barn['farmId'] ?? 0),
            'animalType' => strtoupper((string)($barn['animalType'] ?? 'UNKNOWN')),
            'animalTypeIndex' => (int)($barn['animalTypeIndex'] ?? 0),
            'totalAnimals' => max(0, (int)($barn['totalAnimals'] ?? 0)),
            'maxAnimals' => max(0, (int)($barn['maxAnimals'] ?? 0)),
            'freeSlots' => max(0, (int)($barn['freeSlots'] ?? 0)),
            'occupancyPercent' => $clampPercent($barn['occupancyPercent'] ?? 0),
            'productivity' => $clampFactor($barn['productivity'] ?? 0),
            'health' => $clampFactor($barn['health'] ?? 0),
            'reproduction' => $clampFactor($barn['reproduction'] ?? 0),
            'clusters' => $clusters,
            'food' => $food,
            'water' => $water,
            'straw' => $straw,
            'meadow' => $meadow,
            'outputs' => $outputs,
            'liveSource' => true,
        ];
    }

    usort($normalized, static function(array $a, array $b): int {
        $countCmp = $b['totalAnimals'] <=> $a['totalAnimals'];
        return $countCmp !== 0 ? $countCmp : strnatcasecmp($a['name'], $b['name']);
    });

    $beehivesRaw = is_array($liveData['beehives'] ?? null) ? $liveData['beehives'] : [];
    $beehives = [
        'hiveCount' => max(0, (int)($beehivesRaw['hiveCount'] ?? 0)),
        'activeHiveCount' => max(0, (int)($beehivesRaw['activeHiveCount'] ?? 0)),
        'honeyLitersPerHour' => round(max(0.0, (float)($beehivesRaw['honeyLitersPerHour'] ?? 0)), 2),
        'pendingHoneyLiters' => round(max(0.0, (float)($beehivesRaw['pendingHoneyLiters'] ?? 0)), 1),
        'finishedPallets' => max(0, (int)($beehivesRaw['finishedPallets'] ?? 0)),
        'honeyOnPalletsLiters' => round(max(0.0, (float)($beehivesRaw['honeyOnPalletsLiters'] ?? 0)), 1),
        'hasSpawner' => (bool)($beehivesRaw['hasSpawner'] ?? false),
        'palletLimitReached' => (bool)($beehivesRaw['palletLimitReached'] ?? false),
        'hives' => is_array($beehivesRaw['hives'] ?? null) ? array_values($beehivesRaw['hives']) : [],
    ];

    echo json_encode([
        'husbandries' => $normalized,
        'barnCount' => count($normalized),
        'totalAnimals' => array_sum(array_column($normalized, 'totalAnimals')),
        'beehives' => $beehives,
        'diagnostics' => $liveData['animalDiagnostics'] ?? null,
        'source' => 'lua-live',
        'modVersion' => $liveData['version'] ?? '',
        'liveStatus' => $liveData['status'] ?? 'unknown',
        'liveAge' => $liveData['fileAgeSeconds'] ?? 0,
        'timestamp' => $liveData['timestamp'] ?? null,
    ]);
    exit;
}
'''

endpoint_pattern = re.compile(r'// ---------------------------------------------------------------\n// Tierbestände\n// ---------------------------------------------------------------\nif \(\$action === \'animals_data\'.*?\n}\n\n(?=// ---------------------------------------------------------------\n// Produktionsketten)', re.S)
match = endpoint_pattern.search(api)
if not match:
    raise SystemExit('animals_data endpoint not found')
api = api[:match.start()] + new_endpoint + '\n' + api[match.end():]

old_legend = '🐄 Kühe · 🐖 Schweine · 🐑 Schafe · 🐴 Pferde · 🐔 Hühner · Futter-/Wasserfüllstände werden von manchen Stall-Mods nicht im Spielstand gespeichert und können daher hier nicht angezeigt werden'
new_legend = 'Live aus FS25 · Tierbestand nach Rasse und Alter · Gesundheit/Reproduktion · Futter, Wasser, Stroh und Weide · Milch, Wolle, Eier, Mist/Gülle und weitere Tierprodukte · 🐝 Honig'
if old_legend not in index:
    raise SystemExit('animal legend not found')
index = index.replace(old_legend, new_legend, 1)

css_add = r'''    .animal-card { min-width: 0; }
    .animal-summary-line {
        font-family: var(--font-mono);
        font-size: 10.5px;
        color: var(--muted);
        margin: -2px 0 10px;
    }
    .animal-section {
        margin-top: 12px;
        padding-top: 10px;
        border-top: 1px solid var(--ink-800);
    }
    .animal-section-title {
        font-family: var(--font-mono);
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .07em;
        color: var(--muted);
        margin-bottom: 7px;
    }
    .animal-breed-group + .animal-breed-group { margin-top: 8px; }
    .animal-breed-title {
        display: flex;
        gap: 8px;
        align-items: baseline;
        font-size: 12px;
        font-weight: 600;
        margin-bottom: 3px;
    }
    .animal-breed-title span:last-child {
        margin-left: auto;
        font-family: var(--font-mono);
        color: var(--muted);
        font-size: 10.5px;
        font-weight: 400;
    }
    .animal-cluster-row {
        display: flex;
        flex-wrap: wrap;
        gap: 6px 10px;
        margin: 3px 0 3px 12px;
        font-family: var(--font-mono);
        font-size: 10.5px;
        color: var(--muted);
    }
    .animal-resource { margin-top: 8px; }
    .animal-resource-head {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        font-family: var(--font-mono);
        font-size: 10.5px;
        color: var(--muted);
        margin-bottom: 4px;
    }
    .animal-resource-detail {
        font-family: var(--font-mono);
        font-size: 10px;
        line-height: 1.45;
        color: var(--muted);
        margin-top: 3px;
    }
    .animal-output-row {
        padding: 7px 0;
        border-bottom: 1px solid var(--ink-800);
    }
    .animal-output-row:last-child { border-bottom: none; }
    .animal-output-head {
        display: flex;
        align-items: baseline;
        gap: 8px;
        font-size: 12px;
        font-weight: 600;
    }
    .animal-output-rate {
        margin-left: auto;
        font-family: var(--font-mono);
        color: var(--moss-400);
        font-size: 10px;
        font-weight: 400;
        white-space: nowrap;
    }
    .animal-live-note {
        font-family: var(--font-mono);
        color: var(--moss-400);
        font-size: 10.5px;
    }
'''
css_marker = '    /* Produktion */\n'
if css_marker not in index:
    raise SystemExit('production css marker not found')
index = index.replace(css_marker, css_add + '\n' + css_marker, 1)

new_js = r'''// =================================================================
// Tierbestände – Live aus Lua
// =================================================================
let animalsCache = [];
let beehivesCache = { hiveCount: 0, activeHiveCount: 0, honeyLitersPerHour: 0, pendingHoneyLiters: 0, finishedPallets: 0, honeyOnPalletsLiters: 0, hasSpawner: false, hives: [] };

const ANIMAL_TYPE_META = {
    COW:     { icon: '🐄', label: 'Kühe' },
    PIG:     { icon: '🐖', label: 'Schweine' },
    SHEEP:   { icon: '🐑', label: 'Schafe / Ziegen' },
    GOAT:    { icon: '🐐', label: 'Ziegen' },
    HORSE:   { icon: '🐴', label: 'Pferde' },
    CHICKEN: { icon: '🐔', label: 'Hühner' },
};

function animalTypeMeta(type) {
    return ANIMAL_TYPE_META[String(type || '').toUpperCase()] || { icon: '🐾', label: type || 'Tiere' };
}

function formatAnimalLiters(value) {
    return Number(value || 0).toLocaleString('de-DE', { maximumFractionDigits: 1 });
}

function formatAnimalRate(value) {
    return Number(value || 0).toLocaleString('de-DE', { maximumFractionDigits: 2 });
}

function animalPercentFactor(value) {
    return Math.max(0, Math.min(100, Number(value || 0) * 100));
}

function renderAnimalResourceBar(label, resource, detail) {
    if (!resource || !resource.enabled) return '';
    if (resource.automatic) {
        return `<div class="animal-resource">
            <div class="animal-resource-head"><span>${escapeHtml(label)}</span><span>automatisch</span></div>
            ${detail ? `<div class="animal-resource-detail">${detail}</div>` : ''}
        </div>`;
    }
    const capacity = Number(resource.capacity || 0);
    const level = Number(resource.level || 0);
    const pct = capacity > 0 ? Math.max(0, Math.min(100, Number(resource.percent || 0))) : 0;
    return `<div class="animal-resource">
        <div class="animal-resource-head">
            <span>${escapeHtml(label)}</span>
            <span>${capacity > 0 ? `${formatAnimalLiters(level)} / ${formatAnimalLiters(capacity)} L · ${pct.toFixed(0)}%` : `${formatAnimalLiters(level)} L`}</span>
        </div>
        ${capacity > 0 ? `<div class="bar-track"><div class="bar-fill ${barClass(1 - pct / 100)}" style="width:${pct}%"></div></div>` : ''}
        ${detail ? `<div class="animal-resource-detail">${detail}</div>` : ''}
    </div>`;
}

function animalOutputIcon(output) {
    const ft = String(output.fillType || '').toUpperCase();
    if (ft.includes('MILK')) return '🥛';
    if (ft === 'WOOL') return '🧶';
    if (ft === 'EGG') return '🥚';
    if (ft === 'MANURE') return '🟫';
    if (ft === 'LIQUIDMANURE') return '💧';
    return output.kind === 'PALLET' ? '📦' : '🪣';
}

async function loadAnimalsData() {
    const container = document.getElementById('animalsContainer');
    container.innerHTML = '<div class="empty-note">Lade Live-Tierdaten …</div>';
    const res = await fetch('api.php?action=animals_data');
    const data = await res.json();
    if (data.error) { container.innerHTML = `<div class="empty-note">${escapeHtml(data.error)}</div>`; return; }

    animalsCache = Array.isArray(data.husbandries) ? data.husbandries : [];
    beehivesCache = data.beehives || beehivesCache;

    document.getElementById('animalStatGrid').innerHTML = `
        <div class="stat-card">
            <div class="stat-label">Ställe/Gehege</div>
            <div class="stat-value">${Number(data.barnCount || 0)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Tiere gesamt</div>
            <div class="stat-value">${Number(data.totalAnimals || 0)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">🐝 Bienenstöcke</div>
            <div class="stat-value">${Number(beehivesCache.hiveCount || 0)}</div>
            <div class="stat-sub">${Number(beehivesCache.activeHiveCount || 0)} aktiv</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">🍯 Honigproduktion</div>
            <div class="stat-value">${formatAnimalRate(beehivesCache.honeyLitersPerHour || 0)} L/h</div>
        </div>
    `;
    renderAnimals();
}

function renderAnimalClusters(barn) {
    const clusters = Array.isArray(barn.clusters) ? barn.clusters : [];
    if (clusters.length === 0) return '<div class="animal-resource-detail">Noch keine Tiere im Stall.</div>';

    const breeds = new Map();
    clusters.forEach(cluster => {
        const breed = cluster.breedTitle || cluster.subType || 'Unbekannt';
        if (!breeds.has(breed)) breeds.set(breed, []);
        breeds.get(breed).push(cluster);
    });

    return [...breeds.entries()].map(([breed, rows]) => {
        rows.sort((a, b) => Number(b.ageMonths || 0) - Number(a.ageMonths || 0));
        const breedTotal = rows.reduce((sum, row) => sum + Number(row.numAnimals || 0), 0);
        const details = rows.map(c => {
            const health = animalPercentFactor(c.health);
            const reproduction = animalPercentFactor(c.reproduction);
            const flags = [c.isPregnant ? 'trächtig' : '', c.isParent ? 'Elterntier' : ''].filter(Boolean).join(' · ');
            return `<div class="animal-cluster-row">
                <span>${Number(c.ageMonths || 0).toLocaleString('de-DE', { maximumFractionDigits: 1 })} Mon.</span>
                <span>${Number(c.numAnimals || 0)} Tier${Number(c.numAnimals || 0) === 1 ? '' : 'e'}</span>
                <span>Gesundheit ${health.toFixed(0)}%</span>
                ${reproduction > 0 ? `<span>Reproduktion ${reproduction.toFixed(0)}%</span>` : ''}
                ${flags ? `<span>${escapeHtml(flags)}</span>` : ''}
            </div>`;
        }).join('');
        return `<div class="animal-breed-group">
            <div class="animal-breed-title"><span>${escapeHtml(breed)}</span><span>${breedTotal} Tiere</span></div>
            ${details}
        </div>`;
    }).join('');
}

function renderAnimalFood(barn) {
    const food = barn.food || {};
    if (!food.enabled) return '';
    const groups = Array.isArray(food.groups) ? food.groups : [];
    const fillTypes = Array.isArray(food.fillTypes) ? food.fillTypes : [];
    let detail = '';
    if (groups.length > 0) {
        detail = groups.map(group => {
            const weight = Math.max(0, Math.min(100, Number(group.productionWeight || 0) * 100));
            return `${escapeHtml(group.title || 'Futtergruppe')}: ${formatAnimalLiters(group.level || 0)} L${weight > 0 ? ` · ${weight.toFixed(0)}% Leistung` : ''}`;
        }).join('<br>');
    } else if (fillTypes.length > 0) {
        detail = fillTypes.map(ft => `${escapeHtml(ft.title || ft.fillType)}: ${formatAnimalLiters(ft.level || 0)} L`).join('<br>');
    }
    return renderAnimalResourceBar('🌾 Futter', food, detail);
}

function renderAnimalOutputs(barn) {
    const outputs = Array.isArray(barn.outputs) ? barn.outputs : [];
    if (outputs.length === 0) return '';
    return `<div class="animal-section">
        <div class="animal-section-title">Produktion / Lager</div>
        ${outputs.map(output => {
            const capacity = Number(output.capacity || 0);
            const level = Number(output.level || 0);
            const pct = capacity > 0 ? Math.max(0, Math.min(100, Number(output.percent || 0))) : 0;
            const rate = Number(output.litersPerHour || 0);
            const pending = Number(output.pendingLiters || 0);
            return `<div class="animal-output-row">
                <div class="animal-output-head">
                    <span>${animalOutputIcon(output)} ${escapeHtml(output.title || output.fillType || 'Produkt')}</span>
                    ${rate > 0 ? `<span class="animal-output-rate">Basisrate ${formatAnimalRate(rate)} L/h</span>` : ''}
                </div>
                ${capacity > 0 ? `
                    <div class="animal-resource-head"><span>Lager</span><span>${formatAnimalLiters(level)} / ${formatAnimalLiters(capacity)} L · ${pct.toFixed(0)}%</span></div>
                    <div class="bar-track"><div class="bar-fill-progress" style="width:${pct}%"></div></div>
                ` : (level > 0 ? `<div class="animal-resource-detail">Lager: ${formatAnimalLiters(level)} L</div>` : '')}
                ${pending > 0 ? `<div class="animal-resource-detail">Für nächste Palette vorgemerkt: ${formatAnimalLiters(pending)} L</div>` : ''}
                ${output.palletLimitReached ? '<div class="animal-resource-detail" style="color:var(--rust-500)">Palettenlimit erreicht / Ausgabe blockiert</div>' : ''}
            </div>`;
        }).join('')}
    </div>`;
}

function renderAnimals() {
    const container = document.getElementById('animalsContainer');

    const husbandryCards = animalsCache.map(barn => {
        const meta = animalTypeMeta(barn.animalType);
        const maxAnimals = Number(barn.maxAnimals || 0);
        const totalAnimals = Number(barn.totalAnimals || 0);
        const productivity = animalPercentFactor(barn.productivity);
        const health = animalPercentFactor(barn.health);
        const reproduction = animalPercentFactor(barn.reproduction);

        const waterDetail = Number(barn.water?.litersPerHour || 0) > 0 ? `Bedarf: ${formatAnimalRate(barn.water.litersPerHour)} L/h` : '';
        const strawDetail = Number(barn.straw?.litersPerHour || 0) > 0 ? `Bedarf: ${formatAnimalRate(barn.straw.litersPerHour)} L/h` : '';

        return `<div class="animal-card">
            <div class="animal-card-header">
                <span class="animal-name">${meta.icon} ${escapeHtml(barn.name || meta.label)}</span>
                <span class="animal-total-badge">${totalAnimals}${maxAnimals > 0 ? ` / ${maxAnimals}` : ''}</span>
            </div>
            <div class="animal-summary-line">
                ${escapeHtml(meta.label)}${maxAnimals > 0 ? ` · ${Number(barn.freeSlots || 0)} Plätze frei` : ''}
                ${totalAnimals > 0 ? ` · Gesundheit ${health.toFixed(0)}%` : ''}
                ${productivity > 0 ? ` · Produktivität ${productivity.toFixed(0)}%` : ''}
                ${reproduction > 0 ? ` · Reproduktion ${reproduction.toFixed(0)}%` : ''}
            </div>

            <div class="animal-section">
                <div class="animal-section-title">Bestand nach Rasse und Alter</div>
                ${renderAnimalClusters(barn)}
            </div>

            <div class="animal-section">
                <div class="animal-section-title">Versorgung</div>
                ${renderAnimalFood(barn)}
                ${renderAnimalResourceBar('💧 Wasser', barn.water, waterDetail)}
                ${renderAnimalResourceBar('🌾 Stroh', barn.straw, strawDetail)}
                ${renderAnimalResourceBar('🌱 Weide', barn.meadow, (barn.meadow?.fillTypes || []).map(ft => `${escapeHtml(ft.title || ft.fillType)}: ${formatAnimalLiters(ft.level || 0)} L`).join('<br>'))}
            </div>

            ${renderAnimalOutputs(barn)}
        </div>`;
    }).join('');

    const beehiveCard = Number(beehivesCache.hiveCount || 0) > 0 ? `<div class="animal-card">
        <div class="animal-card-header">
            <span class="animal-name">🐝 Bienen</span>
            <span class="animal-total-badge">${Number(beehivesCache.hiveCount || 0)} Stöcke</span>
        </div>
        <div class="animal-summary-line">${Number(beehivesCache.activeHiveCount || 0)} aktiv · Honig ${formatAnimalRate(beehivesCache.honeyLitersPerHour || 0)} L/h</div>
        <div class="animal-section">
            <div class="animal-section-title">Honigproduktion</div>
            <div class="animal-resource-detail">Sammelpunkt: ${beehivesCache.hasSpawner ? 'vorhanden' : 'nicht vorhanden'}</div>
            <div class="animal-resource-detail">Wartender Honig: ${formatAnimalLiters(beehivesCache.pendingHoneyLiters || 0)} L</div>
            <div class="animal-resource-detail">Honig auf fertigen Paletten: ${formatAnimalLiters(beehivesCache.honeyOnPalletsLiters || 0)} L · ${Number(beehivesCache.finishedPallets || 0)} Palette(n)</div>
            ${beehivesCache.palletLimitReached ? '<div class="animal-resource-detail" style="color:var(--rust-500)">Palettenlimit erreicht / Ausgabe blockiert</div>' : ''}
        </div>
        ${(beehivesCache.hives || []).length > 0 ? `<div class="animal-section">
            <div class="animal-section-title">Bienenstöcke</div>
            ${(beehivesCache.hives || []).map(hive => `<div class="animal-cluster-row" style="margin-left:0">
                <span>${escapeHtml(hive.name || 'Bienenstock')}</span>
                <span>${hive.active ? 'aktiv' : 'inaktiv'}</span>
                <span>${formatAnimalRate(hive.honeyLitersPerHour || 0)} L/h</span>
                ${Number(hive.actionRadius || 0) > 0 ? `<span>Radius ${Number(hive.actionRadius).toLocaleString('de-DE', { maximumFractionDigits: 1 })} m</span>` : ''}
            </div>`).join('')}
        </div>` : ''}
    </div>` : '';

    if (!husbandryCards && !beehiveCard) {
        container.innerHTML = '<div class="empty-note">Keine eigenen Tierhaltungen oder Bienenstöcke gefunden.</div>';
        return;
    }
    container.innerHTML = husbandryCards + beehiveCard;
}

'''

js_pattern = re.compile(r'// =================================================================\n// Tierbestände\n// =================================================================\n(?:// =================================================================\n// Tierbestände\n// =================================================================\n)?let animalsCache = \[\];.*?(?=// =================================================================\n// Produktionsketten)', re.S)
match = js_pattern.search(index)
if not match:
    raise SystemExit('animal JS block not found')
index = index[:match.start()] + new_js + index[match.end():]

api_path.write_text(api, encoding='utf-8')
index_path.write_text(index, encoding='utf-8')
