async function loadFarmOverview() {
    const grid = document.getElementById('statGrid');
    grid.innerHTML = '<div class="empty-note">Lade Hof-Übersicht …</div>';
    const res = await fetch('api.php?action=farm_overview');
    const data = await res.json();
    if (data.error) { grid.innerHTML = `<div class="empty-note">${escapeHtml(data.error)}</div>`; return; }

    document.getElementById('farmHeading').textContent = data.farmName || 'Unbenannter Hof';
    currentFarmName = data.farmName || '';
    currentMapTitle = data.mapTitle || '';
    document.getElementById('farmSub').textContent = data.manager
        ? `Bewirtschaftet von ${data.manager} · ${data.mapTitle}`
        : data.mapTitle;

    const money = data.money !== null ? Math.round(data.money).toLocaleString('de-DE') + ' €' : '–';
    const loan = data.loan !== null ? Math.round(data.loan).toLocaleString('de-DE') + ' €' : '–';
    const season = SEASON_LABELS[data.season] || data.season || '–';

    grid.innerHTML = `
        <div class="stat-card">
            <div class="stat-label">Kontostand</div>
            <div class="stat-value">${money}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Kredit</div>
            <div class="stat-value ${data.loan > 0 ? 'stat-warn' : ''}">${loan}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Spieltag</div>
            <div class="stat-value">Tag ${data.currentDay}</div>
            <div class="stat-sub">${season}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Spielzeit</div>
            <div class="stat-value">${data.playTimeHours ?? '–'} h</div>
        </div>
        <div class="stat-card ${data.harvestReadyCount > 0 ? 'stat-highlight' : ''}">
            <div class="stat-label">Felder erntereif</div>
            <div class="stat-value">${data.harvestReadyCount} / ${data.fieldCount}</div>
            ${data.harvestReadyCount > 0 ? '<div class="stat-sub">→ Feld-Tab öffnen</div>' : ''}
        </div>
        <div class="stat-card">
            <div class="stat-label">Fuhrpark</div>
            <div class="stat-value">${data.vehicleCount} Fahrzeuge</div>
        </div>
    `;

    renderQuickAccess(data);
    renderWeatherForecast(data.weatherForecast);
    checkPriceAlerts();
    renderLastSavedInfo(data.lastSaved);
}

function renderLastSavedInfo(lastSaved) {
    const el = document.getElementById('lastSavedInfo');
    el.style.display = 'block';
    const savedPart = lastSaved ? ` Letzter gespeicherter Spielstand: ${lastSaved}.` : '';
    el.textContent = `ℹ Live-Werte werden automatisch über FS25_HofDashboard aktualisiert.${savedPart} Bereiche, die weiterhin direkt Spielstand-Dateien lesen oder schreiben, beziehen sich auf diesen Speicherstand.`;
}

// -----------------------------------------------------------------
// Hofnamen ändern – im Singleplayer im Spiel selbst nicht möglich (steht dort
// immer als "Mein Hof" fest), lässt sich aber direkt in farms.xml ändern.
// -----------------------------------------------------------------
function editFarmName() {
    showPromptModal('Neuer Hofname:', currentFarmName, (name) => {
        updateFarmName(name.trim());
    }, 'Hofnamen ändern');
}

async function updateFarmName(name) {
    const res = await fetch('api.php?action=update_farm_name', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name })
    });
    const data = await res.json();
    if (data.error) { showToast(data.error, 'err'); return; }
    showToast(`Hofname geändert zu "${data.name}"`, 'ok');
    loadFarmOverview();
}

async function checkPriceAlerts() {
    const res = await fetch('api.php?action=market_data');
    const data = await res.json();
    const hits = data.error ? [] : data.market.filter(m => isAlertHit(m));
    updateMarketBadge(hits);
    renderPriceAlertQuickAccess(hits);
}

function updateMarketBadge(hits) {
    const btn = document.getElementById('tabBtnMarket');
    if (!btn) return;
    const existing = btn.querySelector('.tab-badge');
    if (existing) existing.remove();
    if (hits.length > 0) {
        const badge = document.createElement('span');
        badge.className = 'tab-badge';
        badge.textContent = hits.length;
        btn.appendChild(badge);
    }
}

function renderPriceAlertQuickAccess(hits) {
    const container = document.getElementById('quickAccessContainer');
    const existingSection = document.getElementById('priceAlertQuickSection');
    if (existingSection) existingSection.remove();
    if (hits.length === 0) return;

    // "Alles im grünen Bereich"-Hinweis entfernen, sonst widerspricht er sich mit dem Alarm
    const allGood = container.querySelector('.quick-all-good');
    if (allGood) allGood.closest('.quick-section')?.remove();

    const section = document.createElement('div');
    section.id = 'priceAlertQuickSection';
    section.className = 'quick-section';
    section.innerHTML = `
        <h3>🔔 Preis-Alarm ausgelöst</h3>
        <div class="quick-list">
            ${hits.map(m => `
                <div class="quick-row" onclick="switchTab('market')">
                    <span class="quick-title">${escapeHtml(m.label)}</span>
                    <span class="quick-meta">${Number(m.currentPrice || 0).toLocaleString('de-DE')} €${m.bestStation ? ` · ${escapeHtml(m.bestStation)}` : ''}</span>
                </div>
            `).join('')}
        </div>
    `;
    container.prepend(section); // zeitkritisch – ganz oben
}

function renderWeatherForecast(forecast) {
    const container = document.getElementById('weatherForecastContainer');
    if (!forecast || forecast.length === 0) { container.innerHTML = ''; return; }
    container.innerHTML = `
        <div class="quick-section">
            <h3>🌤️ Wettervorschau</h3>
            <div class="weather-row">
                ${forecast.map((f, i) => `
                    <div class="weather-day">
                        <div class="weather-day-label">${i === 0 ? 'Heute' : (i === 1 ? 'Morgen' : 'Tag ' + f.day)}</div>
                        <div class="weather-day-icon">${f.dominantTypeIcon}</div>
                        <div class="weather-day-season">${SEASON_LABELS[f.season] || f.season}</div>
                        ${f.hasPrecipitation ? '<div class="weather-day-precip">🌧 Niederschlag</div>' : ''}
                    </div>
                `).join('')}
            </div>
        </div>
    `;
}

function renderQuickAccess(data) {
    const container = document.getElementById('quickAccessContainer');
    const sections = [];

    if (data.harvestReadyFields && data.harvestReadyFields.length > 0) {
        sections.push(`
            <div class="quick-section">
                <h3>🌾 Bereit zur Ernte</h3>
                <div class="quick-list">
                    ${data.harvestReadyFields.map(f => `
                        <div class="quick-row" onclick="switchTab('fields')">
                            <span class="quick-title">Feld ${escapeHtml(f.id)}</span>
                            <span class="quick-meta">${escapeHtml(f.fruitTypeLabel)}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
        `);
    }

    if (data.vehiclesNeedingAttention && data.vehiclesNeedingAttention.length > 0) {
        sections.push(`
            <div class="quick-section">
                <h3>🚜 Fahrzeuge mit Wartungs-/Waschbedarf</h3>
                <div class="quick-list">
                    ${data.vehiclesNeedingAttention.map(v => `
                        <div class="quick-row" onclick="switchTab('vehicles')">
                            <span class="quick-title">${escapeHtml(v.name)}</span>
                            <span class="quick-meta">Verschleiß ${(v.wear * 100).toFixed(0)}% · Dreck ${(v.dirt * 100).toFixed(0)}%</span>
                        </div>
                    `).join('')}
                </div>
            </div>
        `);
    }

    if (data.missionsTotalCount > 0) {
        sections.push(`
            <div class="quick-section">
                <h3>🤝 Verträge</h3>
                <div class="quick-list">
                    <div class="quick-row" onclick="switchTab('missions')">
                        <span class="quick-title">${data.missionsTotalCount} Verträge verfügbar</span>
                        <span class="quick-meta">Vertrags-Tab öffnen</span>
                    </div>
                </div>
            </div>
        `);
    }

    if (sections.length === 0) {
        container.innerHTML = '<div class="quick-section"><div class="quick-all-good">✓ Aktuell nichts Dringendes – alles im grünen Bereich.</div></div>';
        return;
    }

    container.innerHTML = sections.join('');
}

// =================================================================
// Feld-Dashboard
// =================================================================
let fieldsCache = [];
let liveDataInterval = null;
const LIVE_POLL_MS = 15000;
let lastLiveTimestamp = null;

const FIELD_STATUS_ORDER = {
    READY: 0,
    MIXED: 1,
    GROWING: 2,
    WITHERED: 3,
    HARVESTED: 4,
    TILLED: 5,
    FALLOW: 6,
};

const FIELD_MIX_LABELS = {
    ready: 'Erntereif',
    growing: 'Im Wachstum',
    harvested: 'Abgeerntet',
    tilled: 'Bearbeitet',
    withered: 'Vertrocknet',
    fallow: 'Brache',
};

/** Startet das Live-Polling (idempotent – kein Doppel-Interval). */
function startLivePolling() {
    if (liveDataInterval !== null) return;
    pollLiveData();
    liveDataInterval = setInterval(pollLiveData, LIVE_POLL_MS);
}

function stopLivePolling() {
    if (liveDataInterval === null) return;
    clearInterval(liveDataInterval);
    liveDataInterval = null;
    lastLiveTimestamp = null;
    updateLiveStatusBadge({ status: 'no_mod' });
}

/** Bei einem neuen Lua-Export wird nur der aktive Tab neu geladen. */
async function pollLiveData() {
    try {
        const res = await fetch('api.php?action=live_data');
        const data = await res.json();
        updateLiveStatusBadge(data);

        if (data.status === 'ok' && data.timestamp && data.timestamp !== lastLiveTimestamp) {
            lastLiveTimestamp = data.timestamp;
            autoRefreshActiveTab();
        }
    } catch (e) {
        updateLiveStatusBadge({ status: 'error', message: String(e) });
    }
}

function autoRefreshActiveTab() {
    switch (activeTab) {
        case 'home':       loadFarmOverview(); break;
        case 'fields':     loadFieldsData(); break;
        case 'vehicles':   loadVehiclesData(); break;
        case 'animals':    loadAnimalsData(); break;
        case 'production': if (typeof loadProductionData === 'function') loadProductionData(); break;
        case 'market':     if (typeof loadMarketData === 'function') loadMarketData(); break;
        case 'missions':   if (typeof loadMissionsData === 'function') loadMissionsData(); break;
    }
}

function updateLiveStatusBadge(data) {
    const el = document.getElementById('liveStatusBadge');
    if (!el) return;
    const status = data.status || 'error';
    const labels = { ok: 'Live', stale: 'Veraltet', no_mod: 'Kein Mod', error: 'Fehler' };
    el.innerHTML = `<span class="live-pulse"></span>${labels[status] || status}`;
    el.className = `live-badge live-badge-${status}`;
    el.title = data.message
        || (data.timestamp ? `Letzter Mod-Export: ${data.timestamp.replace('T', ' ')}` : '');
}

function fieldChecklistKey(fieldId, step) {
    return `${fieldId}:${step}`;
}

async function loadFieldsData() {
    const container = document.getElementById('fieldsContainer');
    container.innerHTML = '<div class="empty-note">Lade Felddaten …</div>';
    const res = await fetch('api.php?action=fields_data');
    const data = await res.json();
    if (data.error) {
        container.innerHTML = `<div class="empty-note">${escapeHtml(data.error)}</div>`;
        return;
    }
    fieldsCache = Array.isArray(data.fields) ? data.fields : [];
    renderFields();
}

function renderMixedFieldBars(field) {
    if (field.fieldStatus !== 'MIXED') return '';
    const percentages = field.statusPercentages || {};
    const rows = Object.entries(FIELD_MIX_LABELS)
        .map(([key, label]) => [label, Number(percentages[key] || 0)])
        .filter(([, percent]) => percent >= 0.1)
        .sort((a, b) => b[1] - a[1]);

    if (rows.length === 0) return '';
    return `
        <div class="field-meta">Flächenzustand · ${field.sampleCount || 0} Messpunkte</div>
        ${rows.map(([label, percent]) => `
            <div class="bar-row">
                <span class="bar-label">${escapeHtml(label)}</span>
                <div class="bar-track"><div class="bar-fill-progress" style="width:${Math.min(100, percent)}%"></div></div>
                <span class="bar-value">${percent.toLocaleString('de-DE', { maximumFractionDigits: 1 })}%</span>
            </div>
        `).join('')}
    `;
}

function renderFields() {
    const filter = document.getElementById('fieldFilterInput').value.toLowerCase();
    const container = document.getElementById('fieldsContainer');

    const sorted = fieldsCache.slice().sort((a, b) => {
        const aOrd = FIELD_STATUS_ORDER[a.fieldStatus] ?? 99;
        const bOrd = FIELD_STATUS_ORDER[b.fieldStatus] ?? 99;
        if (aOrd !== bOrd) return aOrd - bOrd;
        return Number(a.id) - Number(b.id);
    });

    if (sorted.length === 0) {
        container.innerHTML = '<div class="empty-note">Keine eigenen Felder in diesem Spielstand gefunden.</div>';
        return;
    }

    const visible = sorted.filter(field => {
        if (!filter) return true;
        const label = field.fruitTypeLabel || '';
        return label.toLowerCase().includes(filter)
            || String(field.fruitType || '').toLowerCase().includes(filter)
            || String(field.id).includes(filter);
    });

    if (visible.length === 0) {
        container.innerHTML = '<div class="empty-note">Keine Treffer.</div>';
        return;
    }

    container.innerHTML = visible.map(field => {
        const ready = field.fieldStatus === 'READY';
        const mixed = field.fieldStatus === 'MIXED';
        const fruitLabel = field.fruitTypeLabel === null ? '–' : field.fruitTypeLabel;

        const stepsHtml = (field.steps || []).map(step => {
            const key = fieldChecklistKey(field.id, step);
            const checked = userSettings.fieldTasks[key] === true ? 'checked' : '';
            return `
                <label class="field-step">
                    <input type="checkbox" ${checked} onchange="toggleFieldStep('${escapeAttr(key)}', this)">
                    <span>${escapeHtml(step)}</span>
                </label>
            `;
        }).join('');

        const bars = [];
        if (field.fieldStatus === 'GROWING') {
            bars.push(['Wachstum', field.growthPercent, `${field.growthState}/${field.maxGrowthState}`]);
        }
        if (field.fieldStatus !== 'READY') {
            bars.push(['Kalk', field.limePercent, `${field.limeLevel}/3`]);
            bars.push(['Düngen', field.sprayPercent, `${field.sprayLevel}/2`]);
        }
        bars.push(['Unkraut', field.weedPercent, `${field.weedState}`]);

        const barsHtml = bars.map(([label, percent, valueText]) => `
            <div class="bar-row">
                <span class="bar-label">${label}</span>
                <div class="bar-track"><div class="bar-fill-progress" style="width:${percent}%"></div></div>
                <span class="bar-value">${valueText}</span>
            </div>
        `).join('');

        return `
            <div class="field-card ${ready ? 'field-ready' : ''} ${mixed ? 'field-mixed' : ''}">
                <div class="field-card-header">
                    <span class="field-name">Feld ${escapeHtml(field.id)}</span>
                    <span class="field-fruit">${escapeHtml(fruitLabel)}</span>
                    <span class="field-status ${ready ? 'field-status-ready' : ''} ${mixed ? 'field-status-mixed' : ''}">${escapeHtml(field.statusLabel)}</span>
                </div>
                <div class="field-card-body">
                    ${renderMixedFieldBars(field)}
                    ${barsHtml}
                    <div class="field-steps">${stepsHtml || '<span class="field-meta">Keine offenen Schritte erkannt.</span>'}</div>
                </div>
            </div>
        `;
    }).join('');
}

function toggleFieldStep(key, input) {
    if (input.checked) userSettings.fieldTasks[key] = true;
    else delete userSettings.fieldTasks[key];
    persistUserSettings();
}

// =================================================================
// Fuhrpark-Dashboard
// =================================================================
