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

const TAB_CONFIG = {
    home: { panel: 'tabHome', button: 'tabBtnHome', loader: 'loadFarmOverview' },
    fields: { panel: 'tabFields', button: 'tabBtnFields', loader: 'loadFieldsData' },
    vehicles: { panel: 'tabVehicles', button: 'tabBtnVehicles', loader: 'loadVehiclesData' },
    animals: { panel: 'tabAnimals', button: 'tabBtnAnimals', loader: 'loadAnimalsData' },
    production: { panel: 'tabProduction', button: 'tabBtnProduction', loader: 'loadProductionData' },
    market: { panel: 'tabMarket', button: 'tabBtnMarket', loader: 'loadMarketData' },
    missions: { panel: 'tabMissions', button: 'tabBtnMissions', loader: 'loadMissionsData' },
    markers: { panel: 'tabMarkers', button: 'tabBtnMarkers', requiresAutoDrive: true },
    map: { panel: 'tabMap', button: 'tabBtnMap', loader: 'ensureMapLoaded', requiresAutoDrive: true },
    system: { panel: 'tabSystem', button: 'tabBtnSystem', loader: 'loadSystemCheck' },
};

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
        if (currentFolder === folder) showToast('Benutzereinstellungen konnten nicht gespeichert werden.', 'err');
    });

    return userSettingsSaveQueue;
}

function applyAutoDriveTabVisibility() {
    for (const tab of ['markers', 'map']) {
        const button = document.getElementById(TAB_CONFIG[tab].button);
        if (button) button.style.display = hasAutoDrive ? '' : 'none';
    }
    if (!hasAutoDrive && TAB_CONFIG[activeTab]?.requiresAutoDrive) switchTab('home');
}

function switchTab(tab) {
    if (!TAB_CONFIG[tab]) return;
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
    const target = TAB_CONFIG[tab];
    if (!target || (target.requiresAutoDrive && !hasAutoDrive)) return;

    activeTab = tab;
    for (const [name, config] of Object.entries(TAB_CONFIG)) {
        const panel = document.getElementById(config.panel);
        const button = document.getElementById(config.button);
        if (panel) panel.style.display = name === tab ? 'block' : 'none';
        if (button) button.classList.toggle('active', name === tab);
    }

    if (target.loader && typeof window[target.loader] === 'function') {
        window[target.loader]();
    }
}

const SEASON_LABELS = { SPRING: 'Frühling', SUMMER: 'Sommer', AUTUMN: 'Herbst', WINTER: 'Winter' };
