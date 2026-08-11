async function openBackupPanel() {
    const res = await fetch('api.php?action=list_backups');
    const data = await res.json();
    if (data.error) { showToast(data.error, 'err'); return; }

    const rows = data.backups.length === 0
        ? '<div class="empty-note">Noch keine Backups vorhanden.</div>'
        : data.backups.map(b => `
            <div class="backup-row">
                <span class="ts">${escapeHtml(b.formatted)}</span>
                <span class="size">${(b.size / 1024).toFixed(0)} KB</span>
                <button class="small" onclick="restoreBackup('${b.file}')">Wiederherstellen</button>
                <button class="small danger" onclick="deleteBackupEntry('${b.file}')" title="Backup dauerhaft löschen">✕</button>
            </div>
        `).join('');

    document.getElementById('modalContainer').innerHTML = `
        <div class="modal-overlay" onclick="if(event.target===this) closeModal()">
            <div class="modal-box">
                <h2>Backups</h2>
                <div class="modal-sub">Automatisch vor jedem Speichern und jeder Wiederherstellung angelegt · max. 20 pro Spielstand</div>
                <div class="modal-list">${rows}</div>
                <div class="modal-close"><button onclick="closeModal()">Schließen</button></div>
            </div>
        </div>
    `;
}

function closeModal() {
    document.getElementById('modalContainer').innerHTML = '';
}

let pendingConfirmCallback = null;

function showConfirmModal(message, onConfirmCallback, confirmLabel) {
    pendingConfirmCallback = onConfirmCallback;
    document.getElementById('modalContainer').innerHTML = `
        <div class="modal-overlay" onclick="if(event.target===this) cancelConfirmModal()">
            <div class="modal-box" style="width:440px">
                <h2>Bestätigung</h2>
                <div class="modal-sub" style="margin-bottom:18px; font-family:var(--font-body); font-size:13px; color:var(--text)">${escapeHtml(message)}</div>
                <div class="modal-close" style="display:flex; justify-content:flex-end; gap:10px">
                    <button onclick="cancelConfirmModal()">Abbrechen</button>
                    <button class="danger" onclick="runConfirmModal()">${escapeHtml(confirmLabel || 'Bestätigen')}</button>
                </div>
            </div>
        </div>
    `;
}

function cancelConfirmModal() {
    pendingConfirmCallback = null;
    closeModal();
}

function runConfirmModal() {
    const cb = pendingConfirmCallback;
    pendingConfirmCallback = null;
    closeModal();
    if (cb) cb();
}

let pendingPromptCallback = null;

function showPromptModal(message, defaultValue, onConfirmCallback, confirmLabel, placeholder) {
    pendingPromptCallback = onConfirmCallback;
    document.getElementById('modalContainer').innerHTML = `
        <div class="modal-overlay" onclick="if(event.target===this) cancelPromptModal()">
            <div class="modal-box" style="width:440px">
                <h2>${escapeHtml(confirmLabel || 'Eingabe')}</h2>
                <div class="modal-sub" style="margin-bottom:10px; font-family:var(--font-body); font-size:13px; color:var(--text)">${escapeHtml(message)}</div>
                <input type="text" id="promptModalInput" value="${escapeAttr(defaultValue || '')}" placeholder="${escapeAttr(placeholder || '')}" style="margin-bottom:18px">
                <div class="modal-close" style="display:flex; justify-content:flex-end; gap:10px">
                    <button onclick="cancelPromptModal()">Abbrechen</button>
                    <button class="primary" onclick="runPromptModal()">Übernehmen</button>
                </div>
            </div>
        </div>
    `;
    const input = document.getElementById('promptModalInput');
    input.focus();
    input.select();
    input.addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); runPromptModal(); }
    });
}

function cancelPromptModal() {
    pendingPromptCallback = null;
    closeModal();
}

function runPromptModal() {
    const cb = pendingPromptCallback;
    const value = document.getElementById('promptModalInput').value.trim();
    pendingPromptCallback = null;
    closeModal();
    if (cb && value) cb(value);
}

function restoreBackup(file) {
    showConfirmModal(
        'Diesen Backup-Stand wiederherstellen? Der aktuelle Stand auf der Festplatte wird vorher automatisch gesichert, aber ungespeicherte Änderungen in diesem Fenster gehen verloren.',
        () => doRestoreBackup(file),
        'Wiederherstellen'
    );
}

async function doRestoreBackup(file) {
    const res = await fetch('api.php?action=restore_backup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file })
    });
    const data = await res.json();
    if (data.error) { showToast('Fehler: ' + data.error, 'err'); return; }

    closeModal();
    showToast('Backup wiederhergestellt', 'ok');
    loadMarkers();
}

function deleteBackupEntry(file) {
    showConfirmModal(
        'Dieses Backup dauerhaft löschen? Das kann nicht rückgängig gemacht werden.',
        () => doDeleteBackup(file),
        'Löschen'
    );
}

async function doDeleteBackup(file) {
    const res = await fetch('api.php?action=delete_backup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file })
    });
    const data = await res.json();
    if (data.error) { showToast('Fehler: ' + data.error, 'err'); return; }
    showToast('Backup gelöscht', 'ok');
    openBackupPanel(); // Liste neu laden
}

// -----------------------------------------------------------------
// Vollständige Spielstand-Backups (ZIP des kompletten Spielstand-Ordners)
// -----------------------------------------------------------------
async function openFullBackupPanel() {
    const res = await fetch('api.php?action=list_full_backups');
    const data = await res.json();
    if (data.error === 'no_savegame_selected') { showToast('Bitte zuerst einen Spielstand auswählen.', 'err'); return; }
    if (data.error) { showToast(data.error, 'err'); return; }
    renderFullBackupPanel(data.backups);
}

function renderFullBackupPanel(backups) {
    const rows = backups.length === 0
        ? '<div class="empty-note">Noch keine vollständigen Backups vorhanden.</div>'
        : backups.map(b => `
            <div class="backup-row">
                <span class="ts">${escapeHtml(b.formatted)}</span>
                <span class="size">${(b.size / 1024 / 1024).toFixed(1)} MB</span>
                <button class="small" onclick="window.location.href='api.php?action=download_full_backup&file=${encodeURIComponent(b.file)}'">Herunterladen</button>
                <button class="small danger" onclick="deleteFullBackupEntry('${b.file}')" title="Backup dauerhaft löschen">✕</button>
            </div>
        `).join('');

    document.getElementById('modalContainer').innerHTML = `
        <div class="modal-overlay" onclick="if(event.target===this) closeModal()">
            <div class="modal-box">
                <h2>Vollständige Spielstand-Backups</h2>
                <div class="modal-sub">Sichert den kompletten Spielstand-Ordner als ZIP (Felder, Gebäude, Fahrzeuge, Terrain-Caches usw.) – unabhängig von den automatischen AutoDrive-Backups oben. Kann je nach Spielstandgröße einige Sekunden dauern, währenddessen ist der Server kurz blockiert.</div>
                <div style="margin-bottom:14px">
                    <button class="primary" id="createFullBackupBtn" onclick="createFullBackup()">💾 Jetzt Backup erstellen</button>
                </div>
                <div class="modal-list" id="fullBackupList">${rows}</div>
                <div class="modal-close"><button onclick="closeModal()">Schließen</button></div>
            </div>
        </div>
    `;
}

async function createFullBackup() {
    const btn = document.getElementById('createFullBackupBtn');
    btn.disabled = true;
    btn.textContent = '⏳ Erstelle Backup …';
    const res = await fetch('api.php?action=create_full_backup', { method: 'POST' });
    const data = await res.json();
    if (data.error) {
        showToast(data.error, 'err');
        btn.disabled = false;
        btn.textContent = '💾 Jetzt Backup erstellen';
        return;
    }
    showToast(`Vollständiges Backup erstellt (${(data.size / 1024 / 1024).toFixed(1)} MB)`, 'ok');
    openFullBackupPanel(); // Liste aktualisieren
}

function deleteFullBackupEntry(file) {
    showConfirmModal(
        'Dieses vollständige Backup dauerhaft löschen? Das kann nicht rückgängig gemacht werden.',
        () => doDeleteFullBackup(file),
        'Löschen'
    );
}

async function doDeleteFullBackup(file) {
    const res = await fetch('api.php?action=delete_full_backup', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file })
    });
    const data = await res.json();
    if (data.error) { showToast('Fehler: ' + data.error, 'err'); return; }
    showToast('Backup gelöscht', 'ok');
    openFullBackupPanel();
}

function exportJson() {
    const payload = {
        exportedAt: new Date().toISOString(),
        groups: knownGroups,
        markers: markers.map(m => ({ id: m.id, name: m.name, group: m.group }))
    };
    const blob = new Blob([JSON.stringify(payload, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `autodrive-marker-export_${new Date().toISOString().slice(0, 10)}.json`;
    a.click();
    URL.revokeObjectURL(url);
    showToast('Export heruntergeladen', 'ok');
}

function importJson(input) {
    const file = input.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = () => {
        let data;
        try {
            data = JSON.parse(reader.result);
        } catch (e) {
            showToast('Datei ist kein gültiges JSON', 'err');
            input.value = '';
            return;
        }
        if (!Array.isArray(data.markers)) {
            showToast('Datei enthält keine Marker-Liste', 'err');
            input.value = '';
            return;
        }
        showConfirmModal(
            `${data.markers.length} Marker aus der Datei importieren? Das ersetzt die aktuelle Ansicht (nicht gespeichert, bis du auf Speichern klickst).`,
            () => {
                markers = data.markers.map(m => ({
                    key: '', id: String(m.id), name: m.name || 'Unbenannt', group: m.group || '', uid: 'm' + (uidCounter++)
                }));
                knownGroups = Array.isArray(data.groups) ? data.groups.slice() : [];
                markers.forEach(m => { if (m.group && !knownGroups.includes(m.group)) knownGroups.push(m.group); });
                knownGroups.sort((a, b) => a.localeCompare(b, 'de', {numeric: true}));
                selectedUids.clear();
                renderTable();
                renderBulkBar();
                showToast(`${markers.length} Marker importiert – noch nicht gespeichert`, 'ok');
                input.value = '';
            },
            'Importieren'
        );
    };
    reader.readAsText(file);
}

function toggleGroup(groupName) {
    if (collapsedGroups.has(groupName)) collapsedGroups.delete(groupName);
    else collapsedGroups.add(groupName);
    renderTable();
}

function collapseAll() {
    [...knownGroups, UNGROUPED].forEach(g => collapsedGroups.add(g));
    renderTable();
}

function expandAll() {
    collapsedGroups.clear();
    renderTable();
}

function toggleAllGroups() {
    const totalGroups = knownGroups.length + 1; // +1 für UNGROUPED
    if (collapsedGroups.size >= totalGroups) {
        expandAll();
    } else {
        collapseAll();
    }
}

function updateToggleAllGroupsLabel() {
    const btn = document.getElementById('toggleAllGroupsBtn');
    if (!btn) return;
    const totalGroups = knownGroups.length + 1;
    const allCollapsed = collapsedGroups.size >= totalGroups;
    btn.textContent = allCollapsed ? '⤢ Ausklappen' : '⤡ Einklappen';
}

function createGroup() {
    showPromptModal('Name der neuen Gruppe:', '', (name) => {
        if (!knownGroups.includes(name)) {
            knownGroups.push(name);
            knownGroups.sort((a, b) => a.localeCompare(b, 'de', {numeric: true}));
        }
        renderTable();
    }, 'Neue Gruppe', 'z. B. "9. Felder 61-70"');
}

function renderTable() {
    updateToggleAllGroupsLabel();
    const filter = document.getElementById('filterInput').value.toLowerCase();
    const container = document.getElementById('groupsContainer');
    container.innerHTML = '';

    const byGroup = {};
    markers.forEach((m, i) => {
        const g = m.group && m.group.trim() ? m.group.trim() : UNGROUPED;
        if (!byGroup[g]) byGroup[g] = [];
        byGroup[g].push(i);
    });

    const orderedGroups = [...knownGroups];
    Object.keys(byGroup).forEach(g => { if (g !== UNGROUPED && !orderedGroups.includes(g)) orderedGroups.push(g); });
    orderedGroups.push(UNGROUPED);

    let anyRendered = false;
    let flurCounter = 0;

    orderedGroups.forEach(groupName => {
        const indices = (byGroup[groupName] || []).slice()
            .sort((a, b) => markers[a].name.localeCompare(markers[b].name, 'de', {numeric: true}));

        const visibleIndices = indices.filter(i => {
            if (!filter) return true;
            return markers[i].name.toLowerCase().includes(filter) || groupName.toLowerCase().includes(filter);
        });

        if (filter && visibleIndices.length === 0) return;
        if (!filter && indices.length === 0 && groupName === UNGROUPED) return;

        anyRendered = true;
        flurCounter++;
        const flurNo = groupName === UNGROUPED ? '—' : 'Flur ' + String(flurCounter).padStart(2, '0');
        const isCollapsed = collapsedGroups.has(groupName);

        const section = document.createElement('div');
        section.className = 'parcel' + (isCollapsed ? ' collapsed' : '');

        const header = document.createElement('div');
        header.className = 'parcel-header';
        header.onclick = (ev) => {
            if (ev.target.tagName === 'INPUT' || ev.target.tagName === 'BUTTON') return;
            toggleGroup(groupName);
        };
        if (groupName === UNGROUPED) {
            header.innerHTML = `
                <span class="chevron">▾</span>
                <span class="flur-no">${flurNo}</span>
                <span style="flex:1; font-family:var(--font-display); font-weight:600; font-size:14.5px; color:var(--muted)">${UNGROUPED}</span>
                <span class="parcel-count">${indices.length}</span>
            `;
        } else {
            header.innerHTML = `
                <span class="chevron">▾</span>
                <span class="flur-no">${flurNo}</span>
                <input value="${escapeHtml(groupName)}" data-old-group="${escapeHtml(groupName)}" onchange="renameGroup(this)">
                <span class="parcel-count">${indices.length}</span>
                <button class="small danger" onclick="deleteGroup('${escapeAttr(groupName)}')" title="Gruppe auflösen (Marker bleiben, werden ungruppiert)">✕ Gruppe</button>
            `;
        }
        section.appendChild(header);

        const body = document.createElement('div');
        body.className = 'parcel-body';

        if (visibleIndices.length === 0) {
            body.innerHTML = '<div class="empty-note">Keine Marker in dieser Gruppe.</div>';
        } else {
            const table = document.createElement('table');
            table.innerHTML = `
                <thead><tr><th style="width:30px"></th><th style="width:100px">Wegpunkt</th><th>Name</th><th style="width:240px">Gruppe</th><th></th></tr></thead>
                <tbody>${visibleIndices.map(i => rowHtml(i)).join('')}</tbody>
            `;
            body.appendChild(table);
        }

        section.appendChild(body);
        container.appendChild(section);
    });

    if (!anyRendered) {
        container.innerHTML = '<div class="empty-note">Keine Treffer.</div>';
    }
}

function rowHtml(i) {
    const m = markers[i];
    const groupSelectOptions = [...knownGroups, UNGROUPED].map(g => {
        const val = g === UNGROUPED ? '' : g;
        const selected = (m.group || '') === val ? 'selected' : '';
        return `<option value="${escapeAttr(val)}" ${selected}>${escapeHtml(g)}</option>`;
    }).join('') + `<option value="__new__">➕ Neue Gruppe…</option>`;

    const checked = selectedUids.has(m.uid) ? 'checked' : '';

    return `
        <tr>
            <td class="check-col"><input type="checkbox" ${checked} onchange="toggleSelect('${m.uid}')"></td>
            <td><span class="id-tag">${m.id ? Math.trunc(parseFloat(m.id)) : ''}</span></td>
            <td><input value="${escapeHtml(m.name)}" oninput="markers[${i}].name = this.value"></td>
            <td class="group-col">
                <select onchange="onGroupSelect(${i}, this)">
                    ${groupSelectOptions}
                </select>
            </td>
            <td class="actions"><button class="danger small" onclick="removeRow(${i})">✕</button></td>
        </tr>
    `;
}

function toggleSelect(uid) {
    if (selectedUids.has(uid)) selectedUids.delete(uid);
    else selectedUids.add(uid);
    renderBulkBar();
}

function renderBulkBar() {
    const bar = document.getElementById('bulkBar');
    if (selectedUids.size === 0) { bar.style.display = 'none'; bar.innerHTML = ''; return; }

    const groupOptions = [...knownGroups].map(g =>
        `<option value="${escapeAttr(g)}">${escapeHtml(g)}</option>`
    ).join('') + `<option value="">${UNGROUPED}</option><option value="__new__">➕ Neue Gruppe…</option>`;

    bar.style.display = 'flex';
    bar.innerHTML = `
        <span class="bulk-count">${selectedUids.size} ausgewählt</span>
        <select id="bulkGroupSelect" style="width:220px">
            <option value="">— Gruppe zuweisen —</option>
            ${groupOptions}
        </select>
        <button onclick="applyBulkGroup()">Zuweisen</button>
        <button class="danger" onclick="bulkDelete()">✕ Ausgewählte löschen</button>
        <button onclick="clearSelection()">Auswahl aufheben</button>
    `;
}

function applyBulkGroup() {
    const select = document.getElementById('bulkGroupSelect');
    const value = select.value;
    if (value === '') return;
    if (value === '__new__') {
        showPromptModal('Name der neuen Gruppe:', '', (name) => {
            if (!knownGroups.includes(name)) {
                knownGroups.push(name);
                knownGroups.sort((a, b) => a.localeCompare(b, 'de', {numeric: true}));
            }
            markers.forEach(m => { if (selectedUids.has(m.uid)) m.group = name; });
            clearSelection();
        }, 'Neue Gruppe');
        return;
    }
    markers.forEach(m => { if (selectedUids.has(m.uid)) m.group = value; });
    clearSelection();
}

function bulkDelete() {
    showConfirmModal(`${selectedUids.size} Marker wirklich löschen?`, () => {
        markers = markers.filter(m => !selectedUids.has(m.uid));
        clearSelection();
    }, 'Löschen');
}

function clearSelection() {
    selectedUids.clear();
    renderBulkBar();
    renderTable();
}

function onGroupSelect(i, select) {
    if (select.value === '__new__') {
        showPromptModal('Name der neuen Gruppe:', '', (name) => {
            if (!knownGroups.includes(name)) {
                knownGroups.push(name);
                knownGroups.sort((a, b) => a.localeCompare(b, 'de', {numeric: true}));
            }
            markers[i].group = name;
            renderTable();
        }, 'Neue Gruppe');
        return;
    }
    markers[i].group = select.value;
    renderTable();
}

function renameGroup(input) {
    const oldName = input.dataset.oldGroup;
    const newName = input.value.trim();
    if (!newName || newName === oldName) { input.value = oldName; return; }
    markers.forEach(m => { if ((m.group || '').trim() === oldName) m.group = newName; });
    const idx = knownGroups.indexOf(oldName);
    if (idx !== -1) knownGroups[idx] = newName;
    knownGroups.sort((a, b) => a.localeCompare(b, 'de', {numeric: true}));
    renderTable();
}

function deleteGroup(groupName) {
    showConfirmModal(`Gruppe "${groupName}" auflösen? Die Marker bleiben erhalten, werden aber ungruppiert.`, () => {
        markers.forEach(m => { if ((m.group || '').trim() === groupName) m.group = ''; });
        knownGroups = knownGroups.filter(g => g !== groupName);
        renderTable();
    }, 'Auflösen');
}

function addRow() {
    showPromptModal('Wegpunkt-ID für den neuen Marker (im Spiel per AutoDrive-Debuganzeige ablesbar):', '', (id) => {
        markers.push({ key: '', id: id, name: 'Neuer Marker', group: '', uid: 'm' + (uidCounter++) });
        renderTable();
    }, 'Marker hinzufügen');
}

function removeRow(i) {
    selectedUids.delete(markers[i].uid);
    markers.splice(i, 1);
    renderTable();
    renderBulkBar();
}

async function saveMarkers() {
    setStatus('Speichere …', '');
    const res = await fetch('api.php?action=save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ markers })
    });
    const data = await res.json();
    if (data.error === 'no_savegame_selected') { showPickerScreen(); return; }
    if (data.error) {
        setStatus('Fehler: ' + data.error, 'err');
        showToast('Fehler beim Speichern: ' + data.error, 'err');
        return;
    }
    setStatus(`✓ Gespeichert (${data.count} Marker) · Backup: ${data.backup}`, 'ok');
    showToast(`Gespeichert – ${data.count} Marker`, 'ok');
    originalSnapshot = markersSnapshot();
}
