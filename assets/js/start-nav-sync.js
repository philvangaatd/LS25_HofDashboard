(() => {
    'use strict';

    if (window.__hofDashboardStartNavSyncLoaded) return;
    window.__hofDashboardStartNavSyncLoaded = true;

    const disabledTitle = 'Nach Auswahl eines Spielstands verfügbar';
    const autoDriveTitle = 'Nach Auswahl eines Spielstands mit AutoDrive verfügbar';

    function icon(name) {
        if (typeof window.startIcon === 'function') {
            return window.startIcon(name, 'start-nav-icon');
        }
        return '';
    }

    function disabledItem(iconName, label, title = disabledTitle) {
        return `<button class="start-nav-item is-disabled" type="button" title="${title}">${icon(iconName)}<span>${label}</span></button>`;
    }

    function renderStartNavigation() {
        const nav = document.querySelector('#pickerScreen .start-nav');
        if (!nav || nav.dataset.synced === '1') return false;

        nav.innerHTML = `
            <button class="start-nav-item is-active" id="startNavHome" type="button" data-start-view="home">${icon('home')}<span>Start</span></button>

            <div class="start-nav-label">Hof</div>
            ${disabledItem('dashboard', 'Übersicht')}
            ${disabledItem('fields', 'Felder')}
            ${disabledItem('vehicles', 'Fuhrpark')}
            ${disabledItem('animals', 'Tiere')}
            ${disabledItem('storage', 'Vorräte')}

            <div class="start-nav-label">Planung</div>
            ${disabledItem('production', 'Produktionen')}
            ${disabledItem('database', 'Markt')}
            ${disabledItem('calendar', 'Verträge')}

            <div class="start-nav-label">AutoDrive</div>
            ${disabledItem('map', 'Marker', autoDriveTitle)}
            ${disabledItem('map', 'Karte', autoDriveTitle)}

            <div class="start-nav-divider"></div>
            ${disabledItem('backup', 'Backups')}
            <button class="start-nav-item" id="startNavSystem" type="button" data-start-view="system">${icon('system')}<span>System</span><i class="start-system-dot" id="startSystemDot"></i></button>`;

        nav.dataset.synced = '1';

        document.getElementById('startNavHome')?.addEventListener('click', () => {
            if (typeof window.openStartHome === 'function') window.openStartHome();
        });
        document.getElementById('startNavSystem')?.addEventListener('click', () => {
            if (typeof window.openStartSystem === 'function') window.openStartSystem();
        });

        if (window.startModStatus && typeof window.updateStartModStatus === 'function') {
            window.updateStartModStatus(window.startModStatus);
        }

        return true;
    }

    function install() {
        if (renderStartNavigation()) return;
        const observer = new MutationObserver(() => {
            if (renderStartNavigation()) observer.disconnect();
        });
        observer.observe(document.documentElement, { childList: true, subtree: true });
        setTimeout(() => observer.disconnect(), 10000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', install, { once: true });
    } else {
        install();
    }
})();
