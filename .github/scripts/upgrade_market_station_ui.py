from pathlib import Path
import re

api_path = Path('api.php')
index_path = Path('index.html')
api = api_path.read_text(encoding='utf-8')
index = index_path.read_text(encoding='utf-8')

# ------------------------------------------------------------------
# PHP: Live-Markt ausschließlich aus Lua-Stationspreisen normalisieren.
# ------------------------------------------------------------------
new_market_endpoint = r'''// ---------------------------------------------------------------
// Marktpreise / Verkaufsplaner – echte Livepreise je Verkaufsstation
// ---------------------------------------------------------------
if ($action === 'market_data' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $liveData = get_live_mod_data();

    if (($liveData['status'] ?? '') === 'no_mod') {
        echo json_encode(['error' => 'Mod nicht aktiv.']);
        exit;
    }
    if (($liveData['status'] ?? '') === 'error') {
        echo json_encode(['error' => $liveData['message'] ?? 'Live-Daten konnten nicht gelesen werden.']);
        exit;
    }

    $ownCrops = [];
    foreach (($liveData['fields'] ?? []) as $field) {
        $fruitType = strtoupper((string)($field['fruitType'] ?? ''));
        if ($fruitType !== '' && $fruitType !== 'NONE' && $fruitType !== 'UNKNOWN') {
            $ownCrops[$fruitType] = true;
        }
    }

    $market = [];
    foreach (($liveData['market'] ?? []) as $m) {
        $ft = strtoupper((string)($m['fillType'] ?? ''));
        if ($ft === '') continue;

        $stations = [];
        foreach (($m['stations'] ?? []) as $station) {
            $price = (float)($station['pricePer1000L'] ?? 0);
            if ($price <= 0) continue;
            $stations[] = [
                'name'  => (string)($station['name'] ?? 'Verkaufsstation'),
                'price' => (int)round($price),
            ];
        }

        usort($stations, static function(array $a, array $b): int {
            $priceCmp = $b['price'] <=> $a['price'];
            return $priceCmp !== 0 ? $priceCmp : strcasecmp($a['name'], $b['name']);
        });

        $currentPrice = $stations[0]['price'] ?? (int)round((float)($m['bestPrice'] ?? $m['pricePerTon'] ?? 0));
        if ($currentPrice <= 0) continue;

        $bestStation = $stations[0]['name'] ?? (string)($m['bestStation'] ?? '');
        $minPrice = $stations ? min(array_column($stations, 'price')) : $currentPrice;
        $maxPrice = $stations ? max(array_column($stations, 'price')) : $currentPrice;

        $market[] = [
            'fruitType'       => $ft,
            'label'           => (string)($m['title'] ?? $ft),
            'category'        => (string)($m['category'] ?? 'product'),
            'unit'            => '1000L',
            'currentPrice'    => $currentPrice,
            'bestPrice'       => $currentPrice,
            'bestStation'     => $bestStation,
            'stationCount'    => count($stations),
            'stations'        => $stations,
            'minPrice'        => $minPrice,
            'maxPrice'        => $maxPrice,
            'priceSpread'     => max(0, $maxPrice - $minPrice),
            'basePricePerTon' => (int)round((float)($m['basePriceTon'] ?? 0)),
            'isOwnCrop'       => isset($ownCrops[$ft]),
        ];
    }

    usort($market, static function(array $a, array $b): int {
        $priceCmp = $b['currentPrice'] <=> $a['currentPrice'];
        return $priceCmp !== 0 ? $priceCmp : strcasecmp($a['label'], $b['label']);
    });

    $periodLabel = 'Unbekannt';
    $currentDayLive = (int)($liveData['currentDay'] ?? 0);
    $daysPerPeriodLive = (int)($liveData['daysPerPeriod'] ?? 0);
    if ($currentDayLive > 0 && $daysPerPeriodLive > 0) {
        $pidx = get_current_period_index($currentDayLive, $daysPerPeriodLive);
        $periodLabel = MARKET_PERIOD_LABELS_DE[MARKET_PERIOD_ORDER[$pidx]] ?? 'Unbekannt';
    }

    echo json_encode([
        'source'             => 'lua-live-stations',
        'modVersion'         => $liveData['version'] ?? '',
        'currentPeriodLabel' => $periodLabel,
        'market'             => $market,
        'liveAge'            => $liveData['fileAgeSeconds'] ?? 0,
    ]);
    exit;
}

'''

api_pattern = re.compile(
    r'// ---------------------------------------------------------------\n// Marktpreise / Verkaufsplaner\n// ---------------------------------------------------------------\nif \(\$action === \'market_data\'.*?(?=// ---------------------------------------------------------------\n// Vertrags-Feed)',
    re.S,
)
if not api_pattern.search(api):
    raise SystemExit('market_data endpoint block not found')
api = api_pattern.sub(new_market_endpoint, api, count=1)

# ------------------------------------------------------------------
# Frontend: Toolbar + Legende.
# ------------------------------------------------------------------
old_toolbar = '''                <input type="text" id="marketFilterInput" placeholder="Filtern nach Kultur …" style="flex:1" oninput="renderMarket()">
                <span class="map-hint" id="marketPeriodLabel"></span>'''
new_toolbar = '''                <input type="text" id="marketFilterInput" placeholder="Filtern nach Ware oder Verkaufsstation …" style="flex:1" oninput="renderMarket()">
                <select id="marketSortSelect" onchange="setMarketSort(this.value)" style="width:auto">
                    <option value="smart">Sortieren: Empfohlen</option>
                    <option value="best_desc">Bestpreis: hoch → niedrig</option>
                    <option value="best_asc">Bestpreis: niedrig → hoch</option>
                    <option value="name_asc">Name: A → Z</option>
                    <option value="stations_desc">Meiste Verkaufsstationen</option>
                    <option value="spread_desc">Größte Preisspanne</option>
                </select>
                <span class="map-hint" id="marketPeriodLabel"></span>'''
if old_toolbar not in index:
    raise SystemExit('market toolbar block not found')
index = index.replace(old_toolbar, new_toolbar, 1)

legend_pattern = re.compile(r'<div class="legend-line">Basispreis der Marktentwicklung.*?</div>')
new_legend = '<div class="legend-line">Live-Verkaufspreise direkt aus den FS25-Verkaufsstationen · der große Preis ist der aktuell beste erzielbare Preis je 1.000 L · darunter stehen alle Stationen mit ihrem echten Livepreis · 🔔 Preis-Alarm wird gegen den aktuellen Bestpreis geprüft und Treffer werden hervorgehoben</div>'
if not legend_pattern.search(index):
    raise SystemExit('market legend not found')
index = legend_pattern.sub(new_legend, index, count=1)

# ------------------------------------------------------------------
# Frontend: zusätzliche Styles für Stationsliste.
# ------------------------------------------------------------------
css_anchor = '''    .market-detail-row {
'''
css_extra = '''    .market-live-price-row { display: flex; align-items: baseline; gap: 6px; }
    .market-live-unit { color: var(--muted); font-family: var(--font-mono); font-size: 10px; }
    .market-best-station { color: var(--moss-400); font-family: var(--font-mono); font-size: 11px; margin: 2px 0 10px; }
    .market-stations { border-top: 1px solid var(--ink-800); border-bottom: 1px solid var(--ink-800); margin: 8px 0; }
    .market-station-row { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 10px; align-items: center; padding: 6px 0; font-size: 11px; font-family: var(--font-mono); }
    .market-station-row + .market-station-row { border-top: 1px solid var(--ink-800); }
    .market-station-row.best .market-station-name,
    .market-station-row.best .market-station-price { color: var(--accent); font-weight: 500; }
    .market-station-name { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--muted); }
    .market-station-price { white-space: nowrap; color: var(--text); }
    .market-station-empty { color: var(--muted); font-family: var(--font-mono); font-size: 11px; padding: 7px 0; }
'''
if css_anchor not in index:
    raise SystemExit('market css anchor not found')
index = index.replace(css_anchor, css_extra + css_anchor, 1)

# ------------------------------------------------------------------
# Frontend: State und Live-Hinweis.
# ------------------------------------------------------------------
if 'let marketCache = [];' not in index:
    raise SystemExit('marketCache declaration not found')
index = index.replace('let marketCache = [];', "let marketCache = [];\nlet marketSortMode = 'smart';", 1)

old_period_line = "    document.getElementById('marketPeriodLabel').textContent = `Aktuelle Saisonperiode: ${data.currentPeriodLabel}`;"
new_period_line = "    document.getElementById('marketPeriodLabel').textContent = `Livepreise · ${data.currentPeriodLabel} · ${Number(data.liveAge || 0)} s alt`;"
if old_period_line not in index:
    raise SystemExit('market period label line not found')
index = index.replace(old_period_line, new_period_line, 1)

# Sortierfunktion vor renderMarket ergänzen.
render_anchor = 'function renderMarket() {'
if render_anchor not in index:
    raise SystemExit('renderMarket anchor not found')
set_sort = '''function setMarketSort(mode) {
    marketSortMode = mode || 'smart';
    renderMarket();
}

'''
index = index.replace(render_anchor, set_sort + render_anchor, 1)

# ------------------------------------------------------------------
# Frontend: Karten auf echte Stationspreise umstellen.
# ------------------------------------------------------------------
new_render = r'''function renderMarket() {
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

function getPriceAlert'''

render_pattern = re.compile(r'function renderMarket\(\) \{.*?\n\}\n\nfunction getPriceAlert', re.S)
if not render_pattern.search(index):
    raise SystemExit('renderMarket function block not found')
index = render_pattern.sub(new_render, index, count=1)

# Quick-Access um die aktuell beste Station ergänzen.
old_quick = '<span class="quick-meta">${m.currentPrice.toLocaleString(\'de-DE\')} €</span>'
new_quick = '<span class="quick-meta">${Number(m.currentPrice || 0).toLocaleString(\'de-DE\')} €${m.bestStation ? ` · ${escapeHtml(m.bestStation)}` : \'\'}</span>'
if old_quick in index:
    index = index.replace(old_quick, new_quick, 1)

api_path.write_text(api, encoding='utf-8')
index_path.write_text(index, encoding='utf-8')
