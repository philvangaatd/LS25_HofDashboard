let marketCache = [];
let marketSortMode = 'smart';

async function loadMarketData() {
    const container = document.getElementById('marketContainer');
    container.innerHTML = '<div class="empty-note">Lade Marktpreise …</div>';
    const res = await fetch('api.php?action=market_data');
    const data = await res.json();
    if (data.error) { container.innerHTML = `<div class="empty-note">${escapeHtml(data.error)}</div>`; return; }
    marketCache = data.market;
    document.getElementById('marketPeriodLabel').textContent = `Livepreise · ${data.currentPeriodLabel} · ${Number(data.liveAge || 0)} s alt`;
    renderMarket();
}

let marketCategoryFilter = 'ALL';

function setMarketFilter(cat) {
    marketCategoryFilter = cat;
    document.querySelectorAll('#marketCategorySwitch .mode-btn').forEach(b => {
        b.classList.toggle('active', b.dataset.cat === cat);
    });
    renderMarket();
}

function setMarketSort(mode) {
    marketSortMode = mode || 'smart';
    renderMarket();
}

function renderMarket() {
    const filter = document.getElementById('marketFilterInput').value.toLowerCase().trim();
    const container = document.getElementById('marketContainer');

    const sorted = marketCache.slice().sort((a, b) => {
        const aHit = isAlertHit(a);
        const bHit = isAlertHit(b);
        if (aHit !== bHit) return aHit ? -1 : 1;

        switch (marketSortMode) {
            case 'best_desc':
                return Number(b.currentPrice || 0) - Number(a.currentPrice || 0) || a.label.localeCompare(b.label, 'de');
            case 'best_asc':
                return Number(a.currentPrice || 0) - Number(b.currentPrice || 0) || a.label.localeCompare(b.label, 'de');
            case 'name_asc':
                return a.label.localeCompare(b.label, 'de');
            case 'stations_desc':
                return Number(b.stationCount || 0) - Number(a.stationCount || 0) || Number(b.currentPrice || 0) - Number(a.currentPrice || 0);
            case 'spread_desc':
                return Number(b.priceSpread || 0) - Number(a.priceSpread || 0) || Number(b.currentPrice || 0) - Number(a.currentPrice || 0);
            case 'smart':
            default:
                if (a.isOwnCrop !== b.isOwnCrop) return a.isOwnCrop ? -1 : 1;
                return Number(b.currentPrice || 0) - Number(a.currentPrice || 0) || a.label.localeCompare(b.label, 'de');
        }
    });

    const visible = sorted.filter(m => {
        if (marketCategoryFilter !== 'ALL' && m.category !== marketCategoryFilter) return false;
        if (!filter) return true;
        const stationText = (m.stations || []).map(s => s.name || '').join(' ');
        return `${m.label || ''} ${m.fruitType || ''} ${stationText}`.toLowerCase().includes(filter);
    });

    if (visible.length === 0) {
        container.innerHTML = '<div class="empty-note">Keine passenden Marktpreise gefunden.</div>';
        return;
    }

    container.innerHTML = visible.map(m => {
        const alertValue = getPriceAlert(m.fruitType);
        const alertHit = isAlertHit(m);
        const stations = Array.isArray(m.stations) ? m.stations : [];
        const stationRows = stations.length > 0
            ? stations.map((s, idx) => `
                <div class="market-station-row ${idx === 0 ? 'best' : ''}">
                    <span class="market-station-name">${idx === 0 ? '★ ' : ''}${escapeHtml(s.name || 'Verkaufsstation')}</span>
                    <span class="market-station-price">${Number(s.price || 0).toLocaleString('de-DE')} €</span>
                </div>
              `).join('')
            : '<div class="market-station-empty">Keine sichtbaren Verkaufsstationen gemeldet.</div>';

        const bestStation = m.bestStation ? escapeHtml(m.bestStation) : '–';
        const rangeText = Number(m.stationCount || 0) > 1
            ? `${Number(m.minPrice || 0).toLocaleString('de-DE')}–${Number(m.maxPrice || 0).toLocaleString('de-DE')} €`
            : `${Number(m.currentPrice || 0).toLocaleString('de-DE')} €`;

        return `
            <div class="market-card ${m.isOwnCrop ? 'own-crop' : ''} ${alertHit ? 'alert-hit' : ''}">
                <div class="market-card-header">
                    <span class="market-name">${escapeHtml(m.label)}</span>
                    ${m.isOwnCrop ? '<span class="market-own-badge">eigene Kultur</span>' : ''}
                </div>
                <div class="market-live-price-row">
                    <span class="market-current-price">${Number(m.currentPrice || 0).toLocaleString('de-DE')} €</span>
                    <span class="market-live-unit">/ 1.000 L</span>
                </div>
                <div class="market-best-station">Bester Livepreis · ${bestStation}</div>
                <div class="market-stations">${stationRows}</div>
                <div class="market-detail-row">
                    <span>${Number(m.stationCount || 0)} Verkaufsstation${Number(m.stationCount || 0) === 1 ? '' : 'en'} · Spanne ${rangeText} / 1.000 L</span>
                </div>
                <div class="market-alert-row">
                    <label>🔔 Alarm ab</label>
                    <input type="number" value="${alertValue !== null ? alertValue : ''}" placeholder="z. B. 400" data-fruit="${escapeAttr(m.fruitType)}" onchange="updatePriceAlert(this)">
                    <span>€ / 1.000 L</span>
                </div>
            </div>
        `;
    }).join('');
}

function getPriceAlert(fruitType) {
    const raw = userSettings.priceAlerts[fruitType];
    const val = parseFloat(raw);
    return isNaN(val) ? null : val;
}

function isAlertHit(m) {
    const alert = getPriceAlert(m.fruitType);
    return alert !== null && alert > 0 && m.currentPrice >= alert;
}

function updatePriceAlert(input) {
    const fruitType = input.dataset.fruit;
    const value = parseFloat(input.value);
    if (!input.value || isNaN(value) || value <= 0) {
        delete userSettings.priceAlerts[fruitType];
    } else {
        userSettings.priceAlerts[fruitType] = value;
    }
    persistUserSettings();
    renderMarket(); // neu sortieren/markieren
    updateMarketBadge(marketCache.filter(m => isAlertHit(m))); // Badge sofort aktualisieren, nicht erst beim nächsten Aktualisieren der Übersicht
}

// =================================================================
// Vertrags-Feed
// =================================================================
async function loadMissionsData() {
    const container = document.getElementById('missionsContainer');
    container.innerHTML = '<div class="empty-note">Lade Verträge …</div>';
    const res = await fetch('api.php?action=missions_data');
    const data = await res.json();
    if (data.error) { container.innerHTML = `<div class="empty-note">${escapeHtml(data.error)}</div>`; return; }

    if (data.missions.length === 0) {
        container.innerHTML = '<div class="empty-note">Keine aktiven Verträge gefunden.</div>';
        return;
    }

    container.innerHTML = data.missions.map(m => {
        const progress = Number(m.progress || 0);
        const statusText = m.isActive ? 'aktiv' : (progress > 0 ? `${Math.round(progress)}%` : 'verfügbar');
        const showFieldCrop = m.fieldCrop && m.fieldCrop !== m.detail;
        const cropInfo = showFieldCrop ? `<span class="mission-extra">Aktuell auf dem Feld: ${escapeHtml(m.fieldCrop)}</span>` : '';
        const rewardInfo = m.reward > 0 ? `<span class="mission-reward">${Math.round(m.reward).toLocaleString('de-DE')} €</span>` : '';
        return `
            <div class="mission-row ${m.isActive ? 'urgent' : ''}">
                <span class="mission-type">${escapeHtml(m.typeLabel)}</span>
                <span class="mission-field">Feld ${escapeHtml(m.fieldId || '–')}</span>
                <span class="mission-detail">${escapeHtml(m.detail || '')} ${cropInfo}</span>
                ${rewardInfo}
                <span class="mission-days ${m.isActive ? 'urgent' : ''}">${statusText}</span>
            </div>
        `;
    }).join('');
}

// =================================================================
// Systemcheck
// =================================================================
const SYSCHECK_ICONS = { ok: '✅', warn: '⚠️', error: '❌', info: 'ℹ️' };
const HIDDEN_SYSTEM_CHECKS = new Set([
    'PHP-Version',
    'Upload-Limit (upload_max_filesize / post_max_size)',
    'Geladene php.ini',
    'Zeitzone',
]);

function isUserFacingSystemCheck(check) {
    return !HIDDEN_SYSTEM_CHECKS.has(check.label)
        && !check.label.startsWith('PHP-Erweiterung');
}

async function loadSystemCheck() {
    const container = document.getElementById('systemCheckContainer');
    container.innerHTML = '<div class="empty-note">Prüfe System …</div>';
    const res = await fetch('api.php?action=system_check');
    const data = await res.json();
    if (data.error) { container.innerHTML = `<div class="empty-note">${escapeHtml(data.error)}</div>`; return; }

    container.innerHTML = data.checks.filter(isUserFacingSystemCheck).map(c => `
        <div class="syscheck-row status-${c.status}">
            <span class="syscheck-icon">${SYSCHECK_ICONS[c.status] || '•'}</span>
            <span class="syscheck-label">${escapeHtml(c.label)}</span>
            <span class="syscheck-detail">${escapeHtml(c.detail)}</span>
        </div>
    `).join('');
}
