from pathlib import Path
import re

path = Path('index.html')
html = path.read_text(encoding='utf-8')

new_css = r'''    /* Fuhrpark */
    #vehiclesContainer {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
        gap: 14px;
        margin-top: 18px;
        align-items: start;
    }
    .vehicle-card {
        min-width: 0;
        background: var(--panel);
        border: 1px solid var(--border);
        border-left: 3px solid var(--secondary);
        border-radius: 10px;
        padding: 14px;
        box-shadow: 0 8px 22px rgba(0,0,0,0.08);
    }
    .vehicle-card.needs-attention {
        border-left-color: var(--rust-500);
        box-shadow: inset 0 0 0 1px rgba(168,85,57,0.08), 0 8px 22px rgba(0,0,0,0.08);
    }
    .vehicle-card-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 10px;
    }
    .vehicle-card-title { min-width: 0; }
    .vehicle-name {
        display: block;
        font-family: var(--font-display);
        font-weight: 600;
        font-size: 14px;
        line-height: 1.25;
        overflow-wrap: anywhere;
    }
    .vehicle-subline {
        display: block;
        margin-top: 3px;
        color: var(--muted);
        font-family: var(--font-mono);
        font-size: 9.5px;
    }
    .vehicle-type-badge {
        flex-shrink: 0;
        font-family: var(--font-mono);
        font-size: 9.5px;
        color: var(--muted);
        border: 1px solid var(--ink-700);
        background: var(--ink-950);
        padding: 3px 7px;
        border-radius: 10px;
        white-space: nowrap;
    }
    .vehicle-alerts {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
        margin: -2px 0 9px;
    }
    .vehicle-alert-chip {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        border: 1px solid rgba(168,85,57,0.38);
        background: rgba(168,85,57,0.08);
        color: var(--rust-500);
        padding: 2px 6px;
        border-radius: 10px;
        font-family: var(--font-mono);
        font-size: 9px;
    }
    .vehicle-meta-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 7px;
        margin-bottom: 12px;
    }
    .vehicle-meta-item {
        min-width: 0;
        padding: 7px 8px;
        border: 1px solid var(--ink-800);
        border-radius: 6px;
        background: var(--ink-950);
    }
    .vehicle-meta-label {
        display: block;
        margin-bottom: 2px;
        color: var(--muted);
        font-family: var(--font-mono);
        font-size: 8.5px;
        text-transform: uppercase;
        letter-spacing: .05em;
    }
    .vehicle-meta-value {
        display: block;
        color: var(--text);
        font-family: var(--font-mono);
        font-size: 10.5px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .vehicle-section {
        margin-top: 11px;
        padding-top: 10px;
        border-top: 1px solid var(--ink-800);
    }
    .vehicle-section:first-of-type {
        margin-top: 0;
        padding-top: 0;
        border-top: 0;
    }
    .vehicle-section-title {
        display: flex;
        align-items: center;
        gap: 7px;
        margin-bottom: 7px;
        color: var(--muted);
        font-family: var(--font-mono);
        font-size: 8.5px;
        text-transform: uppercase;
        letter-spacing: .08em;
    }
    .vehicle-section-title::after {
        content: '';
        height: 1px;
        background: var(--ink-800);
        flex: 1;
    }
    .vehicle-condition-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 7px;
    }
    .vehicle-condition {
        min-width: 0;
        padding: 8px 9px;
        border: 1px solid var(--ink-800);
        border-radius: 6px;
        background: rgba(20,22,14,0.32);
    }
    .vehicle-condition.is-zero { opacity: .72; }
    .vehicle-condition-head {
        display: flex;
        justify-content: space-between;
        gap: 8px;
        align-items: baseline;
        margin-bottom: 5px;
        font-family: var(--font-mono);
        font-size: 9.5px;
        color: var(--muted);
    }
    .vehicle-condition-head strong {
        color: var(--text);
        font-weight: 500;
    }
    .vehicle-condition .bar-track { margin: 0; }
    .vehicle-fill-section {
        margin-top: 11px;
        padding-top: 10px;
        border-top: 1px solid var(--ink-800);
    }
    .vehicle-fill-grid {
        display: grid;
        gap: 7px;
    }
    .vehicle-fill-unit {
        min-width: 0;
        padding: 8px 9px;
        border: 1px solid var(--ink-800);
        border-radius: 6px;
        background: rgba(20,22,14,0.32);
    }
    .vehicle-fill-unit.is-empty { opacity: .72; }
    .vehicle-fill-head {
        display: flex;
        align-items: baseline;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 5px;
    }
    .vehicle-fill-name {
        min-width: 0;
        color: var(--text);
        font-size: 11px;
        font-weight: 600;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
    .vehicle-fill-percent {
        flex-shrink: 0;
        color: var(--text);
        font-family: var(--font-mono);
        font-size: 9.5px;
    }
    .vehicle-fill-meta {
        display: flex;
        justify-content: space-between;
        gap: 8px;
        margin-top: 5px;
        color: var(--muted);
        font-family: var(--font-mono);
        font-size: 9px;
    }
    .vehicle-fill-kind {
        color: var(--moss-400);
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    .vehicle-fill-volume { white-space: nowrap; }
    .vehicle-fill-unit .bar-track { margin: 0; }

    @media (max-width: 700px) {
        #vehiclesContainer { grid-template-columns: 1fr; }
        .vehicle-meta-grid,
        .vehicle-condition-grid { grid-template-columns: 1fr 1fr; }
    }

'''

css_pattern = re.compile(r'    /\* Fuhrpark \*/\n.*?(?=    /\* Tiere \*/)', re.S)
if len(css_pattern.findall(html)) != 1:
    raise SystemExit('vehicle CSS block not found exactly once')
html = css_pattern.sub(new_css, html, count=1)

new_fill_fn = r'''function renderVehicleFillUnits(vehicle) {
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
'''

fill_pattern = re.compile(r'function renderVehicleFillUnits\(vehicle\) \{.*?\n\}\n\n(?=function renderVehicles\(\))', re.S)
if len(fill_pattern.findall(html)) != 1:
    raise SystemExit('renderVehicleFillUnits not found exactly once')
html = fill_pattern.sub(new_fill_fn + '\n', html, count=1)

new_render_fn = r'''function renderVehicles() {
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

    container.innerHTML = visible.map(v => {
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
'''

render_pattern = re.compile(r'function renderVehicles\(\) \{.*?\n\}\n\n(?=// =================================================================\n// Tierbestände)', re.S)
if len(render_pattern.findall(html)) != 1:
    raise SystemExit('renderVehicles not found exactly once')
html = render_pattern.sub(new_render_fn + '\n', html, count=1)

required = [
    'vehicle-meta-grid',
    'vehicle-condition-grid',
    'vehicle-fill-grid',
    "renderGroup('Kraftstoff & Betriebsstoffe'",
    "renderGroup('Füllstände'",
    'Wartung empfohlen',
    'Waschen empfohlen',
    "api.php?action=vehicles_data",
]
for token in required:
    if token not in html:
        raise SystemExit(f'missing vehicle UI token: {token}')

path.write_text(html, encoding='utf-8')
