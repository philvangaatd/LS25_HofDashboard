function markersSnapshot() {
    return JSON.stringify(markers.map(m => ({ id: m.id, name: m.name, group: m.group })));
}

function isDirty() {
    return originalSnapshot !== null && markersSnapshot() !== originalSnapshot;
}

function confirmDiscardIfDirty(actionLabel, onProceed) {
    const courseDirty = typeof isCourseDirty === 'function' && isCourseDirty();
    if (!isDirty() && !courseDirty) { onProceed(); return; }
    const what = `${isDirty() ? 'Marker' : ''}${isDirty() && courseDirty ? ' + ' : ''}${courseDirty ? 'Route' : ''}`;
    showConfirmModal(
        `Du hast ungespeicherte Änderungen (${what}). ${actionLabel} und Änderungen verwerfen?`,
        onProceed,
        actionLabel
    );
}

let farmOverviewInterval = null;
const FARM_OVERVIEW_REFRESH_MS = 30000;
const START_SCREEN_VERSION = '5.4.0';
let startScreenInstalled = false;
let startModStatus = null;

function startFarmOverviewAutoRefresh() {
    stopFarmOverviewAutoRefresh();
    farmOverviewInterval = setInterval(() => {
        loadFarmOverview();
    }, FARM_OVERVIEW_REFRESH_MS);
}

function stopFarmOverviewAutoRefresh() {
    if (farmOverviewInterval) {
        clearInterval(farmOverviewInterval);
        farmOverviewInterval = null;
    }
}

function startIcon(name, extraClass = '') {
    const paths = {
        home: '<path d="M3 11.5 12 4l9 7.5"></path><path d="M5.5 10.5V20h13v-9.5"></path><path d="M9.5 20v-6h5v6"></path>',
        dashboard: '<path d="M4 20V12"></path><path d="M10 20V7"></path><path d="M16 20V4"></path><path d="M22 20H2"></path>',
        production: '<path d="M3 20V10l6 3v-4l6 4V6l6 4v10z"></path><path d="M7 17h2"></path><path d="M13 17h2"></path>',
        animals: '<path d="M5 9h11l3 3v5H6l-2-3z"></path><path d="M8 17v3"></path><path d="M16 17v3"></path><path d="M17 9l2-3 2 2-2 4"></path>',
        vehicles: '<circle cx="7" cy="17" r="3"></circle><circle cx="18" cy="17" r="3"></circle><path d="M4 14h9V7H9v7"></path><path d="M13 11h4l3 3"></path>',
        fields: '<path d="M4 20 20 4"></path><path d="M8 20 22 6"></path><path d="M2 16 16 2"></path>',
        storage: '<path d="M4 8 12 4l8 4-8 4z"></path><path d="M4 8v9l8 4 8-4V8"></path><path d="M12 12v9"></path>',
        backup: '<ellipse cx="12" cy="6" rx="7" ry="3"></ellipse><path d="M5 6v6c0 1.7 3.1 3 7 3s7-1.3 7-3V6"></path><path d="M5 12v6c0 1.7 3.1 3 7 3s7-1.3 7-3v-6"></path>',
        settings: '<circle cx="12" cy="12" r="3"></circle><path d="M19 12a7 7 0 0 0-.1-1l2-1.5-2-3.4-2.4 1a8 8 0 0 0-1.7-1L14.5 3h-5l-.4 3.1a8 8 0 0 0-1.7 1l-2.4-1-2 3.4L5 11a7 7 0 0 0 0 2l-2 1.5 2 3.4 2.4-1a8 8 0 0 0 1.7 1l.4 3.1h5l.4-3.1a8 8 0 0 0 1.7-1l2.4 1 2-3.4-2-1.5a7 7 0 0 0 .1-1z"></path>',
        system: '<rect x="3" y="4" width="18" height="13" rx="2"></rect><path d="M8 21h8"></path><path d="M12 17v4"></path>',
        map: '<path d="M4 6l5-2 6 2 5-2v14l-5 2-6-2-5 2z"></path><path d="M9 4v14"></path><path d="M15 6v14"></path>',
        user: '<circle cx="12" cy="8" r="3"></circle><path d="M5 21c.8-4 3.2-6 7-6s6.2 2 7 6"></path>',
        calendar: '<rect x="3" y="5" width="18" height="16" rx="2"></rect><path d="M7 3v4M17 3v4M3 10h18"></path>',
        puzzle: '<path d="M8 3h4v4a2 2 0 1 0 4 0V3h5v5h-4a2 2 0 1 0 0 4h4v9h-9v-4a2 2 0 1 0-4 0v4H3v-9h4a2 2 0 1 0 0-4H3V3z"></path>',
        database: '<ellipse cx="12" cy="5" rx="8" ry="3"></ellipse><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5"></path><path d="M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"></path>',
        search: '<circle cx="10" cy="10" r="6"></circle><path d="m15 15 6 6"></path>',
        folder: '<path d="M3 6h7l2 2h9v11H3z"></path>',
    };
    return `<svg class="${extraClass}" viewBox="0 0 24 24" aria-hidden="true">${paths[name] || paths.system}</svg>`;
}

function startBrandSeal() {
    return `<svg class="brand-seal" viewBox="0 0 40 44" aria-hidden="true">
        <path d="M20 2.5 34 8v12c0 10.5-7 18-14 21.5C13 38 6 30.5 6 20V8z" fill="none" stroke="#C9A227" stroke-width="1.7"></path>
        <path d="M20 11v21M20 15l-5-3M20 15l5-3M20 20l-5-3M20 20l5-3M20 25l-5-3M20 25l5-3M20 30l-4-2.5M20 30l4-2.5" fill="none" stroke="#7B9970" stroke-width="1.2" stroke-linecap="round"></path>
    </svg>`;
}

function startEscapeAttr(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
}

function installStartScreen() {
    if (startScreenInstalled) return;
    startScreenInstalled = true;

    if (!document.getElementById('startScreenStyles')) {
        const link = document.createElement('link');
        link.id = 'startScreenStyles';
        link.rel = 'stylesheet';
        link.href = `assets/css/start-screen.css?v=${START_SCREEN_VERSION}`;
        document.head.appendChild(link);
    }

    const picker = document.getElementById('pickerScreen');
    picker.className = 'start-shell';
    picker.innerHTML = `
        <aside class="start-sidebar">
            <div class="start-brand">
                ${startBrandSeal()}
                <span class="brand-label">LS25 · HOF-DASHBOARD</span>
            </div>
            <nav class="start-nav" aria-label="Startnavigation">
                <button class="start-nav-item is-active" id="startNavHome" data-start-view="home">${startIcon('home','start-nav-icon')}<span>Start</span></button>
                <button class="start-nav-item is-disabled" title="Nach Auswahl eines Spielstands verfügbar">${startIcon('dashboard','start-nav-icon')}<span>Dashboard</span></button>
                <button class="start-nav-item is-disabled" title="Nach Auswahl eines Spielstands verfügbar">${startIcon('production','start-nav-icon')}<span>Produktionen</span></button>
                <button class="start-nav-item is-disabled" title="Nach Auswahl eines Spielstands verfügbar">${startIcon('animals','start-nav-icon')}<span>Tiere</span></button>
                <button class="start-nav-item is-disabled" title="Nach Auswahl eines Spielstands verfügbar">${startIcon('vehicles','start-nav-icon')}<span>Fahrzeuge</span></button>
                <button class="start-nav-item is-disabled" title="Nach Auswahl eines Spielstands verfügbar">${startIcon('fields','start-nav-icon')}<span>Felder</span></button>
                <button class="start-nav-item is-disabled" title="Nach Auswahl eines Spielstands verfügbar">${startIcon('storage','start-nav-icon')}<span>Vorräte</span></button>
                <button class="start-nav-item is-disabled" title="Nach Auswahl eines Spielstands verfügbar">${startIcon('backup','start-nav-icon')}<span>Backups</span></button>
                <button class="start-nav-item is-disabled" title="Spielstandspezifische Einstellungen sind nach der Auswahl verfügbar">${startIcon('settings','start-nav-icon')}<span>Einstellungen</span></button>
                <button class="start-nav-item" id="startNavSystem" data-start-view="system">${startIcon('system','start-nav-icon')}<span>System</span><i class="start-system-dot" id="startSystemDot"></i></button>
            </nav>
            <div class="start-sidebar-footer">
                <strong>LS25 Hof-Dashboard</strong><br>
                <span id="startSidebarVersion">v${START_SCREEN_VERSION}</span>
            </div>
        </aside>
        <main class="start-main">
            <section class="start-content" id="startHomeView">
                <div class="start-eyebrow">Willkommen</div>
                <div class="start-title-row"><h1 class="start-title">Spielstand auswählen</h1></div>
                <div class="start-intro" id="pickerIntro">Suche Spielstände …</div>
                <div class="start-grid">
                    <div class="start-save-list" id="savegameList"></div>
                    <aside class="start-quick-card">
                        <div class="start-quick-head">
                            <span class="start-quick-bolt">ϟ</span>
                            <div><h2>Schnellzugriff</h2><p>Wichtige Informationen auf einen Blick.</p></div>
                        </div>
                        <div class="start-quick-list">
                            <div class="start-quick-row">${startIcon('puzzle')}<span>Mod erkannt</span><span class="start-quick-value" id="startQuickMod">Prüfe …</span></div>
                            <div class="start-quick-row">${startIcon('database')}<span>Dashboard-Version</span><span class="start-quick-value" id="startQuickVersion">v${START_SCREEN_VERSION}</span></div>
                            <div class="start-quick-row">${startIcon('search')}<span>Letzter Scan</span><span class="start-quick-value" id="startQuickScan">–</span></div>
                            <div class="start-quick-row">${startIcon('folder')}<span>Spielstände gefunden</span><span class="start-quick-value" id="startQuickCount">–</span></div>
                        </div>
                        <button class="start-system-button" id="startOpenSystem">${startIcon('system')}<span style="flex:1;text-align:left">System öffnen</span><span>›</span></button>
                    </aside>
                </div>
                <div class="start-footer-copy">
                    <div class="start-footer-script">Mehr aus deinem Hof.</div>
                    <div class="start-footer-line"></div>
                    <div class="start-footer-tags">Planen&nbsp;&nbsp;&nbsp; Verwalten&nbsp;&nbsp;&nbsp; Optimieren</div>
                </div>
            </section>

            <section class="start-system-view" id="startSystemView">
                <div class="start-eyebrow">System</div>
                <div class="start-title-row"><h1 class="start-title">System &amp; LS25-Integration</h1></div>
                <div class="start-system-toolbar">
                    <button id="startBackHome">← Spielstandauswahl</button>
                    <button onclick="loadStartSystemChecks()">↻ System prüfen</button>
                </div>
                <div class="start-system-grid">
                    <section class="start-system-card">
                        <div class="start-system-kicker">Systemcheck</div>
                        <h2>Lokale Umgebung</h2>
                        <div class="start-system-checks" id="pickerSystemCheckContainer"><div class="empty-note">Prüfung wird geladen …</div></div>
                    </section>
                    <section id="pickerModManagerContainer">
                        <div class="start-system-card" id="pickerModManagerFallback">
                            <div class="start-system-kicker">LS25-Integration</div>
                            <h2>Mod-Status wird geprüft …</h2>
                            <div class="empty-note">Die Windows-App ermittelt Installation und Version der Live-Mod.</div>
                        </div>
                    </section>
                </div>
            </section>
        </main>`;

    picker.querySelectorAll('[data-start-view]').forEach(button => {
        button.addEventListener('click', () => {
            if (button.dataset.startView === 'system') openStartSystem();
            else openStartHome();
        });
    });
    document.getElementById('startOpenSystem').addEventListener('click', openStartSystem);
    document.getElementById('startBackHome').addEventListener('click', openStartHome);

    loadStartManifestVersion();
    if (startModStatus) updateStartModStatus(startModStatus);
}

function setStartNavActive(view) {
    document.getElementById('startNavHome')?.classList.toggle('is-active', view === 'home');
    document.getElementById('startNavSystem')?.classList.toggle('is-active', view === 'system');
}

function openStartHome() {
    document.getElementById('startHomeView')?.classList.remove('is-hidden');
    document.getElementById('startSystemView')?.classList.remove('is-visible');
    setStartNavActive('home');
}

function openStartSystem() {
    document.getElementById('startHomeView')?.classList.add('is-hidden');
    document.getElementById('startSystemView')?.classList.add('is-visible');
    setStartNavActive('system');
    loadStartSystemChecks();
    if (typeof window.requestHofModStatus === 'function') window.requestHofModStatus();
}

async function loadStartManifestVersion() {
    try {
        const response = await fetch('app-manifest.json', { cache: 'no-store' });
        const manifest = await response.json();
        const version = String(manifest?.version || START_SCREEN_VERSION);
        const versionText = `v${version}`;
        const quick = document.getElementById('startQuickVersion');
        const footer = document.getElementById('startSidebarVersion');
        if (quick) quick.textContent = versionText;
        if (footer) footer.textContent = versionText;
    } catch (_) {
        // The embedded package always carries the manifest; the fallback keeps the start screen usable during development.
    }
}

function updateStartModStatus(status) {
    startModStatus = status || null;
    if (!status) return;

    const value = document.getElementById('startQuickMod');
    const dot = document.getElementById('startSystemDot');
    if (!value || !dot) return;

    value.classList.remove('is-ok', 'is-warn', 'is-error');
    dot.classList.remove('is-ok', 'is-warn', 'is-error');

    const installed = !!status.installedVersion;
    if (status.state === 'ready' || status.state === 'newer') {
        value.textContent = installed ? `Ja · v${status.installedVersion}` : 'Ja';
        value.classList.add('is-ok');
        dot.classList.add('is-ok');
    } else if (status.state === 'updateAvailable') {
        value.textContent = installed ? `Update · v${status.installedVersion}` : 'Update';
        value.classList.add('is-warn');
        dot.classList.add('is-warn');
    } else if (status.state === 'notInstalled') {
        value.textContent = 'Nein';
        value.classList.add('is-warn');
        dot.classList.add('is-warn');
    } else if (status.state === 'broken') {
        value.textContent = 'Reparatur nötig';
        value.classList.add('is-error');
        dot.classList.add('is-error');
    } else {
        value.textContent = installed ? `Ja · v${status.installedVersion}` : 'Nicht geprüft';
        dot.classList.add('is-warn');
    }
}
window.updateStartModStatus = updateStartModStatus;

async function loadStartSystemChecks() {
    const container = document.getElementById('pickerSystemCheckContainer');
    if (!container) return;
    container.innerHTML = '<div class="empty-note">Prüfe lokale Umgebung …</div>';
    try {
        const response = await fetch('api.php?action=system_check');
        const data = await response.json();
        if (!response.ok || data.error) throw new Error(data.error || 'Systemcheck fehlgeschlagen.');
        const checks = (Array.isArray(data.checks) ? data.checks : []).filter(check =>
            typeof isUserFacingSystemCheck === 'function' ? isUserFacingSystemCheck(check) : true
        );
        container.innerHTML = checks.length ? checks.map(check => `
            <div class="start-system-check-row status-${startEscapeAttr(check.status || 'info')}">
                <span class="start-system-check-state">${escapeHtml((typeof SYSCHECK_ICONS === 'object' && SYSCHECK_ICONS[check.status]) || String(check.status || 'INFO').toUpperCase())}</span>
                <span>${escapeHtml(check.label || 'Prüfung')}</span>
                <span class="start-system-check-detail">${escapeHtml(check.detail || '')}</span>
            </div>`).join('') : '<div class="empty-note">Keine Systemprüfungen gemeldet.</div>';
    } catch (error) {
        container.innerHTML = `<div class="empty-note">${escapeHtml(error.message || 'Systemcheck fehlgeschlagen.')}</div>`;
    }
}

async function init() {
    // Immer mit Spielstand-Auswahl starten (kein automatisches Weiterleiten).
    installStartScreen();
    showPickerScreen();
}

function showPickerScreen() {
    stopFarmOverviewAutoRefresh();
    stopLivePolling();
    installStartScreen();
    document.body.classList.add('picker-mode');
    document.getElementById('pickerScreen').style.display = 'grid';
    document.getElementById('mainScreen').style.display = 'none';
    openStartHome();
    loadSavegameList();
    if (typeof window.requestHofModStatus === 'function') window.requestHofModStatus();
}

function showMainScreen() {
    document.body.classList.remove('picker-mode');
    document.getElementById('pickerScreen').style.display = 'none';
    document.getElementById('mainScreen').style.display = 'block';
    // Live-Polling global starten sobald Hauptscreen sichtbar ist.
    startLivePolling();
}

function renderStartSavegame(sg) {
    const folder = String(sg.folder || 'savegame');
    const farmName = sg.farmName || sg.savegameName || folder;
    const mapTitle = sg.mapTitle || 'Unbekannte Karte';
    const manager = sg.manager || '';
    const saveDate = sg.saveDate || '–';
    return `
        <article class="start-save-card" data-save-folder="${startEscapeAttr(folder)}">
            <div class="start-farm-visual" aria-hidden="true">
                <span class="start-save-folder">${escapeHtml(folder)}</span>
                <span class="start-silo-shape"></span>
            </div>
            <div class="start-save-body">
                <h2 class="start-save-name">${escapeHtml(farmName)}</h2>
                <div class="start-save-meta">${escapeHtml(mapTitle)}${manager ? ' · ' + escapeHtml(manager) : ''} · zuletzt gespeichert ${escapeHtml(saveDate)}</div>
                <div class="start-save-pills">
                    <span class="start-pill">${startIcon('map')}<span>${escapeHtml(mapTitle)}</span></span>
                    ${manager ? `<span class="start-pill">${startIcon('user')}<span>${escapeHtml(manager)}</span></span>` : ''}
                    <span class="start-pill">${startIcon('calendar')}<span>${escapeHtml(saveDate)}</span></span>
                </div>
                <div class="start-save-actions">
                    ${sg.hasAutoDrive ? '' : '<span class="start-ad-badge">Kein AutoDrive</span>'}
                    <button class="start-open-button" type="button" data-open-savegame="${startEscapeAttr(folder)}">▶ &nbsp;Spielstand öffnen</button>
                </div>
            </div>
        </article>`;
}

async function loadSavegameList() {
    const introEl = document.getElementById('pickerIntro');
    const listEl = document.getElementById('savegameList');
    const countEl = document.getElementById('startQuickCount');
    const scanEl = document.getElementById('startQuickScan');
    introEl.textContent = 'Suche Spielstände …';
    listEl.innerHTML = '<div class="start-empty-card">Spielstände werden gesucht …</div>';
    if (countEl) countEl.textContent = '…';

    try {
        const res = await fetch('api.php?action=list_savegames');
        const data = await res.json();
        if (!res.ok || data.error) throw new Error(data.error || 'Spielstände konnten nicht gelesen werden.');

        const savegames = Array.isArray(data.savegames) ? data.savegames : [];
        if (countEl) countEl.textContent = String(savegames.length);
        if (scanEl) {
            scanEl.textContent = `Heute, ${new Intl.DateTimeFormat('de-DE', { hour: '2-digit', minute: '2-digit' }).format(new Date())}`;
        }

        if (savegames.length === 0) {
            introEl.textContent = `Keine Spielstände gefunden in ${data.baseDir || '(unbekannt)'}.`;
            listEl.innerHTML = '<div class="start-empty-card">Noch kein LS25-Spielstand gefunden. Du kannst den Systembereich trotzdem bereits verwenden.</div>';
            return;
        }

        introEl.textContent = `${savegames.length} Spielstand${savegames.length === 1 ? '' : 'stände'} gefunden in ${data.baseDir} – bitte auswählen.`;
        listEl.innerHTML = savegames.map(renderStartSavegame).join('');
        listEl.querySelectorAll('[data-open-savegame]').forEach(button => {
            button.addEventListener('click', event => {
                event.stopPropagation();
                selectSavegame(button.dataset.openSavegame);
            });
        });
        listEl.querySelectorAll('[data-save-folder]').forEach(card => {
            card.addEventListener('dblclick', () => selectSavegame(card.dataset.saveFolder));
        });
    } catch (error) {
        introEl.textContent = 'Spielstände konnten nicht geladen werden.';
        listEl.innerHTML = `<div class="start-empty-card">${escapeHtml(error.message || 'Unbekannter Fehler')}</div>`;
        if (countEl) countEl.textContent = '–';
    }
}

async function selectSavegame(folder) {
    if (!folder) return;
    const res = await fetch('api.php?action=select_savegame', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ folder })
    });
    const data = await res.json();
    if (data.error) { showToast(data.error, 'err'); return; }
    currentFolder = folder;
    hasAutoDrive = !!data.hasAutoDrive;
    await loadUserSettings(folder);
    points = new Map(); // Kartendaten für neuen Spielstand neu laden
    undoStack = [];
    orphanHighlightIds = null;
    terrainImg = null; // Hintergrundbild gehört zum vorherigen Spielstand, neu laden
    terrainImgTried = false;
    showMainScreen();
    applyAutoDriveTabVisibility();
    switchTab('home');
    loadFarmOverview();
    startFarmOverviewAutoRefresh();
    if (hasAutoDrive) {
        loadMarkers();
    } else {
        document.getElementById('mapInfo').textContent = 'Kein AutoDrive in diesem Spielstand aktiv – Marker & Karte nicht verfügbar.';
        showToast('Spielstand ohne AutoDrive – Marker/Karte-Tab ausgeblendet.', 'ok');
    }
}

function switchSavegame() {
    confirmDiscardIfDirty('Spielstand wechseln', showPickerScreen);
}

async function loadMarkers() {
    setStatus('Lade Marker …', '');
    const res = await fetch('api.php?action=markers');
    const data = await res.json();
    if (data.error === 'no_savegame_selected') { showPickerScreen(); return; }
    if (data.error === 'no_autodrive') {
        document.getElementById('mapInfo').textContent = 'Kein AutoDrive in diesem Spielstand aktiv – Marker & Karte nicht verfügbar.';
        return;
    }
    if (data.error) { setStatus(data.error, 'err'); return; }
    markers = data.markers;
    markers.forEach(m => { m.uid = 'm' + (uidCounter++); });
    selectedUids.clear();
    knownGroups = [...data.groups].sort((a, b) => a.localeCompare(b, 'de', {numeric: true}));
    collapsedGroups = new Set([...knownGroups, UNGROUPED]); // Gruppen beim Laden standardmäßig eingeklappt

    document.getElementById('mapInfo').textContent =
        `KARTE: ${data.mapName.toUpperCase()} · ${markers.length} MARKER · ${knownGroups.length} GRUPPEN`;
    originalSnapshot = markersSnapshot();
    renderTable();
    if (points.size > 0) populateMapJumpList();
    setStatus('', '');
}

function reloadOrDiscard() {
    if (!isDirty()) { loadMarkers(); return; }
    showConfirmModal('Es gibt ungespeicherte Änderungen. Trotzdem neu laden und verwerfen?', loadMarkers, 'Neu laden');
}

// =================================================================
// Kartenansicht
// =================================================================
