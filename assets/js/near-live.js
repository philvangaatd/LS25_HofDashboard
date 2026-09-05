(() => {
    'use strict';

    if (window.__hofDashboardNearLiveLoaded) return;
    window.__hofDashboardNearLiveLoaded = true;

    const FOREGROUND_POLL_MS = 1000;
    const BACKGROUND_POLL_MS = 10000;

    const originalStopLivePolling = typeof window.stopLivePolling === 'function'
        ? window.stopLivePolling.bind(window)
        : null;

    let timer = null;
    let running = false;
    let enabled = false;

    function intervalForState() {
        return document.hidden ? BACKGROUND_POLL_MS : FOREGROUND_POLL_MS;
    }

    function clearTimer() {
        if (timer === null) return;
        clearTimeout(timer);
        timer = null;
    }

    function schedule(delay = intervalForState()) {
        clearTimer();
        if (!enabled) return;
        timer = setTimeout(tick, delay);
    }

    async function tick() {
        timer = null;
        if (!enabled || running || typeof window.pollLiveData !== 'function') {
            schedule();
            return;
        }

        running = true;
        try {
            await window.pollLiveData();
        } catch (_) {
            // pollLiveData pflegt den sichtbaren Verbindungsstatus selbst.
        } finally {
            running = false;
            schedule();
        }
    }

    function disableLegacyOverviewRefresh() {
        if (typeof window.stopFarmOverviewAutoRefresh === 'function') {
            window.stopFarmOverviewAutoRefresh();
        }
        // Der alte 30-Sekunden-Timer ist seit Near-Live redundant. Spätere Aufrufe
        // aus älterem Initialisierungscode werden absichtlich zu einem No-op.
        if (typeof window.startFarmOverviewAutoRefresh === 'function') {
            window.startFarmOverviewAutoRefresh = () => {};
        }
    }

    function startNearLivePolling() {
        if (enabled) return;
        enabled = true;
        originalStopLivePolling?.();
        disableLegacyOverviewRefresh();
        schedule(0);
    }

    function stopNearLivePolling() {
        enabled = false;
        clearTimer();
        originalStopLivePolling?.();
        disableLegacyOverviewRefresh();
    }

    window.startLivePolling = startNearLivePolling;
    window.stopLivePolling = stopNearLivePolling;

    function syncWithAppState() {
        if (document.body.classList.contains('dashboard-mode')) startNearLivePolling();
        else stopNearLivePolling();
    }

    document.addEventListener('visibilitychange', () => {
        if (enabled) schedule(0);
    });

    new MutationObserver(syncWithAppState).observe(document.body, {
        attributes: true,
        attributeFilter: ['class'],
    });

    syncWithAppState();
})();
