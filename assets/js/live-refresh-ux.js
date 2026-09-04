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
        return tag === 'input'
            || tag === 'textarea'
            || tag === 'select'
            || active.isContentEditable === true;
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
        } catch (_) {
            // Focus restoration is best-effort only.
        }
    }

    async function refreshWithoutViewportJump(loader) {
        const x = window.scrollX;
        const y = window.scrollY;
        const focus = captureFocus();
        const body = document.body;
        const previousMinHeight = body.style.minHeight;
        const lockedHeight = Math.max(
            document.documentElement.scrollHeight,
            body.scrollHeight,
            window.innerHeight
        );

        // Existing loaders briefly replace parts of a view with a loading
        // placeholder. Keeping the document height locked prevents the browser
        // from clamping the scroll position while the live view is rebuilt.
        body.style.minHeight = `${lockedHeight}px`;
        body.classList.add('hd-live-refreshing');

        try {
            await loader();
        } finally {
            await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            window.scrollTo(x, y);
            restoreFocus(focus);
            body.style.minHeight = previousMinHeight;
            body.classList.remove('hd-live-refreshing');
            requestAnimationFrame(() => window.scrollTo(x, y));
        }
    }

    window.autoRefreshActiveTab = async function autoRefreshActiveTabSmooth() {
        // Do not rebuild the view while the user is typing/selecting. The next
        // live export refreshes it after that interaction is finished.
        if (isUserEditing()) return;

        const tab = getActiveTab();
        const loaderName = tab ? loaderByTab[tab] : null;
        const loader = loaderName ? window[loaderName] : null;
        if (typeof loader !== 'function') return;

        await refreshWithoutViewportJump(() => loader());
    };
})();
