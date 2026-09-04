(() => {
    'use strict';
    if (window.__hofDashboardAppShellSyncLoaded) return;
    window.__hofDashboardAppShellSyncLoaded = true;

    const tabMap = {
        tabBtnHome: 'home',
        tabBtnFields: 'fields',
        tabBtnVehicles: 'vehicles',
        tabBtnAnimals: 'animals',
        tabBtnStorage: 'storage',
        tabBtnProduction: 'production',
        tabBtnMarket: 'market',
        tabBtnMissions: 'missions',
        tabBtnMarkers: 'markers',
        tabBtnMap: 'map',
        tabBtnSystem: 'system',
    };

    function syncActiveTab() {
        for (const [id, tab] of Object.entries(tabMap)) {
            const button = document.getElementById(id);
            if (button?.classList.contains('active')) {
                window.appShellSetActive?.(tab);
                break;
            }
        }
    }

    function syncAutoDrive() {
        const markerButton = document.getElementById('tabBtnMarkers');
        const available = !!markerButton && markerButton.style.display !== 'none';
        window.appShellApplyAvailability?.(available);
    }

    function attach() {
        Object.keys(tabMap).forEach(id => {
            const button = document.getElementById(id);
            if (!button) return;
            new MutationObserver(syncActiveTab).observe(button, { attributes: true, attributeFilter: ['class'] });
        });
        const markerButton = document.getElementById('tabBtnMarkers');
        if (markerButton) {
            new MutationObserver(syncAutoDrive).observe(markerButton, { attributes: true, attributeFilter: ['style'] });
        }
        syncActiveTab();
        syncAutoDrive();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attach, { once: true });
    else attach();
})();
