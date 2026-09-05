(() => {
    'use strict';

    if (window.__hofDashboardLiveRefreshUxLoaded) return;
    window.__hofDashboardLiveRefreshUxLoaded = true;

    const loaderByTab = {
        home: 'loadFarmOverview',
        fields: 'loadFieldsData',
        vehicles: 'loadVehiclesData',
        animals: 'loadAnimalsData',
        production: 'loadProductionData',
        market: 'loadMarketData',
        missions: 'loadMissionsData',
    };

    const renderedKeys = new Map();
    let lastLiveTimestamp = null;

    function getActiveTab() {
        const activeNav = document.querySelector('.app-nav-item.is-active[data-app-tab]');
        if (activeNav?.dataset?.appTab) return activeNav.dataset.appTab;
        return typeof activeTab === 'string' ? activeTab : null;
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
        return {
            id: active.id,
            selectionStart: typeof active.selectionStart === 'number' ? active.selectionStart : null,
            selectionEnd: typeof active.selectionEnd === 'number' ? active.selectionEnd : null,
        };
    }

    function restoreFocus(snapshot) {
        if (!snapshot) return;
        const target = document.getElementById(snapshot.id);
        if (!target) return;
        try {
            target.focus({ preventScroll: true });
            if (typeof target.setSelectionRange === 'function'
                && snapshot.selectionStart !== null && snapshot.selectionEnd !== null) {
                target.setSelectionRange(snapshot.selectionStart, snapshot.selectionEnd);
            }
        } catch (_) {}
    }

    function captureNestedScroll() {
        const result = [];
        document.querySelectorAll('.app-workspace *').forEach(element => {
            if (element.scrollTop || element.scrollLeft) {
                result.push({ element, top: element.scrollTop, left: element.scrollLeft });
            }
        });
        return result;
    }

    function restoreNestedScroll(snapshots) {
        snapshots.forEach(snapshot => {
            if (!snapshot.element?.isConnected) return;
            snapshot.element.scrollTop = snapshot.top;
            snapshot.element.scrollLeft = snapshot.left;
        });
    }

    async function refreshWithoutViewportJump(loader) {
        const x = window.scrollX;
        const y = window.scrollY;
        const focus = captureFocus();
        const nested = captureNestedScroll();
        const body = document.body;
        const html = document.documentElement;
        const previousMinHeight = body.style.minHeight;
        const previousHtmlAnchor = html.style.overflowAnchor;
        const previousBodyAnchor = body.style.overflowAnchor;
        const stableHeight = Math.max(html.scrollHeight, body.scrollHeight, window.innerHeight);

        body.style.minHeight = `${stableHeight}px`;
        html.style.overflowAnchor = 'none';
        body.style.overflowAnchor = 'none';
        window.__hdAutoRefresh = true;
        try {
            await loader();
        } finally {
            window.__hdAutoRefresh = false;
            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            window.scrollTo(x, y);
            restoreNestedScroll(nested);
            restoreFocus(focus);
            body.style.minHeight = previousMinHeight;
            html.style.overflowAnchor = previousHtmlAnchor;
            body.style.overflowAnchor = previousBodyAnchor;
            requestAnimationFrame(() => {
                window.scrollTo(x, y);
                restoreNestedScroll(nested);
            });
        }
    }

    function revision(data, key) {
        const value = Number(data?.sectionRevisions?.[key]);
        return Number.isFinite(value) ? value : null;
    }

    function stripVehicleMapData(vehicle) {
        if (!vehicle || typeof vehicle !== 'object') return vehicle;
        const { mapX, mapZ, mapYaw, speedKph, isControlled, ...displayData } = vehicle;
        return displayData;
    }

    function stripPlayerPositions(farm) {
        if (!farm || typeof farm !== 'object') return farm;
        const { players, ...displayData } = farm;
        return displayData;
    }

    function viewKey(tab, data) {
        switch (tab) {
            case 'vehicles':
                // Positions- und Geschwindigkeitsänderungen gehören zur Live-Karte
                // und sollen nicht alle zwei Sekunden den kompletten Fuhrpark neu rendern.
                return JSON.stringify((Array.isArray(data?.vehicles) ? data.vehicles : []).map(stripVehicleMapData));
            case 'home':
                return JSON.stringify({
                    farm: stripPlayerPositions(data?.farm),
                    day: data?.currentDay,
                    fields: revision(data, 'fields') ?? data?.fields,
                    vehicles: (Array.isArray(data?.vehicles) ? data.vehicles : []).map(stripVehicleMapData),
                    animals: revision(data, 'animals') ?? data?.animals,
                    beehives: revision(data, 'beehives') ?? data?.beehives,
                    contracts: revision(data, 'contracts') ?? data?.contracts,
                });
            case 'fields':
                return String(revision(data, 'fields') ?? JSON.stringify(data?.fields || []));
            case 'animals':
                return `${revision(data, 'animals') ?? JSON.stringify(data?.animals || [])}:${revision(data, 'beehives') ?? JSON.stringify(data?.beehives || [])}`;
            case 'production':
                return String(revision(data, 'productions') ?? JSON.stringify(data?.productions || []));
            case 'market':
                return String(revision(data, 'market') ?? JSON.stringify(data?.market || []));
            case 'missions':
                return String(revision(data, 'contracts') ?? JSON.stringify(data?.contracts || []));
            default:
                return null;
        }
    }

    async function refreshActiveTabIfNeeded(data) {
        if (isUserEditing()) return;
        const tab = getActiveTab();
        const loaderName = tab ? loaderByTab[tab] : null;
        const loader = loaderName ? window[loaderName] : null;
        if (!tab || typeof loader !== 'function') return;

        const key = viewKey(tab, data);
        if (key === null) return;

        if (!renderedKeys.has(tab)) {
            renderedKeys.set(tab, key);
            return;
        }
        if (renderedKeys.get(tab) === key) return;

        renderedKeys.set(tab, key);
        await refreshWithoutViewportJump(() => loader());
    }

    window.pollLiveData = async function pollLiveDataSelective() {
        try {
            const response = await fetch(`api.php?action=live_data&t=${Date.now()}`, { cache: 'no-store' });
            const data = await response.json();
            window.updateLiveStatusBadge?.(data);

            if (data.status !== 'ok') return;
            if (!data.timestamp || data.timestamp === lastLiveTimestamp) return;
            lastLiveTimestamp = data.timestamp;

            // Eine einzige Live-Abfrage versorgt sowohl die UI als auch die Live-Karte.
            window.__hofLiveSnapshot = data;
            document.dispatchEvent(new CustomEvent('hofdashboard:live-data', { detail: data }));
            await refreshActiveTabIfNeeded(data);
        } catch (error) {
            window.updateLiveStatusBadge?.({ status: 'error', message: String(error) });
        }
    };
})();
