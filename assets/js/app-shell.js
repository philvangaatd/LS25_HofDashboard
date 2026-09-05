(() => {
    'use strict';

    if (window.__hofDashboardAppShellLoaded) return;
    window.__hofDashboardAppShellLoaded = true;

    const VERSION = '5.8.0';

    function ensureStyles() {
        if (document.getElementById('appShellStyles')) return;
        const link = document.createElement('link');
        link.id = 'appShellStyles';
        link.rel = 'stylesheet';
        link.href = `assets/css/app-shell.css?v=${VERSION}`;
        document.head.appendChild(link);
    }

    function icon(name, cls = 'app-nav-icon') {
        if (typeof window.startIcon === 'function') return window.startIcon(name, cls);
        const fallback = '<rect x="3" y="4" width="18" height="13" rx="2"></rect><path d="M8 21h8"></path><path d="M12 17v4"></path>';
        return `<svg class="${cls}" viewBox="0 0 24 24" aria-hidden="true">${fallback}</svg>`;
    }

    function brandSeal() {
        if (typeof window.startBrandSeal === 'function') return window.startBrandSeal();
        return `<svg class="brand-seal" viewBox="0 0 40 44" aria-hidden="true"><path d="M20 2.5 34 8v12c0 10.5-7 18-14 21.5C13 38 6 30.5 6 20V8z" fill="none" stroke="#C9A227" stroke-width="1.7"></path><path d="M20 11v21M20 15l-5-3M20 15l5-3M20 20l-5-3M20 20l5-3M20 25l-5-3M20 25l5-3M20 30l-4-2.5M20 30l4-2.5" fill="none" stroke="#7B9970" stroke-width="1.2" stroke-linecap="round"></path></svg>`;
    }

    const navItems = [
        { tab: 'home', label: 'Übersicht', icon: 'dashboard', group: 'Hof' },
        { tab: 'fields', label: 'Felder', icon: 'fields' },
        { tab: 'vehicles', label: 'Fuhrpark', icon: 'vehicles' },
        { tab: 'animals', label: 'Tiere', icon: 'animals' },
        { tab: 'production', label: 'Produktionen', icon: 'production', group: 'Planung' },
        { tab: 'market', label: 'Markt', icon: 'database' },
        { tab: 'missions', label: 'Verträge', icon: 'calendar' },
        { tab: 'markers', label: 'Marker', icon: 'map', group: 'AutoDrive', requiresAutoDrive: true },
        { tab: 'map', label: 'Karte', icon: 'map', requiresAutoDrive: true },
    ];

    const viewMeta = {
        home: ['Hof', 'Übersicht', 'Finanzen, Spieltag, Wetter und aktuelle Aufgaben auf einen Blick.'],
        fields: ['Live', 'Felder', 'Eigene Flächen, Kulturen, Wachstum und Pflegezustände.'],
        vehicles: ['Live', 'Fuhrpark', 'Fahrzeuge, Geräte, Betriebsstunden, Verschleiß und Füllstände.'],
        animals: ['Live', 'Tiere', 'Tierbestand, Versorgung, Gesundheit und Erzeugnisse.'],
        production: ['Planung', 'Produktionen', 'Aktive Produktionsketten, Rohstoffe und Ausstoß.'],
        market: ['Planung', 'Markt', 'Aktuelle Verkaufspreise und die besten Verkaufschancen.'],
        missions: ['Planung', 'Verträge', 'Aktive und verfügbare Aufträge mit Fortschritt.'],
        markers: ['AutoDrive', 'Marker', 'AutoDrive-Ziele organisieren, gruppieren und sichern.'],
        map: ['AutoDrive', 'Karte', 'Marker und Routen räumlich prüfen und bearbeiten.'],
        system: ['System', 'System & LS25-Integration', 'Versionen, Speicherorte, Live-Mod und lokale Umgebung.'],
    };

    function navMarkup() {
        let currentGroup = null;
        let html = `<button class="app-nav-item" data-app-action="start">${icon('home')}<span>Start</span></button>`;
        navItems.forEach(item => {
            if (item.group && item.group !== currentGroup) {
                currentGroup = item.group;
                html += `<div class="app-nav-label">${item.group}</div>`;
            }
            html += `<button class="app-nav-item" data-app-tab="${item.tab}"${item.requiresAutoDrive ? ' data-requires-autodrive="1"' : ''}>${icon(item.icon)}<span>${item.label}</span></button>`;
        });
        html += `<div class="app-nav-divider"></div>`;
        html += `<button class="app-nav-item" data-app-action="backup">${icon('backup')}<span>Backups</span></button>`;
        html += `<button class="app-nav-item" data-app-tab="system">${icon('system')}<span>System</span><i class="app-nav-badge" id="appSystemDot"></i></button>`;
        return html;
    }

    function installViewHeadings() {
        Object.entries(viewMeta).forEach(([tab, meta]) => {
            const host = document.getElementById(`tab${tab.charAt(0).toUpperCase()}${tab.slice(1)}`);
            if (!host || host.querySelector(':scope > .app-view-heading')) return;
            const heading = document.createElement('div');
            heading.className = 'app-view-heading';
            heading.innerHTML = `<div><div class="app-view-kicker">${meta[0]}</div><h2 class="app-view-title">${meta[1]}</h2><div class="app-view-copy">${meta[2]}</div></div>`;
            host.insertBefore(heading, host.firstChild);
        });
    }

    function installShell() {
        ensureStyles();
        const main = document.getElementById('mainScreen');
        if (!main || main.classList.contains('app-shell')) return;

        const existing = Array.from(main.childNodes);
        const sidebar = document.createElement('aside');
        sidebar.className = 'app-sidebar';
        sidebar.innerHTML = `
            <div class="app-sidebar-brand">${brandSeal()}<span class="brand-label">LS25 · HOF-DASHBOARD</span></div>
            <nav class="app-nav" aria-label="Hauptnavigation">${navMarkup()}</nav>
            <div class="app-sidebar-footer"><strong>LS25 Hof-Dashboard</strong><br><span id="appShellVersion">v${VERSION}</span></div>`;

        const workspace = document.createElement('main');
        workspace.className = 'app-workspace';
        existing.forEach(node => workspace.appendChild(node));

        main.appendChild(sidebar);
        main.appendChild(workspace);
        main.classList.add('app-shell');

        sidebar.querySelectorAll('[data-app-tab]').forEach(button => {
            button.addEventListener('click', () => {
                if (button.classList.contains('is-disabled')) return;
                const tab = button.dataset.appTab;
                if (typeof window.switchTab === 'function') window.switchTab(tab);
            });
        });
        sidebar.querySelector('[data-app-action="start"]')?.addEventListener('click', () => {
            if (typeof window.switchSavegame === 'function') window.switchSavegame();
        });
        sidebar.querySelector('[data-app-action="backup"]')?.addEventListener('click', () => {
            if (typeof window.openFullBackupPanel === 'function') window.openFullBackupPanel();
        });

        installViewHeadings();
        appShellApplyAvailability(window.hasAutoDrive === true || (typeof hasAutoDrive !== 'undefined' && hasAutoDrive === true));
        appShellSetActive(typeof activeTab === 'string' ? activeTab : 'home');
        syncSystemDot();
        observeLiveBadge();
        loadVersion();
    }

    function appShellSetActive(tab) {
        document.querySelectorAll('.app-nav-item[data-app-tab]').forEach(button => {
            button.classList.toggle('is-active', button.dataset.appTab === tab);
        });
    }

    function appShellApplyAvailability(enabled) {
        document.querySelectorAll('.app-nav-item[data-requires-autodrive="1"]').forEach(button => {
            button.classList.toggle('is-disabled', !enabled);
            button.title = enabled ? '' : 'In diesem Spielstand ist AutoDrive nicht aktiv.';
        });
    }

    function syncSystemDot() {
        const source = document.getElementById('liveStatusBadge');
        const dot = document.getElementById('appSystemDot');
        if (!source || !dot) return;
        const bad = source.classList.contains('live-badge-no_mod') || /kein mod|veraltet|fehler/i.test(source.textContent || '');
        dot.style.background = bad ? 'var(--accent)' : '#76c95f';
        dot.style.boxShadow = bad
            ? '0 0 0 4px rgba(201,162,39,.10)'
            : '0 0 0 4px rgba(118,201,95,.10), 0 0 12px rgba(118,201,95,.26)';
    }

    function observeLiveBadge() {
        const source = document.getElementById('liveStatusBadge');
        if (!source) return;
        new MutationObserver(syncSystemDot).observe(source, { childList: true, subtree: true, attributes: true });
    }

    async function loadVersion() {
        try {
            const response = await fetch('app-manifest.json', { cache: 'no-store' });
            const manifest = await response.json();
            const target = document.getElementById('appShellVersion');
            if (target) target.textContent = `v${manifest?.version || VERSION}`;
        } catch (_) {
            // The embedded version is an adequate fallback.
        }
    }

    window.installHofDashboardAppShell = installShell;
    window.appShellSetActive = appShellSetActive;
    window.appShellApplyAvailability = appShellApplyAvailability;

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', installShell, { once: true });
    else installShell();
})();
