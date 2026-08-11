let vehiclesCache = [];

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

function vehicleMaintenanceItems(source = vehiclesCache) {
    return (Array.isArray(source) ? source : [])
        .map(vehicle => {
            const wear = Math.max(0, Math.min(1, Number(vehicle.wear || 0)));
            const dirt = Math.max(0, Math.min(1, Number(vehicle.dirt || 0)));
            const hours = Number(vehicle.operatingHours || 0);
            const reasons = [];

            if (wear > 0.5) reasons.push(`Wartung ${(wear * 100).toFixed(0)}%`);
            if (dirt > 0.5) reasons.push(`Waschen ${(dirt * 100).toFixed(0)}%`);
            if (hours >= 100) reasons.push(`${hours.toLocaleString('de-DE', { maximumFractionDigits: 1 })} Bh`);

            return {
                vehicle,
                reasons,
                score: Math.max(wear, dirt) * 100 + Math.min(hours, 250) / 10,
            };
        })
        .filter(item => item.reasons.length > 0)
        .sort((a, b) => b.score - a.score)
        .slice(0, 6);
}

function renderVehicleMaintenancePlan(source = vehiclesCache) {
    const items = vehicleMaintenanceItems(source);
    if (items.length === 0) return '';

    return `<div class="vehicle-maintenance-plan">
        <div class="plan-header">
            <div>
                <div class="plan-kicker">Wartungsplan</div>
                <h3>Fuhrpark-Prioritäten</h3>
            </div>
            <span>${items.length} Aufgabe${items.length === 1 ? '' : 'n'}</span>
        </div>
        <div class="plan-list">
            ${items.map(item => {
                const vehicle = item.vehicle;
                const shopPrice = Math.round(Number(vehicle.shopPrice || 0));
                return `<div class="plan-row">
                    <span class="plan-title">${escapeHtml(vehicle.name || vehicle.model || 'Unbekannt')}</span>
                    <span class="plan-meta">${escapeHtml(item.reasons.join(' · '))}${shopPrice > 0 ? ` · ${shopPrice.toLocaleString('de-DE')} €` : ''}</span>
                </div>`;
            }).join('')}
        </div>
    </div>`;
}

function renderVehicleFillUnits(vehicle) {
    const fillUnits = Array.isArray(vehicle.fillUnits) ? vehicle.fillUnits : [];
    if (fillUnits.length === 0) return '';

    const renderGroup = (title, units, kindLabel) => {
        if (units.length === 0) return '';
        return `<div class="vehicle-fill-section">
            <div class="vehicle-section-title">${escapeHtml(title)}</div>
            <div class="vehicle-fill-grid">
                ${units.map(fillUnit => {
                    const percent = Math.max(0, Math.min(100, Number(fillUnit.percent || 0)));
                    const label = vehicleFillLabel(fillUnit);
                    const fillClass = fillUnit.kind === 'FUEL'
                        ? `bar-fill ${barClass(1 - percent / 100)}`
                        : 'bar-fill-progress';
                    const emptyClass = percent <= 0 ? ' is-empty' : '';
                    const kind = fillUnit.kind === 'FUEL' ? kindLabel : (fillUnit.fillType === 'UNKNOWN' ? 'leer' : 'Ladung');

                    return `<div class="vehicle-fill-unit${emptyClass}">
                        <div class="vehicle-fill-head">
                            <span class="vehicle-fill-name">${escapeHtml(label)}</span>
                            <span class="vehicle-fill-percent">${percent.toFixed(0)}%</span>
                        </div>
                        <div class="bar-track"><div class="${fillClass}" style="width:${percent}%"></div></div>
                        <div class="vehicle-fill-meta">
                            <span class="vehicle-fill-kind">${escapeHtml(kind)}</span>
                            <span class="vehicle-fill-volume">${formatVehicleLiters(fillUnit.liters)} / ${formatVehicleLiters(fillUnit.capacity)} L</span>
                        </div>
                    </div>`;
                }).join('')}
            </div>
        </div>`;
    };

    const fuelUnits = fillUnits.filter(fillUnit => fillUnit.kind === 'FUEL');
    const cargoUnits = fillUnits.filter(fillUnit => fillUnit.kind !== 'FUEL');

    return renderGroup('Kraftstoff & Betriebsstoffe', fuelUnits, 'Betriebsstoff')
        + renderGroup('Füllstände', cargoUnits, 'Ladung');
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

    const renderCondition = (label, value) => {
        const normalized = Math.max(0, Math.min(1, Number(value || 0)));
        const percent = normalized * 100;
        return `<div class="vehicle-condition ${percent <= 0 ? 'is-zero' : ''}">
            <div class="vehicle-condition-head">
                <span>${escapeHtml(label)}</span>
                <strong>${percent.toFixed(0)}%</strong>
            </div>
            <div class="bar-track"><div class="bar-fill ${barClass(normalized)}" style="width:${percent}%"></div></div>
        </div>`;
    };

    container.innerHTML = renderVehicleMaintenancePlan(typeFiltered) + visible.map(v => {
        const wear = Number(v.wear || 0);
        const dirt = Number(v.dirt || 0);
        const needsMaintenance = wear > 0.5;
        const needsWash = dirt > 0.5;
        const needsAttention = needsMaintenance || needsWash;
        const shopPrice = Math.round(Number(v.shopPrice || 0)).toLocaleString('de-DE');
        const hours = Number(v.operatingHours || 0).toLocaleString('de-DE', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
        const brand = String(v.brand || '').trim();
        const model = String(v.model || '').trim();
        const subline = [brand, model].filter(Boolean).filter((value, index, values) => values.indexOf(value) === index).join(' · ');
        const alerts = [
            needsMaintenance ? '<span class="vehicle-alert-chip">🔧 Wartung empfohlen</span>' : '',
            needsWash ? '<span class="vehicle-alert-chip">🧽 Waschen empfohlen</span>' : '',
        ].filter(Boolean).join('');

        return `<div class="vehicle-card ${needsAttention ? 'needs-attention' : ''}">
            <div class="vehicle-card-header">
                <div class="vehicle-card-title">
                    <span class="vehicle-name">${escapeHtml(v.name || v.model || 'Unbekannt')}</span>
                    ${subline ? `<span class="vehicle-subline">${escapeHtml(subline)}</span>` : ''}
                </div>
                <span class="vehicle-type-badge">${VEHICLE_TYPE_ICON[v.vehicleType] || escapeHtml(v.vehicleType)}</span>
            </div>

            ${alerts ? `<div class="vehicle-alerts">${alerts}</div>` : ''}

            <div class="vehicle-meta-grid">
                <div class="vehicle-meta-item">
                    <span class="vehicle-meta-label">Shoppreis</span>
                    <span class="vehicle-meta-value">${shopPrice} €</span>
                </div>
                <div class="vehicle-meta-item">
                    <span class="vehicle-meta-label">Betriebsstunden</span>
                    <span class="vehicle-meta-value">${hours} Bh</span>
                </div>
            </div>

            <div class="vehicle-section">
                <div class="vehicle-section-title">Zustand</div>
                <div class="vehicle-condition-grid">
                    ${renderCondition('Verschleiß', wear)}
                    ${renderCondition('Dreck', dirt)}
                </div>
            </div>

            ${renderVehicleFillUnits(v)}
        </div>`;
    }).join('');
}

// =================================================================
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

// =================================================================
// Produktionsketten
// =================================================================
let productionCache = [];

function formatProductionLiters(value) {
    return Math.max(0, Math.round(Number(value) || 0)).toLocaleString('de-DE');
}

function renderProductionStorage(storage, icon) {
    const level = Math.max(0, Number(storage?.level) || 0);
    const capacity = Math.max(0, Number(storage?.capacity) || 0);
    const calculatedPercent = capacity > 0 ? level / capacity * 100 : 0;
    const percent = Math.max(0, Math.min(100, Number.isFinite(calculatedPercent) ? calculatedPercent : 0));
    const label = storage?.title || storage?.fillType || 'Lager';
    const amount = capacity > 0
        ? `${formatProductionLiters(level)} / ${formatProductionLiters(capacity)} L`
        : `${formatProductionLiters(level)} L`;

    return `<div class="production-storage-card">
        <div class="production-storage-head">
            <span class="production-storage-name">${icon} ${escapeHtml(label)}</span>
            ${capacity > 0 ? `<span class="production-storage-percent">${percent.toFixed(0)}%</span>` : ''}
        </div>
        ${capacity > 0 ? `<div class="bar-track"><div class="bar-fill-progress" style="width:${percent}%"></div></div>` : ''}
        <div class="production-storage-amount">${amount}</div>
    </div>`;
}

function productionStoragePercent(storage) {
    const capacity = Number(storage?.capacity || 0);
    const explicit = Number(storage?.percent);
    if (Number.isFinite(explicit)) return Math.max(0, Math.min(100, explicit));
    if (capacity <= 0) return 0;
    return Math.max(0, Math.min(100, Number(storage?.level || 0) / capacity * 100));
}

function productionPlannerItems(points = productionCache) {
    const bottlenecks = [];
    const fullOutputs = [];
    let activeChains = 0;

    (Array.isArray(points) ? points : []).forEach(point => {
        const pointName = point.name || 'Produktionsanlage';
        const productions = Array.isArray(point.productions) ? point.productions : [];
        const activeCountValue = Number(point.activeCount);
        activeChains += Number.isFinite(activeCountValue)
            ? activeCountValue
            : productions.filter(prod => prod?.enabled === true).length;

        (Array.isArray(point.inputStorages) ? point.inputStorages : []).forEach(storage => {
            const percent = productionStoragePercent(storage);
            const isWater = String(storage.fillType || '').toUpperCase() === 'WATER';
            const threshold = isWater ? 25 : 15;
            if (Number(storage.capacity || 0) > 0 && percent <= threshold) {
                bottlenecks.push({
                    title: storage.title || storage.fillType || 'Betriebsstoff',
                    place: pointName,
                    percent,
                    priority: percent <= 5 ? 0 : 1,
                });
            }
        });

        (Array.isArray(point.outputStorages) ? point.outputStorages : []).forEach(storage => {
            const percent = productionStoragePercent(storage);
            if (Number(storage.capacity || 0) > 0 && percent >= 90) {
                fullOutputs.push({
                    title: storage.title || storage.fillType || 'Ausstoß',
                    place: pointName,
                    percent,
                    priority: percent >= 98 ? 0 : 1,
                });
            }
        });
    });

    return {
        activeChains,
        bottlenecks: bottlenecks.sort((a, b) => a.priority - b.priority || a.percent - b.percent),
        fullOutputs: fullOutputs.sort((a, b) => a.priority - b.priority || b.percent - a.percent),
    };
}

function renderProductionPlanner(plan) {
    const rows = [
        ...plan.bottlenecks.slice(0, 5).map(item => ({
            title: `${item.title} nachfüllen`,
            meta: `${item.place} · ${item.percent.toFixed(0)}%`,
        })),
        ...plan.fullOutputs.slice(0, 5).map(item => ({
            title: `${item.title} abholen`,
            meta: `${item.place} · ${item.percent.toFixed(0)}% voll`,
        })),
    ].slice(0, 8);

    if (rows.length === 0) {
        return `<div class="production-planner is-calm">
            <div class="plan-header">
                <div>
                    <div class="plan-kicker">Produktionsplaner</div>
                    <h3>Keine Engpässe oder vollen Outputs</h3>
                </div>
                <span>${plan.activeChains} aktiv</span>
            </div>
        </div>`;
    }

    return `<div class="production-planner">
        <div class="plan-header">
            <div>
                <div class="plan-kicker">Produktionsplaner</div>
                <h3>Nächste Produktionsaufgaben</h3>
            </div>
            <span>${plan.activeChains} aktiv</span>
        </div>
        <div class="plan-list">
            ${rows.map(row => `<div class="plan-row">
                <span class="plan-title">${escapeHtml(row.title)}</span>
                <span class="plan-meta">${escapeHtml(row.meta)}</span>
            </div>`).join('')}
        </div>
    </div>`;
}

async function loadProductionData() {
    const container = document.getElementById('productionContainer');
    container.innerHTML = '<div class="empty-note">Lade Produktionsanlagen …</div>';
    const res = await fetch('api.php?action=production_data');
    const data = await res.json();
    if (data.error) { container.innerHTML = `<div class="empty-note">${escapeHtml(data.error)}</div>`; return; }
    productionCache = data.productionPoints;
    const plan = productionPlannerItems(productionCache);

    document.getElementById('productionStatGrid').innerHTML = `
        <div class="stat-card">
            <div class="stat-label">Produktionsanlagen</div>
            <div class="stat-value">${data.pointCount}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Aktive Ketten</div>
            <div class="stat-value">${plan.activeChains}</div>
        </div>
        <div class="stat-card ${plan.bottlenecks.length > 0 ? 'stat-highlight' : ''}">
            <div class="stat-label">Engpässe</div>
            <div class="stat-value ${plan.bottlenecks.length > 0 ? 'stat-warn' : ''}">${plan.bottlenecks.length}</div>
        </div>
        <div class="stat-card ${plan.fullOutputs.length > 0 ? 'stat-highlight' : ''}">
            <div class="stat-label">Volle Outputs</div>
            <div class="stat-value">${plan.fullOutputs.length}</div>
        </div>
    `;
    renderProduction();
}

function renderProduction() {
    const container = document.getElementById('productionContainer');

    if (productionCache.length === 0) {
        container.innerHTML = '<div class="empty-note">Keine eigenen Produktionsanlagen in diesem Spielstand gefunden.</div>';
        return;
    }

    const plan = productionPlannerItems(productionCache);

    container.innerHTML = renderProductionPlanner(plan) + productionCache.map(pp => {
        const productions = Array.isArray(pp.productions) ? pp.productions : [];
        const inputStorages = Array.isArray(pp.inputStorages) ? pp.inputStorages : [];
        const outputStorages = Array.isArray(pp.outputStorages) ? pp.outputStorages : [];
        const activeCountValue = Number(pp.activeCount);
        const activeCount = Number.isFinite(activeCountValue)
            ? activeCountValue
            : productions.filter(prod => prod?.enabled === true).length;
        const rows = productions.map(prod => `
            <div class="production-row">
                <span class="production-dot on"></span>
                <span>${escapeHtml(prod.label || prod.name || prod.id || 'Produktion')}</span>
                <span class="production-status">aktiv</span>
            </div>
        `).join('');
        const inputSection = inputStorages.length > 0 ? `
            <div class="production-section">
                <div class="production-section-label">Betriebsstoffe</div>
                <div class="production-storage-grid">
                    ${inputStorages.map(storage => renderProductionStorage(storage, String(storage.fillType || '').toUpperCase() === 'WATER' ? '💧' : '📥')).join('')}
                </div>
            </div>` : '';
        const outputSection = outputStorages.length > 0 ? `
            <div class="production-section">
                <div class="production-section-label">Produzierte Waren</div>
                <div class="production-storage-grid">
                    ${outputStorages.map(storage => renderProductionStorage(storage, '📦')).join('')}
                </div>
            </div>` : '';

        return `
            <div class="production-card">
                <div class="production-card-header">
                    <span class="production-name">${escapeHtml(pp.name)}</span>
                    <span class="production-count-badge">${activeCount} aktiv</span>
                </div>
                <div class="production-section-label">Aktive Produktionen</div>
                ${rows || '<div class="empty-note">Keine aktive Produktion.</div>'}
                ${inputSection}
                ${outputSection}
            </div>
        `;
    }).join('');
}

// =================================================================
// Marktpreise / Verkaufsplaner
// =================================================================
