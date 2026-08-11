let points = new Map();      // id(string) -> {x,y,z,out:Set<string>, flags:number}
let courseBounds = null;     // { minX, maxX, minZ, maxZ } – tatsächliche Wegpunkt-Ausdehnung
let guessedMapBounds = null; // { minX, maxX, minZ, maxZ, size } – geschätzte volle Kartengröße,
                              // für die Hintergrundbild-Platzierung verwendet (siehe guessMapWorldBounds)
let edgesList = [];          // [[idA, idB], ...] dedupliziert, für Rendering
let courseOriginalSnapshot = null;
let nextNewId = null;
let terrainImg = null;       // Image-Objekt, null falls kein Hintergrund verfügbar
let terrainImgTried = false;
let mapView = { centerX: 0, centerZ: 0, scale: 1 };
let mapDragging = false;     // Karte wird verschoben (Klick auf leeren Bereich)
let mapDraggingPoint = null; // id des gerade per Maus verschobenen Wegpunkts
let mapMouseDownPos = null;
let mapLastMouse = null;
let mapCanvasEl = null;
let mapCtx = null;
let editMode = 'view';       // 'view' | 'draw' | 'delete'
let chainSelection = null;   // id des zuletzt gesetzten Punkts beim Routenzeichnen
let disconnectSelection = null; // erster ausgewählter Punkt im Trennen-Modus
let lastClickedPointId = null;  // zuletzt angeklickter Punkt, für "Als Marker anlegen"
let dragOriginalPos = null;     // Position eines Punkts vor dem Ziehen, für Undo
let orphanHighlightIds = null;  // Set von IDs isolierter Stränge (nach Konnektivitätsprüfung)
let undoStack = [];          // Liste von Schritten, jeder Schritt = Array von Einzel-Operationen
const UNDO_STACK_LIMIT = 50;
const DRAG_THRESHOLD_PX = 4;
const POINT_HIT_RADIUS_PX = 10;
const SNAP_RADIUS_PX = 22; // großzügigerer Radius beim Setzen neuer Punkte, um an bestehende anzudocken

// FS25-Karten sind praktisch immer quadratisch, auf den Weltursprung (0,0) zentriert
// und in glatten Kantenlängen gehalten (z. B. 2048 m). Die Wegpunkt-Bounding-Box
// erreicht aber selten die tatsächlichen Kartenränder (Straßen enden vor dem Rand) –
// wird ein Hintergrundbild stattdessen auf die (zu kleine, oft nicht mittige) reine
// Wegpunkt-Box gepasst, verschiebt und verzerrt sich das Bild gegenüber den echten
// Straßen. Diese Schätzung rundet auf die nächste übliche Kantenlänge auf und nimmt
// eine Zentrierung auf (0,0) an – funktioniert bei den allermeisten Karten deutlich
// genauer als die reine Wegpunkt-Box. Die manuellen Ausrichtungsregler bleiben als
// Korrektur für Ausnahmefälle erhalten.
function guessMapWorldBounds(bounds) {
    const spanX = bounds.maxX - bounds.minX;
    const spanZ = bounds.maxZ - bounds.minZ;
    const maxSpan = Math.max(spanX, spanZ);
    const commonSizes = [512, 1024, 1536, 2048, 3072, 4096, 6144, 8192];
    let size = commonSizes.find(s => s >= maxSpan * 1.01);
    if (!size) size = Math.ceil(maxSpan / 256) * 256;
    const half = size / 2;
    return { minX: -half, maxX: half, minZ: -half, maxZ: half, size };
}

function updateMapSizeHint() {
    const el = document.getElementById('mapSizeHint');
    if (!el || !guessedMapBounds) return;
    const detail = guessedMapBounds.exact
        ? 'aus den Spieldateien ermittelt, exakt'
        : 'geschätzt anhand der Wegpunkt-Ausdehnung, nicht garantiert exakt';
    el.textContent = `Empfohlen für das Hintergrundbild: quadratisch (1:1), zeigt die komplette Karte von Rand zu Rand – Kartengröße ${guessedMapBounds.size}×${guessedMapBounds.size} m (${detail}). Bild sollte mindestens ${guessedMapBounds.size}×${guessedMapBounds.size} Pixel haben, sonst wirkt es beim Hineinzoomen unscharf.`;
}

// Versucht die exakte Kartengröße direkt aus der Karten-XML zu lesen (siehe
// map_size_info in api.php) und ersetzt damit die reine Schätzung, sobald verfügbar.
// Läuft im Hintergrund, ohne das Laden der Karte zu blockieren; bei Fehlschlag bleibt
// stillschweigend die Schätzung bestehen.
async function refineMapSizeFromGameFiles() {
    try {
        const res = await fetch('api.php?action=map_size_info');
        const data = await res.json();
        if (data.size && data.size.width > 0 && data.size.height > 0) {
            const halfW = data.size.width / 2;
            const halfH = data.size.height / 2;
            guessedMapBounds = { minX: -halfW, maxX: halfW, minZ: -halfH, maxZ: halfH, size: data.size.width, exact: true };
            updateMapSizeHint();
            mapRedraw();
        }
    } catch (e) { /* stille Verbesserung, kein Nutzer-Fehler bei Fehlschlag */ }
}

async function ensureMapLoaded() {
    if (!mapCanvasEl) {
        mapCanvasEl = document.getElementById('mapCanvas');
        mapCtx = mapCanvasEl.getContext('2d');
        mapCanvasEl.addEventListener('wheel', mapOnWheel, { passive: false });
        mapCanvasEl.addEventListener('mousedown', mapOnMouseDown);
        window.addEventListener('mousemove', mapOnMouseMove);
        window.addEventListener('mouseup', mapOnMouseUp);
        window.addEventListener('resize', () => { sizeMapCanvas(); mapRedraw(); });
    }

    if (points.size === 0) {
        document.getElementById('mapStatus').textContent = 'Lade Kursdaten …';
        const res = await fetch('api.php?action=course_data');
        const data = await res.json();
        if (data.error) {
            document.getElementById('mapStatus').textContent = 'Fehler: ' + data.error;
            return;
        }

        points = new Map();
        let minX = Infinity, maxX = -Infinity, minZ = Infinity, maxZ = -Infinity;
        let maxId = 0;
        for (let i = 0; i < data.ids.length; i++) {
            const id = data.ids[i];
            points.set(id, {
                x: data.x[i], y: data.y[i], z: data.z[i],
                out: new Set(data.out[i]),
                flags: parseInt(data.flags[i]) || 0,
            });
            if (data.x[i] < minX) minX = data.x[i];
            if (data.x[i] > maxX) maxX = data.x[i];
            if (data.z[i] < minZ) minZ = data.z[i];
            if (data.z[i] > maxZ) maxZ = data.z[i];
            const numId = parseInt(id);
            if (numId > maxId) maxId = numId;
        }
        courseBounds = { minX, maxX, minZ, maxZ };
        guessedMapBounds = guessMapWorldBounds(courseBounds);
        updateMapSizeHint();
        nextNewId = maxId + 1;
        undoStack = [];
        orphanHighlightIds = null;
        updateUndoButton();

        rebuildEdgesList();
        courseOriginalSnapshot = courseSnapshot();
        document.getElementById('mapStatus').textContent =
            `${points.size} Wegpunkte · ${edgesList.length} Verbindungen`;

        populateMapJumpList();
        loadTerrainImage();
        mapResetView();
        refineMapSizeFromGameFiles(); // im Hintergrund, verbessert die Schätzung nachträglich falls möglich
    }

    sizeMapCanvas();
    mapRedraw();
}

function courseSnapshot() {
    // Kompakte Prüfsumme für Dirty-Tracking (vollständige Serialisierung wäre bei
    // 30k Punkten unnötig teuer bei jeder Prüfung)
    const parts = [];
    for (const [id, p] of points) {
        parts.push(id + ':' + p.x.toFixed(2) + ',' + p.z.toFixed(2) + ',' + [...p.out].sort().join('|'));
    }
    return parts.length + '#' + parts.join(';');
}

function isCourseDirty() {
    return courseOriginalSnapshot !== null && courseSnapshot() !== courseOriginalSnapshot;
}

function rebuildEdgesList() {
    edgesList = [];
    for (const [id, p] of points) {
        for (const targetId of p.out) {
            if (!points.has(targetId)) continue;
            if (Number(targetId) > Number(id)) {
                edgesList.push([id, targetId]);
            } else if (Number(targetId) < Number(id) && !points.get(targetId).out.has(id)) {
                // einseitige Verbindung (nur in eine Richtung) trotzdem einmal zeichnen
                edgesList.push([targetId, id]);
            }
        }
    }
}

function setEditMode(mode) {
    if (mode !== 'draw') chainSelection = null;
    if (mode !== 'disconnect') disconnectSelection = null;
    editMode = mode;
    document.getElementById('modeBtnView').classList.toggle('active', mode === 'view');
    document.getElementById('modeBtnDraw').classList.toggle('active', mode === 'draw');
    document.getElementById('modeBtnDisconnect').classList.toggle('active', mode === 'disconnect');
    document.getElementById('modeBtnDelete').classList.toggle('active', mode === 'delete');
    document.getElementById('modeBtnDelete').classList.toggle('delete-active', mode === 'delete');
    if (mapCanvasEl) {
        mapCanvasEl.classList.toggle('mode-draw', mode === 'draw');
        mapCanvasEl.classList.toggle('mode-delete', mode === 'delete');
        mapCanvasEl.classList.toggle('mode-disconnect', mode === 'disconnect');
    }

    const hintBar = document.getElementById('mapHintBar');
    if (mode === 'view') {
        hintBar.textContent = 'Scrollen = Zoom · Ziehen = Karte verschieben';
    } else if (mode === 'draw') {
        hintBar.textContent = 'Klick = Punkt setzen/verbinden (fortlaufende Kette) · Klick auf bestehenden Punkt = andocken · vorhandenen Punkt ziehen = verschieben · Esc = Kette beenden';
    } else if (mode === 'disconnect') {
        hintBar.textContent = 'Zwei verbundene Punkte nacheinander anklicken = Verbindung zwischen ihnen entfernen (Punkte bleiben erhalten) · Esc = Auswahl zurücksetzen';
    } else if (mode === 'delete') {
        hintBar.textContent = 'Klick auf einen Wegpunkt = löschen (inkl. aller Verbindungen)';
    }
    if (mapCanvasEl) mapRedraw();
}

function populateMapJumpList() {
    const select = document.getElementById('mapJumpSelect');
    select.innerHTML = '<option value="">— Zu Marker springen —</option>' +
        markers.slice().sort((a, b) => a.name.localeCompare(b.name, 'de', {numeric: true}))
            .map(m => `<option value="${parseInt(m.id)}">${escapeHtml(m.name)}</option>`).join('');
}

function loadTerrainImage(force) {
    if ((terrainImgTried && !force) || !currentFolder) return;
    terrainImgTried = true;
    const img = new Image();
    img.onload = () => { terrainImg = img; updateTerrainButtons(); populateTerrainAlignInputs(); mapRedraw(); };
    img.onerror = () => { terrainImg = null; updateTerrainButtons(); }; // kein Hintergrundbild vorhanden – Karte funktioniert trotzdem
    img.src = `api.php?action=terrain_image&folder=${encodeURIComponent(currentFolder)}&t=${Date.now()}`; // Cache-Busting, da Dateiname nach Upload gleich bleibt
}

function updateTerrainButtons() {
    const removeBtn = document.getElementById('removeTerrainBtn');
    if (removeBtn) removeBtn.disabled = !terrainImg;
}

// -----------------------------------------------------------------
// Hintergrundbild hochladen/entfernen + manuelle Ausrichtung
// Ausrichtung (Versatz X/Z, Skalierung) wird vom lokalen PHP-Endpunkt dauerhaft
// je Spielstand gespeichert. Das ist unabhängig vom wechselnden Launcher-Port.
// -----------------------------------------------------------------
function getTerrainAlign() {
    return userSettings.terrainAlign || { offsetX: 0, offsetZ: 0, scale: 1 };
}

function saveTerrainAlign(align) {
    userSettings.terrainAlign = { ...align };
    persistUserSettings();
}

function populateTerrainAlignInputs() {
    const align = getTerrainAlign();
    const ox = document.getElementById('terrainOffsetX');
    const oz = document.getElementById('terrainOffsetZ');
    const sc = document.getElementById('terrainScale');
    if (ox) ox.value = align.offsetX;
    if (oz) oz.value = align.offsetZ;
    if (sc) sc.value = align.scale;
}

function updateTerrainAlignFromInputs() {
    const align = {
        offsetX: parseFloat(document.getElementById('terrainOffsetX').value) || 0,
        offsetZ: parseFloat(document.getElementById('terrainOffsetZ').value) || 0,
        scale: parseFloat(document.getElementById('terrainScale').value) || 1,
    };
    saveTerrainAlign(align);
    mapRedraw();
}

function resetTerrainAlign() {
    saveTerrainAlign({ offsetX: 0, offsetZ: 0, scale: 1 });
    populateTerrainAlignInputs();
    mapRedraw();
}

// -----------------------------------------------------------------
// Kartenbild automatisch aus den Moddateien laden – sucht direkt im
// "mods"-Ordner nach der Kartenmod, findet dort aber häufig nur eine DDS-Textur
// (Standard-Texturformat der Giants Engine), die dieses Tool nicht lesen kann.
// In dem Fall (oder bei Standardkarten ohne Mod-ZIP) bekommt man eine klare
// Fehlermeldung und kann wie gewohnt manuell ein Bild hochladen.
// -----------------------------------------------------------------
async function loadMapTerrainFromGame() {
    document.getElementById('mapStatus').textContent = 'Suche Kartenbild in den Spieldateien …';
    try {
        const res = await fetch('api.php?action=load_map_terrain', { method: 'POST' });
        const data = await res.json();
        if (!data.error) {
            loadTerrainImage(true);
            showToast(`Kartenbild aus Spieldateien geladen (${data.width}×${data.height}px)`, 'ok');
            document.getElementById('mapStatus').textContent = '';
            return;
        }
        // Keine PNG/JPEG-Variante gefunden – falls stattdessen eine DDS-Textur existiert,
        // versuchen wir die direkt im Browser zu dekodieren (PHP/GD kann DDS nicht lesen,
        // JavaScript mit einem eigenen DXT1-Dekoder aber sehr wohl).
        if (data.ddsAvailable) {
            await tryLoadMapDds();
        } else {
            showToast(data.error, 'err');
            document.getElementById('mapStatus').textContent = '';
        }
    } catch (e) {
        showToast('Laden fehlgeschlagen.', 'err');
        document.getElementById('mapStatus').textContent = '';
    }
}

// -----------------------------------------------------------------
// DDS-Kartentextur laden und client-seitig dekodieren. FS25-Kartenbilder liegen
// so gut wie immer als DXT1 (BC1) vor – verifiziert an der echten mapUS-Textur
// (4096×4096 DXT1). Andere Kompressionsformen (DXT3/5, BC7, DX10-Header) werden
// bewusst nicht geraten unterstützt, sondern klar als nicht unterstützt gemeldet.
// -----------------------------------------------------------------
async function tryLoadMapDds() {
    document.getElementById('mapStatus').textContent = 'Lade und dekodiere DDS-Kartentextur …';
    try {
        const res = await fetch('api.php?action=fetch_map_dds');
        if (!res.ok) {
            showToast('Keine DDS-Kartentextur gefunden. Bitte manuell ein Bild hochladen.', 'err');
            document.getElementById('mapStatus').textContent = '';
            return;
        }
        const buffer = await res.arrayBuffer();
        let canvas = decodeDdsToCanvas(buffer);
        if (!canvas) {
            showToast('Dieses DDS-Kompressionsformat wird (noch) nicht unterstützt (nur DXT1/BC1). Bitte manuell ein Bild hochladen.', 'err');
            document.getElementById('mapStatus').textContent = '';
            return;
        }

        // Vor dem Hochladen auf maximal 2048px Kantenlänge herunterskalieren – das Backend
        // würde ohnehin auf diese Größe herunterrechnen (save_terrain_image_from_path), und
        // eine 4096×4096-Kartentextur kann unkomprimiert als PNG leicht 10-15 MB groß sein,
        // was am recht niedrigen PHP-Standardlimit (upload_max_filesize oft nur 2 MB) scheitern
        // würde. Kleiner hochzuladen spart zudem unnötige Übertragungszeit.
        const maxDim = 2048;
        if (canvas.width > maxDim || canvas.height > maxDim) {
            const ratio = Math.min(maxDim / canvas.width, maxDim / canvas.height);
            const scaled = document.createElement('canvas');
            scaled.width = Math.round(canvas.width * ratio);
            scaled.height = Math.round(canvas.height * ratio);
            scaled.getContext('2d').drawImage(canvas, 0, 0, scaled.width, scaled.height);
            canvas = scaled;
        }

        // Als PNG ans Backend zum Speichern schicken – gleicher Ablauf wie beim manuellen
        // Upload, damit terrain_<folder>.png konsistent bleibt und beim nächsten Laden
        // einfach per <img> angezeigt wird, statt bei jedem Seitenaufruf neu zu dekodieren.
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
        const formData = new FormData();
        formData.append('image', blob, 'dds_decoded.png');
        const uploadRes = await fetch('api.php?action=upload_terrain', { method: 'POST', body: formData });
        const uploadData = await uploadRes.json();
        if (uploadData.error) {
            showToast(uploadData.error, 'err');
            document.getElementById('mapStatus').textContent = '';
            return;
        }
        loadTerrainImage(true);
        showToast(`DDS-Kartentextur dekodiert und gespeichert (${uploadData.width}×${uploadData.height}px)`, 'ok');
        document.getElementById('mapStatus').textContent = '';
    } catch (e) {
        showToast('DDS-Dekodierung fehlgeschlagen.', 'err');
        document.getElementById('mapStatus').textContent = '';
    }
}

function decodeDdsToCanvas(arrayBuffer) {
    const view = new DataView(arrayBuffer);
    if (view.byteLength < 128 || view.getUint32(0, true) !== 0x20534444) return null; // "DDS " Magic
    const height = view.getUint32(12, true);
    const width = view.getUint32(16, true);
    // FourCC steht im DDS_PIXELFORMAT-Block: Offset 4 (Magic) + 76 (Header bis dahin) + 4 (pfSize) = 84
    const fourCC = String.fromCharCode(
        view.getUint8(84), view.getUint8(85), view.getUint8(86), view.getUint8(87)
    );
    const dataOffset = 128; // Standard-Header ist 4 (Magic) + 124 Byte lang

    let rgba;
    if (fourCC === 'DXT1') {
        rgba = decodeDXT1(view, dataOffset, width, height);
    } else {
        return null; // DXT3/DXT5/BC7/DX10 usw. – (noch) nicht implementiert
    }

    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const ctx = canvas.getContext('2d');
    const imgData = ctx.createImageData(width, height);
    imgData.data.set(rgba);
    ctx.putImageData(imgData, 0, 0);
    return canvas;
}

function rgb565to888(c) {
    const r5 = (c >> 11) & 0x1F, g6 = (c >> 5) & 0x3F, b5 = c & 0x1F;
    return [(r5 << 3) | (r5 >> 2), (g6 << 2) | (g6 >> 4), (b5 << 3) | (b5 >> 2)];
}

function dxt1BlockColors(c0, c1) {
    const [r0, g0, b0] = rgb565to888(c0);
    const [r1, g1, b1] = rgb565to888(c1);
    const colors = [[r0, g0, b0, 255], [r1, g1, b1, 255]];
    if (c0 > c1) {
        colors.push([Math.round((2*r0+r1)/3), Math.round((2*g0+g1)/3), Math.round((2*b0+b1)/3), 255]);
        colors.push([Math.round((r0+2*r1)/3), Math.round((g0+2*g1)/3), Math.round((b0+2*b1)/3), 255]);
    } else {
        colors.push([Math.round((r0+r1)/2), Math.round((g0+g1)/2), Math.round((b0+b1)/2), 255]);
        colors.push([0, 0, 0, 0]); // transparent
    }
    return colors;
}

function decodeDXT1(view, offset, width, height) {
    const rgba = new Uint8ClampedArray(width * height * 4);
    const blocksWide = Math.ceil(width / 4);
    const blocksHigh = Math.ceil(height / 4);
    let ptr = offset;

    for (let by = 0; by < blocksHigh; by++) {
        for (let bx = 0; bx < blocksWide; bx++) {
            const c0 = view.getUint16(ptr, true);
            const c1 = view.getUint16(ptr + 2, true);
            const indices = view.getUint32(ptr + 4, true);
            ptr += 8;
            const colors = dxt1BlockColors(c0, c1);

            for (let py = 0; py < 4; py++) {
                for (let px = 0; px < 4; px++) {
                    const x = bx * 4 + px, y = by * 4 + py;
                    if (x >= width || y >= height) continue;
                    const idx = (indices >>> ((py * 4 + px) * 2)) & 0x3;
                    const [r, g, b, a] = colors[idx];
                    const p = (y * width + x) * 4;
                    rgba[p] = r; rgba[p+1] = g; rgba[p+2] = b; rgba[p+3] = a;
                }
            }
        }
    }
    return rgba;
}

function uploadTerrainImage(input) {
    const file = input.files[0];
    input.value = ''; // erlaubt erneutes Auswählen derselben Datei später
    if (!file) return;
    if (!currentFolder) { showToast('Kein Spielstand ausgewählt.', 'err'); return; }
    if (!file.type.startsWith('image/')) { showToast('Bitte eine Bilddatei auswählen.', 'err'); return; }
    if (file.size > 25 * 1024 * 1024) { showToast('Datei zu groß (maximal 25 MB).', 'err'); return; }

    const formData = new FormData();
    formData.append('image', file);

    document.getElementById('mapStatus').textContent = 'Hintergrundbild wird hochgeladen …';
    fetch('api.php?action=upload_terrain', { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.error) {
                showToast(data.error, 'err');
                document.getElementById('mapStatus').textContent = '';
                return;
            }
            loadTerrainImage(true);
            showToast(`Hintergrundbild gespeichert (${data.width}×${data.height}px)`, 'ok');
            document.getElementById('mapStatus').textContent = '';
        })
        .catch(() => {
            showToast('Upload fehlgeschlagen.', 'err');
            document.getElementById('mapStatus').textContent = '';
        });
}

function removeTerrainImage() {
    if (!terrainImg) return;
    showConfirmModal('Hintergrundbild für diesen Spielstand entfernen?', async () => {
        const res = await fetch('api.php?action=delete_terrain', { method: 'POST' });
        const data = await res.json();
        if (data.error) { showToast(data.error, 'err'); return; }
        terrainImg = null;
        terrainImgTried = true;
        updateTerrainButtons();
        mapRedraw();
        showToast('Hintergrundbild entfernt', 'ok');
    }, 'Entfernen');
}

function sizeMapCanvas() {
    if (!mapCanvasEl) return;
    const rect = mapCanvasEl.parentElement.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    mapCanvasEl.width = Math.max(1, Math.round(rect.width * dpr));
    mapCanvasEl.height = Math.max(1, Math.round(rect.height * dpr));
    mapCtx.setTransform(dpr, 0, 0, dpr, 0, 0);
}

function mapResetView() {
    if (!courseBounds || !mapCanvasEl) return;
    const rect = mapCanvasEl.parentElement.getBoundingClientRect();
    const worldW = Math.max(1, courseBounds.maxX - courseBounds.minX);
    const worldH = Math.max(1, courseBounds.maxZ - courseBounds.minZ);
    const padding = 0.92;
    const scale = Math.min(rect.width / worldW, rect.height / worldH) * padding;
    mapView.scale = scale;
    mapView.centerX = (courseBounds.minX + courseBounds.maxX) / 2;
    mapView.centerZ = (courseBounds.minZ + courseBounds.maxZ) / 2;
    mapRedraw();
}

function worldToCanvas(x, z) {
    const rect = mapCanvasEl.getBoundingClientRect();
    return [
        rect.width / 2 + (x - mapView.centerX) * mapView.scale,
        rect.height / 2 + (z - mapView.centerZ) * mapView.scale,
    ];
}

function canvasToWorld(cx, cy) {
    const rect = mapCanvasEl.getBoundingClientRect();
    return [
        mapView.centerX + (cx - rect.width / 2) / mapView.scale,
        mapView.centerZ + (cy - rect.height / 2) / mapView.scale,
    ];
}

function mapJumpToMarker(id) {
    if (!id || !points.has(id)) return;
    const p = points.get(id);
    mapView.centerX = p.x;
    mapView.centerZ = p.z;
    mapView.scale = Math.max(mapView.scale, 4);
    mapRedraw();
}

// Nächstgelegenen Wegpunkt zu einer Bildschirmposition finden (für Klick-Interaktion)
function findNearestPointAtScreen(clientX, clientY, maxPx) {
    const rect = mapCanvasEl.getBoundingClientRect();
    const cx = clientX - rect.left, cy = clientY - rect.top;
    let bestId = null, bestDist = maxPx;
    for (const [id, p] of points) {
        const [px, py] = worldToCanvas(p.x, p.z);
        if (px < cx - maxPx || px > cx + maxPx || py < cy - maxPx || py > cy + maxPx) continue;
        const d = Math.hypot(px - cx, py - cy);
        if (d < bestDist) { bestDist = d; bestId = id; }
    }
    return bestId;
}

function connectPoints(idA, idB) {
    if (idA === idB) return null;
    const a = points.get(idA), b = points.get(idB);
    if (!a || !b) return null;
    if (a.out.has(idB)) return null; // bereits verbunden
    a.out.add(idB);
    b.out.add(idA);
    return { type: 'connect', a: idA, b: idB };
}

function disconnectPoints(idA, idB) {
    if (idA === idB) return null;
    const a = points.get(idA), b = points.get(idB);
    if (!a || !b) return null;
    if (!a.out.has(idB) && !b.out.has(idA)) return null; // nicht verbunden
    a.out.delete(idB);
    b.out.delete(idA);
    return { type: 'disconnect', a: idA, b: idB };
}

function addPointAtScreen(clientX, clientY) {
    const rect = mapCanvasEl.getBoundingClientRect();
    const [wx, wz] = canvasToWorld(clientX - rect.left, clientY - rect.top);

    // Höhe (y) grob vom nächstgelegenen bestehenden Punkt übernehmen (Gelände ist
    // im Nahbereich meist ähnlich hoch)
    let nearestY = 0, bestDist = Infinity;
    for (const p of points.values()) {
        const d = (p.x - wx) ** 2 + (p.z - wz) ** 2;
        if (d < bestDist) { bestDist = d; nearestY = p.y; }
    }

    const id = String(nextNewId++);
    points.set(id, { x: wx, y: nearestY, z: wz, out: new Set(), flags: 0 });
    return id;
}

function deletePointById(id) {
    const p = points.get(id);
    if (!p) return null;
    const neighbors = [...p.out];
    const data = { x: p.x, y: p.y, z: p.z, flags: p.flags };
    for (const neighborId of neighbors) {
        const n = points.get(neighborId);
        if (n) n.out.delete(id);
    }
    points.delete(id);
    if (chainSelection === id) chainSelection = null;
    if (disconnectSelection === id) disconnectSelection = null;
    return { type: 'deletePoint', id, data, neighbors };
}

function isMarkerReferencing(id) {
    return markers.some(m => String(parseInt(m.id)) === id);
}

// -----------------------------------------------------------------
// Undo-System: jeder Bearbeitungsschritt (Klick/Zug) wird als Liste von
// Einzel-Operationen gespeichert; Strg+Z macht sie in umgekehrter Reihenfolge rückgängig.
// -----------------------------------------------------------------
function pushUndoStep(entries) {
    if (!entries || entries.length === 0) return;
    undoStack.push(entries);
    if (undoStack.length > UNDO_STACK_LIMIT) undoStack.shift();
    updateUndoButton();
}

function applyInverseEntry(entry) {
    if (entry.type === 'addPoint') {
        points.delete(entry.id);
    } else if (entry.type === 'deletePoint') {
        points.set(entry.id, { x: entry.data.x, y: entry.data.y, z: entry.data.z, out: new Set(), flags: entry.data.flags });
        const restored = points.get(entry.id);
        for (const nb of entry.neighbors) {
            const n = points.get(nb);
            if (n) { n.out.add(entry.id); restored.out.add(nb); }
        }
    } else if (entry.type === 'connect') {
        const a = points.get(entry.a), b = points.get(entry.b);
        if (a) a.out.delete(entry.b);
        if (b) b.out.delete(entry.a);
    } else if (entry.type === 'disconnect') {
        const a = points.get(entry.a), b = points.get(entry.b);
        if (a) a.out.add(entry.b);
        if (b) b.out.add(entry.a);
    } else if (entry.type === 'move') {
        const p = points.get(entry.id);
        if (p) { p.x = entry.oldX; p.z = entry.oldZ; }
    }
}

function undoCourseEdit() {
    if (undoStack.length === 0) return;
    const step = undoStack.pop();
    for (let i = step.length - 1; i >= 0; i--) applyInverseEntry(step[i]);
    chainSelection = null;
    disconnectSelection = null;
    orphanHighlightIds = null;
    rebuildEdgesList();
    updateUndoButton();
    mapRedraw();
    showToast('Änderung rückgängig gemacht', 'ok');
}

function updateUndoButton() {
    const btn = document.getElementById('undoBtn');
    if (btn) btn.disabled = undoStack.length === 0;
}

// -----------------------------------------------------------------
// Marker direkt auf der Karte anlegen
// -----------------------------------------------------------------
function markSelectedAsMarker() {
    const id = lastClickedPointId;
    if (id === null || !points.has(id)) {
        showToast('Erst einen Wegpunkt im Zeichen- oder Trennen-Modus anklicken.', 'err');
        return;
    }
    if (isMarkerReferencing(id)) {
        showToast('Für diesen Wegpunkt existiert bereits ein Marker.', 'err');
        return;
    }
    showPromptModal('Name für den neuen Marker:', 'Neuer Marker', (name) => {
        markers.push({ key: '', id: id, name: name.trim(), group: '', uid: 'm' + (uidCounter++) });
        populateMapJumpList();
        mapRedraw();
        showToast(`Marker "${name.trim()}" angelegt – im Marker-Tab speichern nicht vergessen`, 'ok');
    }, 'Neuer Marker');
}

// -----------------------------------------------------------------
// Getrennte Stränge erkennen (Konnektivitätsprüfung)
// -----------------------------------------------------------------
function checkConnectivity() {
    if (points.size === 0) { showToast('Keine Wegpunkte geladen.', 'err'); return; }

    const reverseAdj = new Map();
    for (const id of points.keys()) reverseAdj.set(id, new Set());
    for (const [id, p] of points) {
        for (const t of p.out) {
            if (reverseAdj.has(t)) reverseAdj.get(t).add(id);
        }
    }

    const visited = new Set();
    const components = [];
    for (const startId of points.keys()) {
        if (visited.has(startId)) continue;
        const compIds = [];
        const stack = [startId];
        visited.add(startId);
        while (stack.length) {
            const cur = stack.pop();
            compIds.push(cur);
            const p = points.get(cur);
            for (const nb of p.out) {
                if (!visited.has(nb) && points.has(nb)) { visited.add(nb); stack.push(nb); }
            }
            for (const nb of reverseAdj.get(cur)) {
                if (!visited.has(nb)) { visited.add(nb); stack.push(nb); }
            }
        }
        components.push(compIds);
    }

    components.sort((a, b) => b.length - a.length);

    if (components.length === 1) {
        orphanHighlightIds = null;
        showToast(`✓ Route vollständig verbunden (${points.size} Wegpunkte, 1 Strang)`, 'ok');
    } else {
        orphanHighlightIds = new Set(components.slice(1).flat());
        const sizes = components.map(c => c.length).join(', ');
        showToast(`⚠ ${components.length} getrennte Stränge gefunden (Größen: ${sizes}). Kleinere Stränge rot markiert.`, 'err');
    }
    mapRedraw();
}

function mapOnWheel(ev) {
    ev.preventDefault();
    const rect = mapCanvasEl.getBoundingClientRect();
    const cx = ev.clientX - rect.left;
    const cy = ev.clientY - rect.top;
    const [wx, wz] = canvasToWorld(cx, cy);

    const factor = ev.deltaY < 0 ? 1.15 : 1 / 1.15;
    mapView.scale = Math.min(50, Math.max(0.02, mapView.scale * factor));

    const [wx2, wz2] = canvasToWorld(cx, cy);
    mapView.centerX += wx - wx2;
    mapView.centerZ += wz - wz2;
    mapRedraw();
}

function mapOnMouseDown(ev) {
    mapMouseDownPos = { x: ev.clientX, y: ev.clientY };
    mapLastMouse = { x: ev.clientX, y: ev.clientY };

    // Treffertest nur in Zeichnen/Trennen/Löschen-Modus – im Ansehen-Modus gibt es keine
    // Punkt-Interaktion, und ein Treffertest würde dort das Verschieben der Karte
    // stören (die 30k Punkte liegen dicht am gesamten Straßennetz).
    if (editMode !== 'view') {
        const hitId = findNearestPointAtScreen(ev.clientX, ev.clientY, POINT_HIT_RADIUS_PX);
        if (hitId !== null) {
            mapDraggingPoint = hitId;
            const p = points.get(hitId);
            dragOriginalPos = p ? { x: p.x, z: p.z } : null;
            return;
        }
    }
    mapDragging = true;
    mapCanvasEl.classList.add('dragging');
}

function mapOnMouseMove(ev) {
    if (mapDraggingPoint !== null) {
        const moved = Math.hypot(ev.clientX - mapMouseDownPos.x, ev.clientY - mapMouseDownPos.y);
        // Tatsächliches Verschieben der Position nur im Zeichenmodus erlauben –
        // in Ansehen/Trennen/Löschen soll ein Ziehen keine unbeabsichtigte Positionsänderung auslösen.
        if (moved > DRAG_THRESHOLD_PX && editMode === 'draw') {
            const rect = mapCanvasEl.getBoundingClientRect();
            const [wx, wz] = canvasToWorld(ev.clientX - rect.left, ev.clientY - rect.top);
            const p = points.get(mapDraggingPoint);
            if (p) { p.x = wx; p.z = wz; mapRedraw(); }
        }
        return;
    }
    if (!mapDragging) return;
    const dx = ev.clientX - mapLastMouse.x;
    const dy = ev.clientY - mapLastMouse.y;
    mapLastMouse = { x: ev.clientX, y: ev.clientY };
    mapView.centerX -= dx / mapView.scale;
    mapView.centerZ -= dy / mapView.scale;
    mapRedraw();
}

function mapOnMouseUp(ev) {
    if (mapDraggingPoint !== null) {
        const moved = mapMouseDownPos ? Math.hypot(ev.clientX - mapMouseDownPos.x, ev.clientY - mapMouseDownPos.y) : 0;
        const draggedId = mapDraggingPoint;
        const originalPos = dragOriginalPos;
        mapDraggingPoint = null;
        dragOriginalPos = null;
        if (moved <= DRAG_THRESHOLD_PX) {
            // war ein Klick, kein Ziehen -> je nach Modus behandeln
            handlePointClick(draggedId);
        } else {
            if (editMode === 'draw' && originalPos) {
                const p = points.get(draggedId);
                if (p && (p.x !== originalPos.x || p.z !== originalPos.z)) {
                    pushUndoStep([{ type: 'move', id: draggedId, oldX: originalPos.x, oldZ: originalPos.z }]);
                }
            }
            orphanHighlightIds = null;
            rebuildEdgesList();
            mapRedraw();
        }
        return;
    }

    if (mapDragging) {
        mapDragging = false;
        mapCanvasEl.classList.remove('dragging');
        const moved = mapMouseDownPos ? Math.hypot(ev.clientX - mapMouseDownPos.x, ev.clientY - mapMouseDownPos.y) : 999;
        if (moved <= DRAG_THRESHOLD_PX && editMode === 'draw') {
            // Klick auf leeren Bereich im Zeichenmodus -> neuen Punkt setzen,
            // außer es liegt ein bestehender Punkt im großzügigeren Einrast-Radius (Snap)
            const entries = [];
            const snapId = findNearestPointAtScreen(ev.clientX, ev.clientY, SNAP_RADIUS_PX);
            let targetId;
            if (snapId !== null) {
                targetId = snapId;
            } else {
                targetId = addPointAtScreen(ev.clientX, ev.clientY);
                entries.push({ type: 'addPoint', id: targetId });
            }
            if (chainSelection !== null && chainSelection !== targetId) {
                const e = connectPoints(chainSelection, targetId);
                if (e) entries.push(e);
            }
            chainSelection = targetId;
            lastClickedPointId = targetId;
            orphanHighlightIds = null;
            if (entries.length) pushUndoStep(entries);
            rebuildEdgesList();
            mapRedraw();
        }
    }
}

function handlePointClick(id) {
    lastClickedPointId = id;

    if (editMode === 'draw') {
        const entries = [];
        if (chainSelection !== null && chainSelection !== id) {
            const e = connectPoints(chainSelection, id);
            if (e) entries.push(e);
        }
        chainSelection = id;
        orphanHighlightIds = null;
        if (entries.length) pushUndoStep(entries);
        rebuildEdgesList();
        mapRedraw();
    } else if (editMode === 'disconnect') {
        if (disconnectSelection === null || disconnectSelection === id) {
            disconnectSelection = disconnectSelection === id ? null : id;
            mapRedraw();
            return;
        }
        const e = disconnectPoints(disconnectSelection, id);
        if (e) {
            pushUndoStep([e]);
            showToast('Verbindung entfernt', 'ok');
        } else {
            showToast('Diese beiden Punkte sind nicht direkt verbunden.', 'err');
        }
        disconnectSelection = id; // erlaubt direktes Weiterklicken für mehrere Trennungen
        orphanHighlightIds = null;
        rebuildEdgesList();
        mapRedraw();
    } else if (editMode === 'delete') {
        if (isMarkerReferencing(id)) {
            showToast('Dieser Wegpunkt wird von einem Marker referenziert – erst im Marker-Tab umhängen.', 'err');
            return;
        }
        showConfirmModal(
            'Diesen einzelnen Wegpunkt löschen? Nur die direkten Verbindungen zu diesem einen Punkt werden entfernt – die restliche Route bleibt unangetastet.',
            () => {
                const e = deletePointById(id);
                if (e) pushUndoStep([e]);
                orphanHighlightIds = null;
                rebuildEdgesList();
                mapRedraw();
            },
            'Wegpunkt löschen'
        );
    }
}

document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') {
        if (editMode === 'draw') { chainSelection = null; mapRedraw(); }
        else if (editMode === 'disconnect') { disconnectSelection = null; mapRedraw(); }
    }
    if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 'z' && activeTab === 'map') {
        ev.preventDefault();
        undoCourseEdit();
    }
});

async function saveCourse() {
    document.getElementById('mapStatus').textContent = 'Speichere Route …';
    const payload = {
        points: [...points.entries()].map(([id, p]) => ({
            id, x: p.x, y: p.y, z: p.z, out: [...p.out], flags: p.flags,
        })),
    };
    const res = await fetch('api.php?action=save_course', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (data.error) {
        document.getElementById('mapStatus').textContent = 'Fehler: ' + data.error;
        showToast(data.error, 'err');
        return;
    }
    courseOriginalSnapshot = courseSnapshot();
    document.getElementById('mapStatus').textContent =
        `✓ Gespeichert (${data.count} Wegpunkte) · Backup: ${data.backup}`;
    showToast(`Route gespeichert – ${data.count} Wegpunkte`, 'ok');
}

function mapRedraw() {
    if (!mapCtx || !courseBounds || activeTab !== 'map') return;
    const rect = mapCanvasEl.getBoundingClientRect();
    mapCtx.clearRect(0, 0, rect.width, rect.height);

    // Hintergrundbild (falls vorhanden). Ausgerichtet an der GESCHÄTZTEN vollen Kartengröße
    // (guessedMapBounds), NICHT an der reinen Wegpunkt-Box – Straßen erreichen selten die
    // echten Kartenränder, ein Fit auf die Wegpunkt-Box allein hätte das Bild verschoben
    // und leicht verzerrt dargestellt. Kalibrierung steuert nur die Bild-Orientierung.
    if (terrainImg && guessedMapBounds) {
        const calib = document.getElementById('mapCalibration').value;
        // Manuelle Ausrichtung: Versatz (Weltkoordinaten) + Skalierung um den Bild-Mittelpunkt,
        // zusätzlich zur automatischen Einpassung in die geschätzte Kartengröße.
        const align = getTerrainAlign();
        const centerX = (guessedMapBounds.minX + guessedMapBounds.maxX) / 2 + align.offsetX;
        const centerZ = (guessedMapBounds.minZ + guessedMapBounds.maxZ) / 2 + align.offsetZ;
        const halfW = (guessedMapBounds.maxX - guessedMapBounds.minX) / 2 * align.scale;
        const halfH = (guessedMapBounds.maxZ - guessedMapBounds.minZ) / 2 * align.scale;
        const [x0, y0] = worldToCanvas(centerX - halfW, centerZ - halfH);
        const [x1, y1] = worldToCanvas(centerX + halfW, centerZ + halfH);
        const w = x1 - x0, h = y1 - y0;

        mapCtx.save();
        mapCtx.globalAlpha = 0.85;
        if (calib === 'normal') {
            mapCtx.drawImage(terrainImg, x0, y0, w, h);
        } else if (calib === 'flipz') {
            mapCtx.translate(x0, y0 + h); mapCtx.scale(1, -1);
            mapCtx.drawImage(terrainImg, 0, 0, w, h);
        } else if (calib === 'flipx') {
            mapCtx.translate(x0 + w, y0); mapCtx.scale(-1, 1);
            mapCtx.drawImage(terrainImg, 0, 0, w, h);
        } else if (calib === 'swap') {
            mapCtx.translate(x0, y0);
            mapCtx.rotate(Math.PI / 2);
            mapCtx.scale(1, -1);
            mapCtx.drawImage(terrainImg, 0, 0, h, w);
        }
        mapCtx.restore();
    }

    // Wegpunkt-Netz (Kanten). Kräftiges Cyan statt des bisherigen gedämpften Grüns –
    // das ging im olivfarbenen Kartenhintergrund praktisch unter. Cyan hebt sich von den
    // warmen Erd-/Grüntönen der Karte klar ab, ohne mit dem Gold der Marker oder dem Rot
    // der isolierten Stränge zu kollidieren.
    {
        const cx0 = rect.width / 2, cy0 = rect.height / 2;
        mapCtx.strokeStyle = 'rgba(0, 217, 255, 0.65)';
        mapCtx.lineWidth = 1.2;
        mapCtx.beginPath();
        for (const [idA, idB] of edgesList) {
            const a = points.get(idA), b = points.get(idB);
            if (!a || !b) continue;
            const ax = cx0 + (a.x - mapView.centerX) * mapView.scale;
            const ay = cy0 + (a.z - mapView.centerZ) * mapView.scale;
            const bx = cx0 + (b.x - mapView.centerX) * mapView.scale;
            const by = cy0 + (b.z - mapView.centerZ) * mapView.scale;
            if ((ax < 0 || ax > rect.width || ay < 0 || ay > rect.height) &&
                (bx < 0 || bx > rect.width || by < 0 || by > rect.height) &&
                ((ax < 0 && bx < 0) || (ax > rect.width && bx > rect.width) ||
                 (ay < 0 && by < 0) || (ay > rect.height && by > rect.height))) continue;
            mapCtx.moveTo(ax, ay);
            mapCtx.lineTo(bx, by);
        }
        mapCtx.stroke();
    }

    // Isolierte Stränge (nach Konnektivitätsprüfung) rot markieren – immer sichtbar,
    // auch im Ansehen-Modus, damit die Warnung nicht übersehen wird.
    if (orphanHighlightIds && orphanHighlightIds.size > 0) {
        const cx0 = rect.width / 2, cy0 = rect.height / 2;
        mapCtx.fillStyle = '#A85539';
        mapCtx.beginPath();
        for (const id of orphanHighlightIds) {
            const p = points.get(id);
            if (!p) continue;
            const cx = cx0 + (p.x - mapView.centerX) * mapView.scale;
            const cy = cy0 + (p.z - mapView.centerZ) * mapView.scale;
            if (cx < -5 || cx > rect.width + 5 || cy < -5 || cy > rect.height + 5) continue;
            mapCtx.rect(cx - 2.5, cy - 2.5, 5, 5);
        }
        mapCtx.fill();
    }

    // Einzelne Wegpunkte nur im Bearbeitungsmodus als Klickziele anzeigen.
    // Performance-kritisch bei 30k+ Punkten: rect einmal cachen (kein getBoundingClientRect
    // pro Punkt) und ein einziger Path mit fill() statt vieler Einzelaufrufe (deutlich schneller).
    if (editMode !== 'view') {
        const cx0 = rect.width / 2, cy0 = rect.height / 2;
        mapCtx.fillStyle = 'rgba(169, 164, 140, 0.55)';
        mapCtx.beginPath();
        for (const [id, p] of points) {
            const cx = cx0 + (p.x - mapView.centerX) * mapView.scale;
            const cy = cy0 + (p.z - mapView.centerZ) * mapView.scale;
            if (cx < -4 || cx > rect.width + 4 || cy < -4 || cy > rect.height + 4) continue;
            mapCtx.rect(cx - 1.5, cy - 1.5, 3, 3);
        }
        mapCtx.fill();

        // Auswahl-Highlight: im Zeichenmodus der Kettenpunkt, im Trennen-Modus der erste gewählte Punkt
        const highlightId = editMode === 'draw' ? chainSelection : (editMode === 'disconnect' ? disconnectSelection : null);
        if (highlightId !== null && points.has(highlightId)) {
            const p = points.get(highlightId);
            const [cx, cy] = worldToCanvas(p.x, p.z);
            mapCtx.beginPath();
            mapCtx.arc(cx, cy, 6, 0, Math.PI * 2);
            mapCtx.strokeStyle = editMode === 'disconnect' ? '#A85539' : '#C9A227';
            mapCtx.lineWidth = 2;
            mapCtx.stroke();
        }
    }

    // Marker als hervorgehobene Punkte + Label (Label nur bei ausreichendem Zoom)
    const showLabels = mapView.scale > 1.2;
    for (const m of markers) {
        const id = String(parseInt(m.id));
        const p = points.get(id);
        if (!p) continue;
        const [cx, cy] = worldToCanvas(p.x, p.z);
        if (cx < -20 || cx > rect.width + 20 || cy < -20 || cy > rect.height + 20) continue;

        mapCtx.beginPath();
        mapCtx.arc(cx, cy, 4.5, 0, Math.PI * 2);
        mapCtx.fillStyle = '#C9A227';
        mapCtx.fill();
        mapCtx.strokeStyle = '#14160E';
        mapCtx.lineWidth = 1.2;
        mapCtx.stroke();

        if (showLabels) {
            mapCtx.font = '11px "IBM Plex Mono", monospace';
            mapCtx.fillStyle = '#ECE7D8';
            mapCtx.fillText(m.name, cx + 7, cy - 6);
        }
    }
}
