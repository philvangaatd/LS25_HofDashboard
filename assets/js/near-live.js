(() => {
    'use strict';

    if (window.__hofDashboardNearLiveLoaded) return;
    window.__hofDashboardNearLiveLoaded = true;

    const FOREGROUND_POLL_MS = 1000;
    const BACKGROUND_POLL_MS = 5000;

    const originalStopLivePolling = typeof window.stopLivePolling === 'function'
        ? window.stopLivePolling.bind(window)
        : null;

    let timer = null;
    let requestInFlight = false;

    async function tick() {
        if (requestInFlight || typeof window.pollLiveData !== 'function') return;

        requestInFlight = true;
        try {
            await window.pollLiveData();
        } catch (_) {
            // pollLiveData handles the visible connection state itself.
        } finally {
            requestInFlight = false;
        }
    }

    function clearTimer() {
        if (timer === null) return;
        clearInterval(timer);
        timer = null;
    }

    function schedule() {
        clearTimer();
        const interval = document.hidden ? BACKGROUND_POLL_MS : FOREGROUND_POLL_MS;
        timer = setInterval(tick, interval);
    }

    function startNearLivePolling() {
        // Remove the legacy 15-second timer before installing the faster loop.
        originalStopLivePolling?.();
        if (timer !== null) return;
        tick();
        schedule();
    }

    function stopNearLivePolling() {
        clearTimer();
        originalStopLivePolling?.();
    }

    // Existing application code continues to call the familiar functions,
    // but from v5.6.0 they use the near-live cadence.
    window.startLivePolling = startNearLivePolling;
    window.stopLivePolling = stopNearLivePolling;

    function syncWithAppState() {
        if (document.body.classList.contains('dashboard-mode')) {
            startNearLivePolling();
        } else {
            stopNearLivePolling();
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (timer !== null) schedule();
    });

    new MutationObserver(syncWithAppState).observe(document.body, {
        attributes: true,
        attributeFilter: ['class'],
    });

    syncWithAppState();
})();
