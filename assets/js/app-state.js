let markers = [];
let knownGroups = [];
let collapsedGroups = new Set();
let selectedUids = new Set();
let originalSnapshot = null;
let uidCounter = 0;
let currentFolder = null;
let hasAutoDrive = false;
let activeTab = 'home';
let currentFarmName = '';
let currentMapTitle = '';
let userSettings = defaultUserSettings();
let userSettingsSaveQueue = Promise.resolve();
const UNGROUPED = '(ohne Gruppe)';

function defaultUserSettings() {
    return {
        schemaVersion: 1,
        terrainAlign: { offsetX: 0, offsetZ: 0, scale: 1 },
        priceAlerts: {},
        fieldTasks: {},
    };
}

function normalizeUserSettings(raw) {
    const defaults = defaultUserSettings();
    if (!raw || typeof raw !== 'object') return defaults;

    const terrain = raw.terrainAlign && typeof raw.terrainAlign === 'object'
        ? raw.terrainAlign
        : defaults.terrainAlign;
    const offsetX = Number(terrain.offsetX);
    const offsetZ = Number(terrain.offsetZ);
    const scale = Number(terrain.scale);

    return {
        schemaVersion: 1,
        terrainAlign: {
            offsetX: Number.isFinite(offsetX) ? offsetX : 0,
            offsetZ: Number.isFinite(offsetZ) ? offsetZ : 0,
            scale: Number.isFinite(scale) && scale > 0 ? scale : 1,
        },
        priceAlerts: raw.priceAlerts && typeof raw.priceAlerts === 'object'
            ? { ...raw.priceAlerts }
            : {},
        fieldTasks: raw.fieldTasks && typeof raw.fieldTasks === 'object'
            ? { ...raw.fieldTasks }
            : {},
    };
}

async function loadUserSettings(folder) {
    userSettings = defaultUserSettings();
    try {
        const res = await fetch(`api.php?action=user_settings&folder=${encodeURIComponent(folder)}`);
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'Einstellungen konnten nicht geladen werden.');
        userSettings = normalizeUserSettings(data.settings);
    } catch (error) {
        console.error('Benutzereinstellungen konnten nicht geladen werden:', error);
        showToast('Benutzereinstellungen konnten nicht geladen werden.', 'err');
    }
}

function persistUserSettings() {
    const folder = currentFolder;
    const settings = JSON.parse(JSON.stringify(userSettings));
    if (!folder) return Promise.resolve();

    userSettingsSaveQueue = userSettingsSaveQueue.then(async () => {
        const res = await fetch('api.php?action=user_settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ folder, settings }),
        });
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'Einstellungen konnten nicht gespeichert werden.');
    }).catch(error => {
        console.error('Benutzereinstellungen konnten nicht gespeichert werden:', error);
        if (currentFolder === folder) {
            showToast('Benutzereinstellungen konnten nicht gespeichert werden.', 'err');
        }
    });

    return userSettingsSaveQueue;
}

function applyAutoDriveTabVisibility() {
    document.getElementById('tabBtnMarkers').style.display = hasAutoDrive ? '' : 'none';
    document.getElementById('tabBtnMap').style.display = hasAutoDrive ? '' : 'none';
    if (!hasAutoDrive && (activeTab === 'markers' || activeTab === 'map')) {
        switchTab('home');
    }
}

function switchTab(tab) {
    if (activeTab === 'map' && tab !== 'map' && typeof isCourseDirty === 'function' && isCourseDirty()) {
        showConfirmModal(
            'Ungespeicherte Änderungen an der Route gehen verloren, wenn du den Tab wechselst. Trotzdem wechseln?',
            () => performSwitchTab(tab),
            'Trotzdem wechseln'
        );
        return;
    }
    performSwitchTab(tab);
}

function performSwitchTab(tab) {
    if ((tab === 'markers' || tab === 'map') && !hasAutoDrive) return; // Sicherheitsnetz, sollte über die Tabbar nicht erreichbar sein
    activeTab = tab;
    document.getElementById('tabHome').style.display = tab === 'home' ? 'block' : 'none';
    document.getElementById('tabFields').style.display = tab === 'fields' ? 'block' : 'none';
    document.getElementById('tabMarkers').style.display = tab === 'markers' ? 'block' : 'none';
    document.getElementById('tabMap').style.display = tab === 'map' ? 'block' : 'none';
    document.getElementById('tabVehicles').style.display = tab === 'vehicles' ? 'block' : 'none';
    document.getElementById('tabAnimals').style.display = tab === 'animals' ? 'block' : 'none';
    document.getElementById('tabStorage').style.display = tab === 'storage' ? 'block' : 'none';
    document.getElementById('tabProduction').style.display = tab === 'production' ? 'block' : 'none';
    document.getElementById('tabMarket').style.display = tab === 'market' ? 'block' : 'none';
    document.getElementById('tabMissions').style.display = tab === 'missions' ? 'block' : 'none';
    document.getElementById('tabSystem').style.display = tab === 'system' ? 'block' : 'none';
    document.getElementById('tabBtnHome').classList.toggle('active', tab === 'home');
    document.getElementById('tabBtnFields').classList.toggle('active', tab === 'fields');
    document.getElementById('tabBtnMarkers').classList.toggle('active', tab === 'markers');
    document.getElementById('tabBtnMap').classList.toggle('active', tab === 'map');
    document.getElementById('tabBtnVehicles').classList.toggle('active', tab === 'vehicles');
    document.getElementById('tabBtnAnimals').classList.toggle('active', tab === 'animals');
    document.getElementById('tabBtnStorage').classList.toggle('active', tab === 'storage');
    document.getElementById('tabBtnProduction').classList.toggle('active', tab === 'production');
    document.getElementById('tabBtnMarket').classList.toggle('active', tab === 'market');
    document.getElementById('tabBtnMissions').classList.toggle('active', tab === 'missions');
    document.getElementById('tabBtnSystem').classList.toggle('active', tab === 'system');
    if (tab === 'map') {
        ensureMapLoaded();
    } else if (tab === 'home') {
        loadFarmOverview();
    } else if (tab === 'fields') {
        loadFieldsData();
    } else if (tab === 'vehicles') {
        loadVehiclesData();
    } else if (tab === 'animals') {
        loadAnimalsData();
    } else if (tab === 'storage') {
        loadStorageData();
    } else if (tab === 'production') {
        loadProductionData();
    } else if (tab === 'market') {
        loadMarketData();
    } else if (tab === 'missions') {
        loadMissionsData();
    } else if (tab === 'system') {
        loadSystemCheck();
    }
}

// =================================================================
// Hof-Übersicht
// =================================================================
const SEASON_LABELS = { SPRING: 'Frühling', SUMMER: 'Sommer', AUTUMN: 'Herbst', WINTER: 'Winter' };

// =================================================================
// Vorräte
// =================================================================
let storageCache = [];
let storageViewMode = 'storage';

function installStorageTab() {
    if (!document.getElementById('storageStyles')) {
        const styleLink = document.createElement('link');
        styleLink.id = 'storageStyles';
        styleLink.rel = 'stylesheet';
        styleLink.href = 'assets/css/storage.css';
        document.head.appendChild(styleLink);
    }

    if (!document.getElementById('tabBtnStorage')) {
        const animalsButton = document.getElementById('tabBtnAnimals');
        if (animalsButton) {
            const button = document.createElement('button');
            button.className = 'tab-btn';
            button.id = 'tabBtnStorage';
            button.textContent = 'Vorräte';
            button.onclick = () => switchTab('storage');
            animalsButton.insertAdjacentElement('afterend', button);
        }
    }

    if (!document.getElementById('tabStorage')) {
        const animalsTab = document.getElementById('tabAnimals');
        if (animalsTab) {
            const tab = document.createElement('div');
            tab.id = 'tabStorage';
            tab.style.display = 'none';
            tab.innerHTML = `
                <div class="toolbar">
                    <button onclick="loadStorageData()"><span class="ui-icon">↻</span> Aktualisieren</button>
                    <div class="mode-switch" id="storageModeSwitch">
                        <button class="mode-btn active" data-storage-mode="storage" onclick="setStorageViewMode('storage')">Nach Lager</button>
                        <button class="mode-btn" data-storage-mode="product" onclick="setStorageViewMode('product')">Nach Produkt</button>
                    </div>
                    <input type="text" id="storageFilterInput" placeholder="Filtern nach Lager oder Produkt …" style="flex:1" oninput="renderStorageData()">
                </div>
                <div class="legend-line">Live aus FS25 · Silos und Erweiterungen · Ballen-/Palettenlager · Güllebehälter · Misthaufen · kompatible Mod-Lager über die GIANTS-Storage-APIs</div>
                <div class="stat-grid" id="storageStatGrid"></div>
                <div id="storageContainer"><div class="empty-note">Vorräte werden beim Öffnen geladen.</div></div>
            `;
            animalsTab.insertAdjacentElement('afterend', tab);
        }
    }
}

function setStorageViewMode(mode) {
    storageViewMode = mode === 'product' ? 'product' : 'storage';
    document.querySelectorAll('#storageModeSwitch .mode-btn').forEach(button => {
        button.classList.toggle('active', button.dataset.storageMode === storageViewMode);
    });
    renderStorageData();
}

function formatStorageLiters(value) {
    return Math.round(Number(value || 0)).toLocaleString('de-DE');
}

function storageObjectLabel(content) {
    const count = Math.max(0, Number(content?.objectCount || 0));
    if (count <= 0) return '';
    const kind = String(content?.objectKind || '').toLowerCase();
    const noun = kind === 'bale' ? (count === 1 ? 'Ballen' : 'Ballen')
        : kind === 'pallet' ? (count === 1 ? 'Palette' : 'Paletten')
        : (count === 1 ? 'Objekt' : 'Objekte');
    return `${count.toLocaleString('de-DE')} ${noun}`;
}

function storageTypeLabel(storage) {
    return storage?.typeLabel || ({
        silo: 'Silo',
        siloExtension: 'Silo-Erweiterung',
        manureHeap: 'Misthaufen',
        liquidManure: 'Güllebehälter',
        objectStorage: 'Ballen-/Palettenlager',
    }[storage?.type] || 'Lager');
}

function normalizeStorageRecord(storage) {
    const contents = (Array.isArray(storage?.contents) ? storage.contents : []).map(content => ({
        fillType: String(content?.fillType || 'UNKNOWN'),
        title: String(content?.title || content?.fillType || 'Unbekannt'),
        level: Math.max(0, Number(content?.level || 0)),
        capacity: Math.max(0, Number(content?.capacity || 0)),
        percent: Math.max(0, Math.min(100, Number(content?.percent || 0))),
        objectCount: Math.max(0, Number(content?.objectCount || 0)),
        objectKind: String(content?.objectKind || ''),
    }));

    return {
        id: String(storage?.id || ''),
        name: String(storage?.name || 'Lager'),
        type: String(storage?.type || 'storage'),
        typeLabel: String(storage?.typeLabel || ''),
        farmId: Number(storage?.farmId || 0),
        isMod: storage?.isMod === true,
        modName: String(storage?.modName || ''),
        capacityLiters: Math.max(0, Number(storage?.capacityLiters || 0)),
        objectCount: Math.max(0, Number(storage?.objectCount || 0)),
        objectCapacity: Math.max(0, Number(storage?.objectCapacity || 0)),
        supportsBales: storage?.supportsBales === true,
        supportsPallets: storage?.supportsPallets === true,
        contents,
    };
}

async function loadStorageData() {
    const container = document.getElementById('storageContainer');
    if (!container) return;
    container.innerHTML = '<div class="empty-note">Lade Vorräte aus FS25 …</div>';

    try {
        const response = await fetch('api.php?action=live_data');
        const data = await response.json();
        if (!response.ok || data.error || data.status === 'error') {
            throw new Error(data.error || data.message || 'Live-Daten konnten nicht geladen werden.');
        }
        if (data.status === 'no_mod') {
            storageCache = [];
            document.getElementById('storageStatGrid').innerHTML = '';
            container.innerHTML = '<div class="empty-note">Mod nicht aktiv. FS25_HofDashboard v5.2.0 oder neuer aktivieren und Spiel starten.</div>';
            return;
        }

        storageCache = (Array.isArray(data.storages) ? data.storages : []).map(normalizeStorageRecord);
        if (!Array.isArray(data.storages) && String(data.version || '') !== '') {
            document.getElementById('storageStatGrid').innerHTML = '';
            container.innerHTML = `<div class="empty-note">Die aktive Live-Mod v${escapeHtml(String(data.version))} liefert noch keine Vorratsdaten. Bitte Mod v5.2.0 oder neuer installieren.</div>`;
            return;
        }
        renderStorageStats();
        renderStorageData();
    } catch (error) {
        console.error('Vorräte konnten nicht geladen werden:', error);
        storageCache = [];
        document.getElementById('storageStatGrid').innerHTML = '';
        container.innerHTML = `<div class="empty-note">${escapeHtml(error.message || 'Vorräte konnten nicht geladen werden.')}</div>`;
    }
}

function renderStorageStats() {
    const grid = document.getElementById('storageStatGrid');
    if (!grid) return;

    const totalLiters = storageCache.reduce((sum, storage) =>
        sum + storage.contents.reduce((inner, content) => inner + Number(content.level || 0), 0), 0);
    const objectCount = storageCache.reduce((sum, storage) => sum + Number(storage.objectCount || 0), 0);
    const fillTypes = new Set();
    storageCache.forEach(storage => storage.contents.forEach(content => fillTypes.add(String(content.fillType || content.title))));
    const modStorages = storageCache.filter(storage => storage.isMod).length;

    grid.innerHTML = `
        <div class="stat-card">
            <div class="stat-label">Lager</div>
            <div class="stat-value">${storageCache.length.toLocaleString('de-DE')}</div>
            <div class="stat-sub">davon ${modStorages.toLocaleString('de-DE')} aus Mods</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Eingelagert</div>
            <div class="stat-value">${formatStorageLiters(totalLiters)} L</div>
            <div class="stat-sub">über alle mengenbasierten Lager</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Produkte</div>
            <div class="stat-value">${fillTypes.size.toLocaleString('de-DE')}</div>
            <div class="stat-sub">unterschiedliche FillTypes / Objektarten</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Ballen / Paletten</div>
            <div class="stat-value">${objectCount.toLocaleString('de-DE')}</div>
            <div class="stat-sub">aktuell in ObjectStorages</div>
        </div>
    `;
}

function filteredStorages() {
    const query = String(document.getElementById('storageFilterInput')?.value || '').trim().toLocaleLowerCase('de-DE');
    if (!query) return storageCache;
    return storageCache.filter(storage => {
        const haystack = [storage.name, storage.typeLabel, storage.modName]
            .concat(storage.contents.flatMap(content => [content.title, content.fillType]))
            .join(' ')
            .toLocaleLowerCase('de-DE');
        return haystack.includes(query);
    });
}

function storageContentRow(content) {
    const objectLabel = storageObjectLabel(content);
    const amountParts = [];
    if (Number(content.level || 0) > 0) amountParts.push(`${formatStorageLiters(content.level)} L`);
    if (objectLabel) amountParts.push(objectLabel);
    const amount = amountParts.length ? amountParts.join(' · ') : '0 L';
    const capacity = Number(content.capacity || 0);
    const percentage = capacity > 0 ? Math.max(0, Math.min(100, Number(content.percent || 0))) : null;

    return `
        <div class="storage-content-row">
            <div class="storage-product">
                <strong>${escapeHtml(content.title || content.fillType)}</strong>
                <span>${escapeHtml(content.fillType)}</span>
            </div>
            <div class="storage-amount">${escapeHtml(amount)}</div>
            <div class="storage-capacity">
                ${capacity > 0 ? `<span>${formatStorageLiters(capacity)} L Kapazität</span><div class="storage-progress"><i style="width:${percentage}%"></i></div><small>${Math.round(percentage)}%</small>` : '<span class="storage-no-capacity">—</span>'}
            </div>
        </div>`;
}

function renderStorageByStorage(storages) {
    if (!storages.length) return '<div class="empty-note">Keine passenden Lager gefunden.</div>';

    return `<div class="storage-grid">${storages.map(storage => {
        const contents = storage.contents.filter(content => Number(content.level || 0) > 0 || Number(content.objectCount || 0) > 0);
        const totalLiters = contents.reduce((sum, content) => sum + Number(content.level || 0), 0);
        const objectSummary = storage.objectCapacity > 0
            ? `${storage.objectCount.toLocaleString('de-DE')} / ${storage.objectCapacity.toLocaleString('de-DE')} Objekte`
            : '';
        const sourceBadge = storage.isMod
            ? `<span class="storage-badge storage-badge-mod">Mod${storage.modName ? ` · ${escapeHtml(storage.modName)}` : ''}</span>`
            : '<span class="storage-badge">GIANTS / Map</span>';

        return `
            <section class="storage-card">
                <header class="storage-card-header">
                    <div>
                        <div class="storage-kicker">${escapeHtml(storageTypeLabel(storage))}</div>
                        <h3>${escapeHtml(storage.name)}</h3>
                    </div>
                    ${sourceBadge}
                </header>
                <div class="storage-card-summary">
                    <span>${contents.length.toLocaleString('de-DE')} Produkte</span>
                    ${totalLiters > 0 ? `<span>${formatStorageLiters(totalLiters)} L Inhalt</span>` : ''}
                    ${objectSummary ? `<span>${escapeHtml(objectSummary)}</span>` : ''}
                </div>
                <div class="storage-content-list">
                    ${contents.length ? contents.map(storageContentRow).join('') : '<div class="storage-empty">Aktuell leer</div>'}
                </div>
            </section>`;
    }).join('')}</div>`;
}

function aggregateStorageProducts(storages) {
    const products = new Map();
    storages.forEach(storage => {
        storage.contents.forEach(content => {
            if (Number(content.level || 0) <= 0 && Number(content.objectCount || 0) <= 0) return;
            const key = String(content.fillType || content.title || 'UNKNOWN').toLocaleUpperCase('de-DE');
            if (!products.has(key)) {
                products.set(key, {
                    fillType: content.fillType,
                    title: content.title,
                    level: 0,
                    objectCount: 0,
                    sources: [],
                });
            }
            const product = products.get(key);
            product.level += Number(content.level || 0);
            product.objectCount += Number(content.objectCount || 0);
            product.sources.push({
                name: storage.name,
                level: Number(content.level || 0),
                objectCount: Number(content.objectCount || 0),
                objectKind: content.objectKind,
            });
        });
    });

    return Array.from(products.values()).sort((left, right) =>
        left.title.localeCompare(right.title, 'de', { sensitivity: 'base', numeric: true }));
}

function renderStorageByProduct(storages) {
    const products = aggregateStorageProducts(storages);
    if (!products.length) return '<div class="empty-note">Keine eingelagerten Produkte gefunden.</div>';

    return `<div class="storage-product-grid">${products.map(product => {
        const objectLabel = storageObjectLabel(product);
        const totalParts = [];
        if (product.level > 0) totalParts.push(`${formatStorageLiters(product.level)} L`);
        if (objectLabel) totalParts.push(objectLabel);

        return `
            <section class="storage-product-card">
                <header>
                    <div class="storage-kicker">${escapeHtml(product.fillType || '')}</div>
                    <h3>${escapeHtml(product.title || product.fillType)}</h3>
                    <div class="storage-product-total">${escapeHtml(totalParts.join(' · ') || '0 L')}</div>
                </header>
                <div class="storage-source-list">
                    ${product.sources.sort((a, b) => b.level - a.level || b.objectCount - a.objectCount).map(source => {
                        const parts = [];
                        if (source.level > 0) parts.push(`${formatStorageLiters(source.level)} L`);
                        const objectText = storageObjectLabel(source);
                        if (objectText) parts.push(objectText);
                        return `<div><span>${escapeHtml(source.name)}</span><strong>${escapeHtml(parts.join(' · ') || '0 L')}</strong></div>`;
                    }).join('')}
                </div>
            </section>`;
    }).join('')}</div>`;
}

function renderStorageData() {
    const container = document.getElementById('storageContainer');
    if (!container) return;
    const storages = filteredStorages();

    if (!storageCache.length) {
        container.innerHTML = '<div class="empty-note">Keine unterstützten Hof-Lager gefunden oder alle Lager sind noch leer.</div>';
        return;
    }

    container.innerHTML = storageViewMode === 'product'
        ? renderStorageByProduct(storages)
        : renderStorageByStorage(storages);
}

installStorageTab();
