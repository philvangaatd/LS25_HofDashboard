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

async function init() {
    // Immer mit Spielstand-Auswahl starten (kein automatisches Weiterleiten)
    showPickerScreen();
}

function showPickerScreen() {
    stopFarmOverviewAutoRefresh();
    stopLivePolling();
    document.getElementById('pickerScreen').style.display = 'block';
    document.getElementById('mainScreen').style.display = 'none';
    loadSavegameList();
}

function showMainScreen() {
    document.getElementById('pickerScreen').style.display = 'none';
    document.getElementById('mainScreen').style.display = 'block';
    // Live-Polling global starten sobald Hauptscreen sichtbar ist
    startLivePolling();
}

async function loadSavegameList() {
    const introEl = document.getElementById('pickerIntro');
    const listEl = document.getElementById('savegameList');
    introEl.textContent = 'Suche Spielstände …';
    listEl.innerHTML = '';

    const res = await fetch('api.php?action=list_savegames');
    const data = await res.json();

    if (!data.savegames || data.savegames.length === 0) {
        introEl.textContent = `Keine Spielstände gefunden in: ${data.baseDir || '(unbekannt)'}`;
        return;
    }

    introEl.textContent = `${data.savegames.length} Spielstände gefunden in ${data.baseDir} — bitte auswählen:`;

    listEl.innerHTML = data.savegames.map(sg => `
        <div class="save-card" onclick="selectSavegame('${sg.folder}')">
            <span class="folder-tag">${sg.folder}</span>
            <div class="info">
                <div class="name">${escapeHtml(sg.farmName || sg.savegameName)}</div>
                <div class="meta">${escapeHtml(sg.mapTitle)}${sg.manager ? ' · ' + escapeHtml(sg.manager) : ''} · zuletzt gespeichert ${escapeHtml(sg.saveDate)}</div>
            </div>
            ${sg.hasAutoDrive ? '' : '<span class="badge-missing">Kein AutoDrive</span>'}
        </div>
    `).join('');
}

async function selectSavegame(folder) {
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
