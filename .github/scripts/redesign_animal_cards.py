from pathlib import Path
import re

path = Path('index.html')
html = path.read_text(encoding='utf-8')

new_css = r'''    /* Tiere */
    #animalsContainer {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(360px, 520px));
        gap: 16px;
        margin-top: 18px;
        align-items: start;
    }
    .animal-card {
        min-width: 0;
        background: var(--panel);
        border: 1px solid var(--border);
        border-left: 3px solid var(--secondary);
        border-radius: 10px;
        padding: 16px;
        box-shadow: 0 8px 22px rgba(0,0,0,0.10);
    }
    .animal-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 14px;
        margin-bottom: 12px;
    }
    .animal-card-title { min-width: 0; }
    .animal-name {
        display: block;
        font-family: var(--font-display);
        font-weight: 600;
        font-size: 15px;
        line-height: 1.25;
    }
    .animal-kind {
        display: block;
        margin-top: 3px;
        font-family: var(--font-mono);
        font-size: 10px;
        color: var(--muted);
    }
    .animal-card-badges {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 6px;
        flex-wrap: wrap;
        flex-shrink: 0;
    }
    .animal-total-badge,
    .animal-state-badge {
        font-family: var(--font-mono);
        font-size: 10.5px;
        padding: 3px 8px;
        border-radius: 10px;
        white-space: nowrap;
        font-weight: 600;
    }
    .animal-total-badge {
        color: var(--ink-950);
        background: var(--accent);
    }
    .animal-state-badge {
        color: var(--muted);
        background: var(--ink-950);
        border: 1px solid var(--ink-700);
    }
    .animal-state-badge.full {
        color: var(--accent);
        border-color: rgba(201,162,39,0.45);
    }
    .animal-kpi-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 7px;
        margin-bottom: 4px;
    }
    .animal-kpi {
        min-width: 0;
        padding: 8px 9px;
        background: var(--ink-950);
        border: 1px solid var(--ink-800);
        border-radius: 6px;
    }
    .animal-kpi-label {
        display: block;
        font-family: var(--font-mono);
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: var(--muted);
        margin-bottom: 3px;
    }
    .animal-kpi-value {
        display: block;
        font-family: var(--font-mono);
        font-size: 11px;
        color: var(--text);
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .animal-section {
        margin-top: 14px;
        padding-top: 12px;
        border-top: 1px solid var(--ink-800);
    }
    .animal-section-title {
        display: flex;
        align-items: center;
        gap: 7px;
        font-family: var(--font-mono);
        font-size: 9.5px;
        text-transform: uppercase;
        letter-spacing: .09em;
        color: var(--muted);
        margin-bottom: 9px;
    }
    .animal-section-title::after {
        content: '';
        height: 1px;
        background: var(--ink-800);
        flex: 1;
    }
    .animal-breed-group {
        border: 1px solid var(--ink-800);
        background: rgba(20,22,14,0.32);
        border-radius: 6px;
        overflow: hidden;
    }
    .animal-breed-group + .animal-breed-group { margin-top: 8px; }
    .animal-breed-title {
        display: flex;
        gap: 10px;
        align-items: baseline;
        padding: 7px 9px;
        background: rgba(92,122,82,0.08);
        font-size: 12px;
        font-weight: 600;
    }
    .animal-breed-title span:last-child {
        margin-left: auto;
        font-family: var(--font-mono);
        color: var(--muted);
        font-size: 10px;
        font-weight: 400;
        white-space: nowrap;
    }
    .animal-cluster-row {
        display: grid;
        grid-template-columns: 58px minmax(70px, 1fr) auto;
        gap: 7px;
        align-items: center;
        padding: 6px 9px;
        font-family: var(--font-mono);
        font-size: 10px;
        color: var(--muted);
    }
    .animal-cluster-row + .animal-cluster-row { border-top: 1px solid var(--ink-800); }
    .animal-cluster-age { color: var(--text); }
    .animal-cluster-count { color: var(--text); }
    .animal-cluster-meta {
        text-align: right;
        white-space: nowrap;
        font-size: 9.5px;
    }
    .animal-cluster-flags {
        grid-column: 1 / -1;
        color: var(--moss-400);
        font-size: 9.5px;
    }
    .animal-resource {
        margin-top: 8px;
        padding: 9px 10px;
        border: 1px solid var(--ink-800);
        background: rgba(20,22,14,0.32);
        border-radius: 6px;
    }
    .animal-resource:first-of-type { margin-top: 0; }
    .animal-resource.is-empty { opacity: .78; }
    .animal-resource-head {
        display: grid;
        grid-template-columns: minmax(0,1fr) auto;
        gap: 10px;
        align-items: baseline;
        font-family: var(--font-mono);
        font-size: 10px;
        color: var(--muted);
        margin-bottom: 5px;
    }
    .animal-resource-head .animal-resource-name {
        color: var(--text);
        font-family: var(--font-body);
        font-size: 11.5px;
        font-weight: 600;
    }
    .animal-resource-head .animal-resource-value { white-space: nowrap; }
    .animal-resource .bar-track { margin: 1px 0 0; }
    .animal-resource-detail {
        font-family: var(--font-mono);
        font-size: 9.5px;
        line-height: 1.45;
        color: var(--muted);
        margin-top: 6px;
    }
    .animal-food-grid {
        display: grid;
        gap: 3px;
    }
    .animal-food-row {
        display: grid;
        grid-template-columns: minmax(0,1fr) auto auto;
        gap: 8px;
        align-items: baseline;
    }
    .animal-food-row span:nth-child(2),
    .animal-food-row span:nth-child(3) { white-space: nowrap; }
    .animal-food-weight { color: var(--moss-400); }
    .animal-output-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    .animal-output-card {
        min-width: 0;
        padding: 9px 10px;
        border: 1px solid var(--ink-800);
        border-radius: 6px;
        background: rgba(20,22,14,0.32);
    }
    .animal-output-card.is-empty { opacity: .78; }
    .animal-output-head {
        display: flex;
        align-items: baseline;
        gap: 8px;
        font-size: 11.5px;
        font-weight: 600;
        margin-bottom: 6px;
    }
    .animal-output-rate {
        margin-left: auto;
        font-family: var(--font-mono);
        color: var(--moss-400);
        font-size: 9px;
        font-weight: 400;
        white-space: nowrap;
    }
    .animal-output-stock {
        display: flex;
        justify-content: space-between;
        gap: 8px;
        font-family: var(--font-mono);
        font-size: 9.5px;
        color: var(--muted);
        margin-bottom: 4px;
    }
    .animal-output-note {
        margin-top: 5px;
        font-family: var(--font-mono);
        font-size: 9px;
        color: var(--muted);
    }
    .animal-live-note {
        font-family: var(--font-mono);
        color: var(--moss-400);
        font-size: 10.5px;
    }
    .animal-hive-list {
        border: 1px solid var(--ink-800);
        border-radius: 6px;
        overflow: hidden;
    }
    .animal-hive-row {
        display: grid;
        grid-template-columns: minmax(0,1fr) auto auto;
        gap: 8px;
        padding: 7px 9px;
        font-family: var(--font-mono);
        font-size: 9.5px;
        color: var(--muted);
    }
    .animal-hive-row + .animal-hive-row { border-top: 1px solid var(--ink-800); }
    .animal-hive-name { color: var(--text); }

    @media (max-width: 720px) {
        #animalsContainer { grid-template-columns: 1fr; }
        .animal-kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .animal-output-grid { grid-template-columns: 1fr; }
        .animal-cluster-row { grid-template-columns: 54px minmax(60px, 1fr) auto; }
    }
'''

css_pattern = re.compile(r'    /\* Tiere \*/\n.*?\n    /\* Produktion \*/', re.S)
if len(css_pattern.findall(html)) != 1:
    raise SystemExit('animal CSS block not found exactly once')
html = css_pattern.sub(new_css + '\n    /* Produktion */', html, count=1)

new_js = r'''function renderAnimalResourceBar(label, resource, detail) {
    if (!resource || !resource.enabled) return '';

    if (resource.automatic) {
        return `<div class="animal-resource">
            <div class="animal-resource-head">
                <span class="animal-resource-name">${escapeHtml(label)}</span>
                <span class="animal-resource-value">automatisch</span>
            </div>
            ${detail ? `<div class="animal-resource-detail">${detail}</div>` : ''}
        </div>`;
    }

    const capacity = Number(resource.capacity || 0);
    const level = Number(resource.level || 0);
    const pct = capacity > 0 ? Math.max(0, Math.min(100, Number(resource.percent || 0))) : 0;
    const emptyClass = capacity > 0 && pct <= 0 ? ' is-empty' : '';
    const value = capacity > 0
        ? `${formatAnimalLiters(level)} / ${formatAnimalLiters(capacity)} L · ${pct.toFixed(0)}%`
        : `${formatAnimalLiters(level)} L`;

    return `<div class="animal-resource${emptyClass}">
        <div class="animal-resource-head">
            <span class="animal-resource-name">${escapeHtml(label)}</span>
            <span class="animal-resource-value">${value}</span>
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
            const count = Number(c.numAnimals || 0);
            const health = animalPercentFactor(c.health);
            const reproduction = animalPercentFactor(c.reproduction);
            const flags = [c.isPregnant ? 'trächtig' : '', c.isParent ? 'Elterntier' : ''].filter(Boolean).join(' · ');
            const meta = [`Ges. ${health.toFixed(0)}%`];
            if (reproduction > 0) meta.push(`Repro ${reproduction.toFixed(0)}%`);

            return `<div class="animal-cluster-row">
                <span class="animal-cluster-age">${Number(c.ageMonths || 0).toLocaleString('de-DE', { maximumFractionDigits: 1 })} Mon.</span>
                <span class="animal-cluster-count">${count.toLocaleString('de-DE')} Tier${count === 1 ? '' : 'e'}</span>
                <span class="animal-cluster-meta">${meta.join(' · ')}</span>
                ${flags ? `<span class="animal-cluster-flags">${escapeHtml(flags)}</span>` : ''}
            </div>`;
        }).join('');

        return `<div class="animal-breed-group">
            <div class="animal-breed-title"><span>${escapeHtml(breed)}</span><span>${breedTotal.toLocaleString('de-DE')} Tiere</span></div>
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
        detail = `<div class="animal-food-grid">${groups.map(group => {
            const weight = Math.max(0, Math.min(100, Number(group.productionWeight || 0) * 100));
            return `<div class="animal-food-row">
                <span>${escapeHtml(group.title || 'Futtergruppe')}</span>
                <span>${formatAnimalLiters(group.level || 0)} L</span>
                <span class="animal-food-weight">${weight > 0 ? `${weight.toFixed(0)}% Leistung` : ''}</span>
            </div>`;
        }).join('')}</div>`;
    } else if (fillTypes.length > 0) {
        detail = `<div class="animal-food-grid">${fillTypes.map(ft => `<div class="animal-food-row">
            <span>${escapeHtml(ft.title || ft.fillType)}</span>
            <span>${formatAnimalLiters(ft.level || 0)} L</span>
            <span></span>
        </div>`).join('')}</div>`;
    }

    return renderAnimalResourceBar('🌾 Futter', food, detail);
}

function renderAnimalOutputs(barn) {
    const outputs = Array.isArray(barn.outputs) ? barn.outputs : [];
    if (outputs.length === 0) return '';

    return `<div class="animal-section">
        <div class="animal-section-title">Produktion / Lager</div>
        <div class="animal-output-grid">
            ${outputs.map(output => {
                const capacity = Number(output.capacity || 0);
                const level = Number(output.level || 0);
                const pct = capacity > 0 ? Math.max(0, Math.min(100, Number(output.percent || 0))) : 0;
                const rate = Number(output.litersPerHour || 0);
                const pending = Number(output.pendingLiters || 0);
                const emptyClass = capacity > 0 && pct <= 0 ? ' is-empty' : '';

                return `<div class="animal-output-card${emptyClass}">
                    <div class="animal-output-head">
                        <span>${animalOutputIcon(output)} ${escapeHtml(output.title || output.fillType || 'Produkt')}</span>
                        ${rate > 0 ? `<span class="animal-output-rate">${formatAnimalRate(rate)} L/h</span>` : ''}
                    </div>
                    ${capacity > 0 ? `
                        <div class="animal-output-stock"><span>Lager</span><span>${formatAnimalLiters(level)} / ${formatAnimalLiters(capacity)} L · ${pct.toFixed(0)}%</span></div>
                        <div class="bar-track"><div class="bar-fill-progress" style="width:${pct}%"></div></div>
                    ` : (level > 0 ? `<div class="animal-output-stock"><span>Lager</span><span>${formatAnimalLiters(level)} L</span></div>` : '')}
                    ${rate > 0 ? `<div class="animal-output-note">Basisrate aus aktuellem Tierbestand</div>` : ''}
                    ${pending > 0 ? `<div class="animal-output-note">Nächste Palette: ${formatAnimalLiters(pending)} L vorgemerkt</div>` : ''}
                    ${output.palletLimitReached ? '<div class="animal-output-note" style="color:var(--rust-500)">Palettenlimit erreicht / Ausgabe blockiert</div>' : ''}
                </div>`;
            }).join('')}
        </div>
    </div>`;
}

function renderAnimalKpi(label, value) {
    return `<div class="animal-kpi"><span class="animal-kpi-label">${escapeHtml(label)}</span><span class="animal-kpi-value">${escapeHtml(value)}</span></div>`;
}

function renderAnimals() {
    const container = document.getElementById('animalsContainer');

    const husbandryCards = animalsCache.map(barn => {
        const meta = animalTypeMeta(barn.animalType);
        const maxAnimals = Number(barn.maxAnimals || 0);
        const totalAnimals = Number(barn.totalAnimals || 0);
        const freeSlots = Number(barn.freeSlots || 0);
        const productivity = animalPercentFactor(barn.productivity);
        const health = animalPercentFactor(barn.health);
        const reproduction = animalPercentFactor(barn.reproduction);
        const occupancy = maxAnimals > 0 ? Math.round((totalAnimals / maxAnimals) * 100) : 0;
        const isFull = maxAnimals > 0 && freeSlots <= 0;

        const waterDetail = Number(barn.water?.litersPerHour || 0) > 0 ? `Bedarf: ${formatAnimalRate(barn.water.litersPerHour)} L/h` : '';
        const strawDetail = Number(barn.straw?.litersPerHour || 0) > 0 ? `Bedarf: ${formatAnimalRate(barn.straw.litersPerHour)} L/h` : '';
        const meadowDetail = (barn.meadow?.fillTypes || []).map(ft => `${escapeHtml(ft.title || ft.fillType)}: ${formatAnimalLiters(ft.level || 0)} L`).join('<br>');

        const kpis = [
            maxAnimals > 0 ? renderAnimalKpi('Belegung', `${occupancy}%`) : '',
            maxAnimals > 0 ? renderAnimalKpi('Freie Plätze', freeSlots.toLocaleString('de-DE')) : '',
            totalAnimals > 0 ? renderAnimalKpi('Gesundheit', `${health.toFixed(0)}%`) : '',
            productivity > 0 ? renderAnimalKpi('Produktivität', `${productivity.toFixed(0)}%`) : '',
            reproduction > 0 ? renderAnimalKpi('Reproduktion', `${reproduction.toFixed(0)}%`) : '',
        ].filter(Boolean).join('');

        return `<div class="animal-card">
            <div class="animal-card-header">
                <div class="animal-card-title">
                    <span class="animal-name">${meta.icon} ${escapeHtml(barn.name || meta.label)}</span>
                    <span class="animal-kind">${escapeHtml(meta.label)}</span>
                </div>
                <div class="animal-card-badges">
                    <span class="animal-total-badge">${totalAnimals.toLocaleString('de-DE')}${maxAnimals > 0 ? ` / ${maxAnimals.toLocaleString('de-DE')}` : ''}</span>
                    ${maxAnimals > 0 ? `<span class="animal-state-badge ${isFull ? 'full' : ''}">${isFull ? 'voll' : `${freeSlots.toLocaleString('de-DE')} frei`}</span>` : ''}
                </div>
            </div>

            ${kpis ? `<div class="animal-kpi-grid">${kpis}</div>` : ''}

            <div class="animal-section">
                <div class="animal-section-title">Bestand nach Rasse und Alter</div>
                ${renderAnimalClusters(barn)}
            </div>

            <div class="animal-section">
                <div class="animal-section-title">Versorgung</div>
                ${renderAnimalFood(barn)}
                ${renderAnimalResourceBar('💧 Wasser', barn.water, waterDetail)}
                ${renderAnimalResourceBar('🌾 Stroh', barn.straw, strawDetail)}
                ${renderAnimalResourceBar('🌱 Weide', barn.meadow, meadowDetail)}
            </div>

            ${renderAnimalOutputs(barn)}
        </div>`;
    }).join('');

    const beehiveCard = Number(beehivesCache.hiveCount || 0) > 0 ? `<div class="animal-card">
        <div class="animal-card-header">
            <div class="animal-card-title">
                <span class="animal-name">🐝 Bienen</span>
                <span class="animal-kind">Bienenhaltung</span>
            </div>
            <div class="animal-card-badges">
                <span class="animal-total-badge">${Number(beehivesCache.hiveCount || 0).toLocaleString('de-DE')} Stöcke</span>
            </div>
        </div>
        <div class="animal-kpi-grid">
            ${renderAnimalKpi('Aktiv', Number(beehivesCache.activeHiveCount || 0).toLocaleString('de-DE'))}
            ${renderAnimalKpi('Honigrate', `${formatAnimalRate(beehivesCache.honeyLitersPerHour || 0)} L/h`)}
            ${renderAnimalKpi('Paletten', Number(beehivesCache.finishedPallets || 0).toLocaleString('de-DE'))}
        </div>
        <div class="animal-section">
            <div class="animal-section-title">Honigproduktion</div>
            <div class="animal-output-grid">
                <div class="animal-output-card ${Number(beehivesCache.pendingHoneyLiters || 0) <= 0 ? 'is-empty' : ''}">
                    <div class="animal-output-head"><span>🍯 Wartender Honig</span></div>
                    <div class="animal-output-stock"><span>Sammelpunkt</span><span>${beehivesCache.hasSpawner ? 'vorhanden' : 'nicht vorhanden'}</span></div>
                    <div class="animal-output-note">${formatAnimalLiters(beehivesCache.pendingHoneyLiters || 0)} L vorgemerkt</div>
                </div>
                <div class="animal-output-card ${Number(beehivesCache.honeyOnPalletsLiters || 0) <= 0 ? 'is-empty' : ''}">
                    <div class="animal-output-head"><span>📦 Fertige Paletten</span></div>
                    <div class="animal-output-stock"><span>${Number(beehivesCache.finishedPallets || 0)} Palette(n)</span><span>${formatAnimalLiters(beehivesCache.honeyOnPalletsLiters || 0)} L</span></div>
                    ${beehivesCache.palletLimitReached ? '<div class="animal-output-note" style="color:var(--rust-500)">Palettenlimit erreicht / Ausgabe blockiert</div>' : ''}
                </div>
            </div>
        </div>
        ${(beehivesCache.hives || []).length > 0 ? `<div class="animal-section">
            <div class="animal-section-title">Bienenstöcke</div>
            <div class="animal-hive-list">
                ${(beehivesCache.hives || []).map(hive => `<div class="animal-hive-row">
                    <span class="animal-hive-name">${escapeHtml(hive.name || 'Bienenstock')}</span>
                    <span>${hive.active ? 'aktiv' : 'inaktiv'}</span>
                    <span>${formatAnimalRate(hive.honeyLitersPerHour || 0)} L/h${Number(hive.actionRadius || 0) > 0 ? ` · ${Number(hive.actionRadius).toLocaleString('de-DE', { maximumFractionDigits: 1 })} m` : ''}</span>
                </div>`).join('')}
            </div>
        </div>` : ''}
    </div>` : '';

    if (!husbandryCards && !beehiveCard) {
        container.innerHTML = '<div class="empty-note">Keine eigenen Tierhaltungen oder Bienenstöcke gefunden.</div>';
        return;
    }
    container.innerHTML = husbandryCards + beehiveCard;
}

'''

js_pattern = re.compile(r'function renderAnimalResourceBar\(label, resource, detail\) \{.*?(?=// =================================================================\n// Produktionsketten)', re.S)
if len(js_pattern.findall(html)) != 1:
    raise SystemExit('animal JS block not found exactly once')
html = js_pattern.sub(lambda m: new_js, html, count=1)

# Guards: no accidental data-contract changes and new structure present.
required = [
    'function renderAnimalKpi(label, value)',
    'class="animal-kpi-grid"',
    'class="animal-output-grid"',
    'class="animal-food-grid"',
    'fetch(\'api.php?action=animals_data\')',
    'animalsCache = Array.isArray(data.husbandries)',
]
for token in required:
    if token not in html:
        raise SystemExit(f'missing expected token: {token}')

path.write_text(html, encoding='utf-8')
