(() => {
    'use strict';

    if (window.__hofDashboardLiveRefreshUxLoaded) return;
    window.__hofDashboardLiveRefreshUxLoaded = true;

    const loaderByTab = {
        home: 'loadFarmOverview',
        fields: 'loadFieldsData',
        vehicles: 'loadVehiclesData',
        animals: 'loadAnimalsData',
        storage: 'loadStorageData',
        production: 'loadProductionData',
        market: 'loadMarketData',
        missions: 'loadMissionsData',
    };

    const fillTypeLabels = {
        DIGESTATE: 'Gärrest',
        LIQUIDMANURE: 'Gülle',
        MANURE: 'Mist',
        STRAW: 'Stroh',
        DRYGRASS_WINDROW: 'Heu',
        GRASS_WINDROW: 'Gras',
        SILAGE: 'Silage',
        CHAFF: 'Häckselgut',
        DIESEL: 'Diesel',
        WATER: 'Wasser',
        MILK: 'Milch',
    };

    let lastSmoothTimestamp = null;

    function getActiveTab() {
        const activeNav = document.querySelector('.app-nav-item.is-active[data-app-tab]');
        if (activeNav?.dataset?.appTab) return activeNav.dataset.appTab;

        for (const tab of Object.keys(loaderByTab)) {
            const host = document.getElementById(`tab${tab.charAt(0).toUpperCase()}${tab.slice(1)}`);
            if (host && getComputedStyle(host).display !== 'none') return tab;
        }
        return null;
    }

    function isUserEditing() {
        const active = document.activeElement;
        if (!active) return false;
        const tag = String(active.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || active.isContentEditable === true;
    }

    function captureFocus() {
        const active = document.activeElement;
        if (!active || !active.id) return null;
        const snapshot = { id: active.id };
        if (typeof active.selectionStart === 'number') snapshot.selectionStart = active.selectionStart;
        if (typeof active.selectionEnd === 'number') snapshot.selectionEnd = active.selectionEnd;
        return snapshot;
    }

    function restoreFocus(snapshot) {
        if (!snapshot) return;
        const target = document.getElementById(snapshot.id);
        if (!target) return;
        try {
            target.focus({ preventScroll: true });
            if (typeof target.setSelectionRange === 'function'
                && typeof snapshot.selectionStart === 'number'
                && typeof snapshot.selectionEnd === 'number') {
                target.setSelectionRange(snapshot.selectionStart, snapshot.selectionEnd);
            }
        } catch (_) {}
    }

    function captureScrollableElements() {
        const snapshots = [];
        document.querySelectorAll('*').forEach(element => {
            if ((element.scrollTop || element.scrollLeft) && element !== document.body && element !== document.documentElement) {
                snapshots.push({ element, top: element.scrollTop, left: element.scrollLeft });
            }
        });
        return snapshots;
    }

    function restoreScrollableElements(snapshots) {
        snapshots.forEach(snapshot => {
            if (!snapshot.element?.isConnected) return;
            snapshot.element.scrollTop = snapshot.top;
            snapshot.element.scrollLeft = snapshot.left;
        });
    }

    function lockViewport() {
        const body = document.body;
        const html = document.documentElement;
        const x = window.scrollX;
        const y = window.scrollY;
        const snapshot = {
            x,
            y,
            bodyPosition: body.style.position,
            bodyTop: body.style.top,
            bodyLeft: body.style.left,
            bodyRight: body.style.right,
            bodyWidth: body.style.width,
            bodyOverflow: body.style.overflow,
            htmlOverflowAnchor: html.style.overflowAnchor,
            bodyOverflowAnchor: body.style.overflowAnchor,
        };

        html.style.overflowAnchor = 'none';
        body.style.overflowAnchor = 'none';
        body.style.position = 'fixed';
        body.style.top = `${-y}px`;
        body.style.left = `${-x}px`;
        body.style.right = '0';
        body.style.width = '100%';
        body.style.overflow = 'hidden';
        return snapshot;
    }

    function unlockViewport(snapshot) {
        if (!snapshot) return;
        const body = document.body;
        const html = document.documentElement;
        body.style.position = snapshot.bodyPosition;
        body.style.top = snapshot.bodyTop;
        body.style.left = snapshot.bodyLeft;
        body.style.right = snapshot.bodyRight;
        body.style.width = snapshot.bodyWidth;
        body.style.overflow = snapshot.bodyOverflow;
        html.style.overflowAnchor = snapshot.htmlOverflowAnchor;
        body.style.overflowAnchor = snapshot.bodyOverflowAnchor;
        window.scrollTo({ left: snapshot.x, top: snapshot.y, behavior: 'instant' });
    }

    async function refreshWithoutViewportJump(loader) {
        const focus = captureFocus();
        const nestedScroll = captureScrollableElements();
        const viewport = lockViewport();
        document.body.classList.add('hd-live-refreshing');
        window.__hdAutoRefresh = true;
        try {
            await loader();
        } finally {
            window.__hdAutoRefresh = false;
            await new Promise(resolve => requestAnimationFrame(resolve));
            restoreFocus(focus);
            restoreScrollableElements(nestedScroll);
            document.body.classList.remove('hd-live-refreshing');
            unlockViewport(viewport);
            requestAnimationFrame(() => {
                window.scrollTo({ left: viewport.x, top: viewport.y, behavior: 'instant' });
                restoreScrollableElements(nestedScroll);
            });
        }
    }

    function friendlyFillType(content) {
        const raw = String(content?.fillType || '').replace(/^OBJECT:/i, '').trim();
        const key = raw.toUpperCase();
        if (fillTypeLabels[key]) return fillTypeLabels[key];
        if (String(content?.objectKind || '').toLowerCase() === 'bale') return 'Ballen';
        if (String(content?.objectKind || '').toLowerCase() === 'pallet') return 'Palette';
        return String(content?.title || raw || 'Vorrat');
    }

    function inferObjectKind(content) {
        const existing = String(content?.objectKind || '').toLowerCase();
        if (existing === 'bale' || existing === 'pallet') return existing;
        const text = `${content?.title || ''} ${content?.fillType || ''}`.toLowerCase();
        if (text.includes('ballen') || text.includes('bale')) return 'bale';
        if (text.includes('palette') || text.includes('pallet')) return 'pallet';
        return existing || 'object';
    }

    function isVisibleStorage(storage) {
        if (!storage) return false;
        const contents = Array.isArray(storage.contents) ? storage.contents : [];
        const hasContent = contents.some(content => Number(content.level || 0) > 0 || Number(content.objectCount || 0) > 0);
        const hasCapacity = Number(storage.capacityLiters || 0) > 0 || Number(storage.objectCapacity || 0) > 0;
        return hasContent || hasCapacity;
    }

    if (typeof normalizeStorageRecord === 'function') {
        const originalNormalizeStorageRecord = normalizeStorageRecord;
        normalizeStorageRecord = function normalizeStorageRecordPolished(storage) {
            const record = originalNormalizeStorageRecord(storage);
            record.contents = record.contents.map(content => {
                const kind = inferObjectKind(content);
                const fillKey = String(content.fillType || '').toUpperCase();
                const title = fillTypeLabels[fillKey] || content.title;
                return { ...content, objectKind: kind, title };
            });
            return record;
        };
    }

    if (typeof storageObjectLabel === 'function') {
        storageObjectLabel = function storageObjectLabelPolished(content) {
            const count = Math.max(0, Number(content?.objectCount || 0));
            if (count <= 0) return '';
            const kind = inferObjectKind(content);
            const noun = kind === 'bale' ? 'Ballen'
                : kind === 'pallet' ? (count === 1 ? 'Palette' : 'Paletten')
                    : (count === 1 ? 'Objekt' : 'Objekte');
            return `${count.toLocaleString('de-DE')} ${noun}`;
        };
    }

    if (typeof storageTypeLabel === 'function') {
        const originalStorageTypeLabel = storageTypeLabel;
        storageTypeLabel = function storageTypeLabelPolished(storage) {
            const explicit = {
                husbandry: 'Tierstall',
                productionStorage: 'Produktionslager',
                digestate: 'Gärrestlager',
                bunkerSilo: 'Fahrsilo',
            }[storage?.type];
            return explicit || originalStorageTypeLabel(storage);
        };
    }

    if (typeof filteredStorages === 'function') {
        filteredStorages = function filteredStoragesPolished() {
            const query = String(document.getElementById('storageFilterInput')?.value || '').trim().toLocaleLowerCase('de-DE');
            const visible = storageCache.filter(isVisibleStorage);
            if (!query) return visible;
            return visible.filter(storage => {
                const haystack = [storage.name, storage.typeLabel, storage.modName]
                    .concat(storage.contents.flatMap(content => [content.title, friendlyFillType(content)]))
                    .join(' ')
                    .toLocaleLowerCase('de-DE');
                return haystack.includes(query);
            });
        };
    }

    if (typeof renderStorageStats === 'function') {
        renderStorageStats = function renderStorageStatsPolished() {
            const grid = document.getElementById('storageStatGrid');
            if (!grid) return;
            const visible = storageCache.filter(isVisibleStorage);
            const totalLiters = visible.reduce((sum, storage) =>
                sum + storage.contents.reduce((inner, content) => inner + Number(content.level || 0), 0), 0);
            const objectCount = visible.reduce((sum, storage) => sum + Number(storage.objectCount || 0), 0);
            const fillTypes = new Set();
            visible.forEach(storage => storage.contents.forEach(content => {
                if (Number(content.level || 0) > 0 || Number(content.objectCount || 0) > 0) {
                    fillTypes.add(friendlyFillType(content));
                }
            }));
            const modStorages = visible.filter(storage => storage.isMod).length;
            grid.innerHTML = `
                <div class="stat-card"><div class="stat-label">Lager</div><div class="stat-value">${visible.length.toLocaleString('de-DE')}</div><div class="stat-sub">davon ${modStorages.toLocaleString('de-DE')} aus Mods</div></div>
                <div class="stat-card"><div class="stat-label">Eingelagert</div><div class="stat-value">${formatStorageLiters(totalLiters)} L</div><div class="stat-sub">über alle mengenbasierten Lager</div></div>
                <div class="stat-card"><div class="stat-label">Produkte</div><div class="stat-value">${fillTypes.size.toLocaleString('de-DE')}</div><div class="stat-sub">unterschiedliche Vorratsarten</div></div>
                <div class="stat-card"><div class="stat-label">Ballen / Paletten</div><div class="stat-value">${objectCount.toLocaleString('de-DE')}</div><div class="stat-sub">aktuell eingelagert</div></div>`;
        };
    }

    if (typeof storageContentRow === 'function') {
        storageContentRow = function storageContentRowPolished(content) {
            const objectLabel = storageObjectLabel(content);
            const amountParts = [];
            if (Number(content.level || 0) > 0) amountParts.push(`${formatStorageLiters(content.level)} L`);
            if (objectLabel) amountParts.push(objectLabel);
            const amount = amountParts.length ? amountParts.join(' · ') : '0 L';
            const capacity = Number(content.capacity || 0);
            const percentage = capacity > 0 ? Math.max(0, Math.min(100, Number(content.percent || 0))) : null;
            const kind = inferObjectKind(content);
            const secondary = kind === 'bale' ? 'Ballen' : kind === 'pallet' ? 'Palette' : friendlyFillType(content);
            return `
                <div class="storage-content-row">
                    <div class="storage-product"><strong>${escapeHtml(content.title || friendlyFillType(content))}</strong><span>${escapeHtml(secondary)}</span></div>
                    <div class="storage-amount">${escapeHtml(amount)}</div>
                    <div class="storage-capacity">${capacity > 0 ? `<span>${formatStorageLiters(capacity)} L Kapazität</span><div class="storage-progress"><i style="width:${percentage}%"></i></div><small>${Math.round(percentage)}%</small>` : '<span class="storage-no-capacity">—</span>'}</div>
                </div>`;
        };
    }

    if (typeof aggregateStorageProducts === 'function') {
        aggregateStorageProducts = function aggregateStorageProductsPolished(storages) {
            const products = new Map();
            storages.forEach(storage => {
                storage.contents.forEach(content => {
                    if (Number(content.level || 0) <= 0 && Number(content.objectCount || 0) <= 0) return;
                    const kind = inferObjectKind(content);
                    const title = fillTypeLabels[String(content.fillType || '').toUpperCase()] || content.title || friendlyFillType(content);
                    const key = kind === 'bale' || kind === 'pallet'
                        ? `${kind}:${title}`.toLocaleUpperCase('de-DE')
                        : String(content.fillType || title || 'UNKNOWN').toLocaleUpperCase('de-DE');
                    if (!products.has(key)) {
                        products.set(key, { fillType: content.fillType, title, level: 0, objectCount: 0, objectKind: kind, sources: [] });
                    }
                    const product = products.get(key);
                    product.level += Number(content.level || 0);
                    product.objectCount += Number(content.objectCount || 0);
                    product.sources.push({ name: storage.name, level: Number(content.level || 0), objectCount: Number(content.objectCount || 0), objectKind: kind });
                });
            });
            return Array.from(products.values()).sort((a, b) => a.title.localeCompare(b.title, 'de', { sensitivity: 'base', numeric: true }));
        };
    }

    if (typeof loadStorageData === 'function') {
        const originalLoadStorageData = loadStorageData;
        loadStorageData = async function loadStorageDataSmooth() {
            if (!window.__hdAutoRefresh) return originalLoadStorageData();
            try {
                const response = await fetch(`api.php?action=live_data&t=${Date.now()}`, { cache: 'no-store' });
                const data = await response.json();
                if (!response.ok || data.error || data.status === 'error' || data.status === 'no_mod') return;
                if (!Array.isArray(data.storages)) return;
                storageCache = data.storages.map(normalizeStorageRecord);
                renderStorageStats();
                renderStorageData();
            } catch (_) {}
        };
    }

    window.autoRefreshActiveTab = async function autoRefreshActiveTabSmooth() {
        if (isUserEditing()) return;
        const tab = getActiveTab();
        const loaderName = tab ? loaderByTab[tab] : null;
        const loader = loaderName ? window[loaderName] : null;
        if (typeof loader !== 'function') return;
        await refreshWithoutViewportJump(() => loader());
    };

    window.pollLiveData = async function pollLiveDataSmooth() {
        try {
            const res = await fetch(`api.php?action=live_data&t=${Date.now()}`, { cache: 'no-store' });
            const data = await res.json();
            if (typeof window.updateLiveStatusBadge === 'function') window.updateLiveStatusBadge(data);

            if (data.status === 'ok' && data.timestamp && data.timestamp !== lastSmoothTimestamp) {
                lastSmoothTimestamp = data.timestamp;
                await window.autoRefreshActiveTab();
            }
        } catch (error) {
            if (typeof window.updateLiveStatusBadge === 'function') {
                window.updateLiveStatusBadge({ status: 'error', message: String(error) });
            }
        }
    };
})();
