(() => {
    'use strict';

    if (window.__hofDashboardSidebarCollapseLoaded) return;
    window.__hofDashboardSidebarCollapseLoaded = true;

    const STORAGE_KEY = 'hofDashboard.sidebarCollapsed';

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
        } catch (_) {}
    }

    function getLabel(button) {
        return (button?.querySelector(':scope > span')?.textContent || '').trim();
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

        const versionTarget = footer.querySelector('[id$="Version"]');
        const compact = document.createElement('div');
        compact.className = 'sidebar-footer-compact';
        compact.innerHTML = `<strong>© LS25</strong><span>${versionTarget?.textContent?.trim() || ''}</span>`;
        footer.appendChild(compact);

        if (versionTarget) {
            new MutationObserver(() => {
                const target = compact.querySelector('span');
                if (target) target.textContent = versionTarget.textContent || '';
            }).observe(versionTarget, { childList: true, subtree: true, characterData: true });
        }
    }

    function ensureToggle(root) {
        const brand = root.querySelector('.app-sidebar-brand, .start-brand');
        if (!brand || brand.querySelector('.sidebar-collapse-toggle')) return;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'sidebar-collapse-toggle';
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
        const label = collapsed ? 'Navigation vergrößern' : 'Navigation verkleinern';
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', label);
        toggle.title = label;
    }

    function prepareRoot(root) {
        if (!root) return false;
        ensureToggle(root);
        ensureFooter(root);
        updateToggle(root);
        ensureTooltips(root);
        return !!root.querySelector('.sidebar-collapse-toggle');
    }

    function setCollapsed(collapsed, persist = false) {
        document.querySelectorAll('#mainScreen.app-shell, #pickerScreen.start-shell').forEach(root => {
            root.classList.toggle('sidebar-collapsed', collapsed);
            prepareRoot(root);
            ensureTooltips(root);
        });
        if (persist) saveState(collapsed);
        window.dispatchEvent(new CustomEvent('hofdashboard:sidebar', { detail: { collapsed } }));
    }

    function prepareAvailableRoots() {
        const roots = Array.from(document.querySelectorAll('#mainScreen.app-shell, #pickerScreen.start-shell'));
        roots.forEach(prepareRoot);
        setCollapsed(isCollapsed(), false);
        return roots.length >= 2 && roots.every(root => root.querySelector('.sidebar-collapse-toggle'));
    }

    function install() {
        if (prepareAvailableRoots()) return;

        // Die beiden Shells werden nur einmal aufgebaut. Der Observer wird deshalb
        // sofort wieder getrennt, sobald beide Sidebars bereit sind, statt dauerhaft
        // jede DOM-Änderung der gesamten Anwendung zu beobachten.
        const observer = new MutationObserver(() => {
            if (prepareAvailableRoots()) observer.disconnect();
        });
        observer.observe(document.body, { childList: true, subtree: true });
        setTimeout(() => observer.disconnect(), 10000);
    }

    window.setHofDashboardSidebarCollapsed = setCollapsed;

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, { once: true });
    else install();
})();
