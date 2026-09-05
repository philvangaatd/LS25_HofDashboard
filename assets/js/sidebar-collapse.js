(() => {
    'use strict';

    if (window.__hofDashboardSidebarCollapseLoaded) return;
    window.__hofDashboardSidebarCollapseLoaded = true;

    const STORAGE_KEY = 'hofDashboard.sidebarCollapsed';

    function loadStartNavSync() {
        if (window.__hofDashboardStartNavSyncRequested) return;
        window.__hofDashboardStartNavSyncRequested = true;
        const script = document.createElement('script');
        script.src = 'assets/js/start-nav-sync.js?v=5.6.2';
        script.async = false;
        document.head.appendChild(script);
    }

    function isCollapsed() {
        try {
            return localStorage.getItem(STORAGE_KEY) === '1';
        } catch (_) {
            return false;
        }
    }

    function saveState(collapsed) {
        try {
            localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
        } catch (_) {
            // Persistence is optional; the UI remains fully functional without it.
        }
    }

    function getLabel(button) {
        const span = button?.querySelector(':scope > span');
        return (span?.textContent || '').trim();
    }

    function ensureTooltips(root) {
        root.querySelectorAll('.app-nav-item, .start-nav-item').forEach(button => {
            const label = getLabel(button);
            if (label && !button.dataset.sidebarLabel) button.dataset.sidebarLabel = label;
            if (root.classList.contains('sidebar-collapsed') && label) {
                button.title = button.title || label;
            } else if (button.title === button.dataset.sidebarLabel) {
                button.removeAttribute('title');
            }
        });
    }

    function ensureFooter(root) {
        const footer = root.querySelector('.app-sidebar-footer, .start-sidebar-footer');
        if (!footer || footer.querySelector('.sidebar-footer-compact')) return;

        const version = footer.querySelector('[id$="Version"]')?.textContent?.trim() || '';
        const compact = document.createElement('div');
        compact.className = 'sidebar-footer-compact';
        compact.innerHTML = `<strong>© LS25</strong><span>${version}</span>`;
        footer.appendChild(compact);

        const versionTarget = footer.querySelector('[id$="Version"]');
        if (versionTarget) {
            new MutationObserver(() => {
                const compactVersion = compact.querySelector('span');
                if (compactVersion) compactVersion.textContent = versionTarget.textContent || '';
            }).observe(versionTarget, { childList: true, subtree: true, characterData: true });
        }
    }

    function ensureToggle(root, brand) {
        if (!brand || brand.querySelector('.sidebar-collapse-toggle')) return;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'sidebar-collapse-toggle';
        button.setAttribute('aria-label', 'Navigation verkleinern');
        button.setAttribute('aria-expanded', 'true');
        button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"></path></svg>';
        button.addEventListener('click', event => {
            event.preventDefault();
            event.stopPropagation();
            setCollapsed(!root.classList.contains('sidebar-collapsed'), true);
        });
        brand.appendChild(button);
    }

    function updateToggle(root) {
        const collapsed = root.classList.contains('sidebar-collapsed');
        const toggle = root.querySelector('.sidebar-collapse-toggle');
        if (!toggle) return;
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', collapsed ? 'Navigation vergrößern' : 'Navigation verkleinern');
        toggle.title = collapsed ? 'Navigation vergrößern' : 'Navigation verkleinern';
    }

    function prepareRoot(root) {
        if (!root) return;
        const brand = root.querySelector('.app-sidebar-brand, .start-brand');
        ensureToggle(root, brand);
        ensureFooter(root);
        updateToggle(root);
        ensureTooltips(root);
    }

    function setCollapsed(collapsed, persist = false) {
        document.querySelectorAll('#mainScreen.app-shell, #pickerScreen.start-shell').forEach(root => {
            root.classList.toggle('sidebar-collapsed', collapsed);
            prepareRoot(root);
            updateToggle(root);
            ensureTooltips(root);
        });
        if (persist) saveState(collapsed);
        window.dispatchEvent(new CustomEvent('hofdashboard:sidebar', { detail: { collapsed } }));
    }

    function install() {
        loadStartNavSync();
        document.querySelectorAll('#mainScreen.app-shell, #pickerScreen.start-shell').forEach(prepareRoot);
        setCollapsed(isCollapsed(), false);

        const observer = new MutationObserver(() => {
            document.querySelectorAll('#mainScreen.app-shell, #pickerScreen.start-shell').forEach(prepareRoot);
            setCollapsed(isCollapsed(), false);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    window.setHofDashboardSidebarCollapsed = setCollapsed;

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, { once: true });
    else install();
})();
