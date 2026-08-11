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
