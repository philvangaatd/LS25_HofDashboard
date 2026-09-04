(() => {
    'use strict';

    if (window.__hofDashboardModManagerLoaded) return;
    window.__hofDashboardModManagerLoaded = true;

    const bridge = window.chrome && window.chrome.webview;
    if (!bridge) return;

    let currentStatus = null;
    let busy = false;

    const css = document.createElement('link');
    css.rel = 'stylesheet';
    css.href = 'assets/css/mod-manager.css?v=5.4.0';
    document.head.appendChild(css);

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function post(action) {
        bridge.postMessage({ action });
    }

    function requestStatus() {
        post('mod-status');
    }
    window.requestHofModStatus = requestStatus;

    function ensureSystemHosts() {
        const hosts = [];
        const checks = document.getElementById('systemCheckContainer');
        if (checks && checks.parentNode) {
            let host = document.getElementById('modManagerContainer');
            if (!host) {
                host = document.createElement('div');
                host.id = 'modManagerContainer';
                host.className = 'mod-manager-host';
                checks.parentNode.insertBefore(host, checks);
            }
            hosts.push(host);
        }

        const pickerHost = document.getElementById('pickerModManagerContainer');
        if (pickerHost) hosts.push(pickerHost);
        return hosts;
    }

    function ensureBanner() {
        const mapInfo = document.getElementById('mapInfo');
        if (!mapInfo || !mapInfo.parentNode) return null;

        let banner = document.getElementById('modManagerBanner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'modManagerBanner';
            banner.className = 'mod-manager-banner';
            mapInfo.parentNode.insertBefore(banner, mapInfo.nextSibling);
        }
        return banner;
    }

    function stateBadge(status) {
        const labels = {
            ready: 'Einsatzbereit',
            newer: 'Installiert',
            notInstalled: 'Nicht eingerichtet',
            updateAvailable: 'Update verfügbar',
            broken: 'Reparatur nötig',
            offline: 'Prüfung nicht möglich',
        };
        return labels[status.state] || 'Status';
    }

    function stateClass(status) {
        if (status.state === 'ready' || status.state === 'newer') return 'is-ok';
        if (status.state === 'notInstalled' || status.state === 'updateAvailable') return 'is-warn';
        if (status.state === 'broken') return 'is-error';
        return 'is-info';
    }

    function systemCardHtml(status) {
        const gameNotice = status.gameRunning
            ? '<div class="mod-manager-game-notice">Landwirtschafts-Simulator 25 läuft gerade. Für Installation oder Reparatur bitte zuerst das Spiel schließen.</div>'
            : '';

        const actionDisabled = busy || (status.gameRunning && status.canInstall);
        const action = status.canInstall
            ? `<button class="primary mod-manager-primary" data-mod-action="install" ${actionDisabled ? 'disabled' : ''}>${escapeHtml(status.actionLabel)}</button>`
            : `<button data-mod-action="refresh" ${busy ? 'disabled' : ''}>Erneut prüfen</button>`;

        const versions = [
            `Dashboard ${escapeHtml(status.dashboardVersion)}`,
            status.installedVersion ? `Mod ${escapeHtml(status.installedVersion)}` : 'Mod nicht installiert',
            status.availableVersion ? `Aktuell ${escapeHtml(status.availableVersion)}` : null,
        ].filter(Boolean).join(' · ');

        return `
            <section class="mod-manager-card ${stateClass(status)}">
                <div class="mod-manager-card-head">
                    <div>
                        <div class="mod-manager-kicker">LS25-Integration</div>
                        <h3>${escapeHtml(status.title)}</h3>
                    </div>
                    <span class="mod-manager-badge">${escapeHtml(stateBadge(status))}</span>
                </div>
                <p class="mod-manager-detail">${escapeHtml(status.detail)}</p>
                <div class="mod-manager-versionline">${versions}</div>
                ${gameNotice}
                <div class="mod-manager-actions">
                    ${action}
                    <button data-mod-action="refresh" ${busy ? 'disabled' : ''}>↻ Status prüfen</button>
                </div>
                <details class="mod-manager-details">
                    <summary>Technische Details</summary>
                    <div class="mod-manager-detail-grid">
                        <span>Mod-Ordner</span>
                        <code>${escapeHtml(status.directory || 'nicht ermittelt')}</code>
                        <span>Pfadquelle</span>
                        <span>${status.usesCustomDirectory ? 'manuell gewählt' : 'automatisch erkannt'}</span>
                    </div>
                    <div class="mod-manager-actions compact">
                        <button data-mod-action="folder">Mod-Ordner auswählen</button>
                        <button data-mod-action="open-folder">Ordner öffnen</button>
                        ${status.releaseNotesUrl ? `<a class="button-link" href="${escapeHtml(status.releaseNotesUrl)}" target="_blank" rel="noreferrer">Mod-Release ansehen</a>` : ''}
                    </div>
                </details>
            </section>`;
    }

    function renderSystemCard(status) {
        ensureSystemHosts().forEach(host => {
            host.innerHTML = systemCardHtml(status);
            bindActions(host);
        });
    }

    function renderBanner(status) {
        const banner = ensureBanner();
        if (!banner) return;

        const attention = ['notInstalled', 'updateAvailable', 'broken'].includes(status.state);
        if (!attention) {
            banner.style.display = 'none';
            banner.innerHTML = '';
            return;
        }

        const copy = {
            notInstalled: ['Live-Daten einrichten', 'Ein Klick installiert die benötigte LS25-Mod automatisch.'],
            updateAvailable: ['Mod-Update verfügbar', 'Aktualisiere die LS25-Verbindung direkt im Dashboard.'],
            broken: ['LS25-Verbindung reparieren', 'Die Mod-Installation ist beschädigt oder unvollständig.'],
        }[status.state];

        const disabled = busy || status.gameRunning;
        banner.className = `mod-manager-banner ${stateClass(status)}`;
        banner.style.display = 'flex';
        banner.innerHTML = `
            <div class="mod-manager-banner-copy">
                <strong>${escapeHtml(copy[0])}</strong>
                <span>${escapeHtml(status.gameRunning ? 'Bitte LS25 zuerst schließen. Danach kann das Dashboard die Mod installieren.' : copy[1])}</span>
            </div>
            <button class="primary" data-mod-action="install" ${disabled ? 'disabled' : ''}>${escapeHtml(status.actionLabel)}</button>`;
        bindActions(banner);
    }

    function progressCardHtml(progress) {
        const percent = Math.max(0, Math.min(100, Number(progress.percent) || 0));
        return `
            <section class="mod-manager-card is-info">
                <div class="mod-manager-card-head">
                    <div>
                        <div class="mod-manager-kicker">LS25-Integration</div>
                        <h3>Mod wird eingerichtet</h3>
                    </div>
                    <span class="mod-manager-badge">${percent} %</span>
                </div>
                <p class="mod-manager-detail">${escapeHtml(progress.message || 'Bitte einen Moment …')}</p>
                <div class="mod-manager-progress"><span style="width:${percent}%"></span></div>
            </section>`;
    }

    function renderProgress(progress) {
        busy = progress.percent < 100;
        ensureSystemHosts().forEach(host => {
            host.innerHTML = progressCardHtml(progress);
        });

        const banner = ensureBanner();
        if (banner && busy) {
            banner.className = 'mod-manager-banner is-info';
            banner.style.display = 'flex';
            banner.innerHTML = `
                <div class="mod-manager-banner-copy">
                    <strong>LS25-Mod wird eingerichtet</strong>
                    <span>${escapeHtml(progress.message || 'Download und Prüfung laufen …')}</span>
                </div>`;
        }
    }

    function bindActions(root) {
        root.querySelectorAll('[data-mod-action]').forEach(element => {
            element.addEventListener('click', () => {
                const action = element.dataset.modAction;
                if (action === 'install') {
                    busy = true;
                    renderProgress({ percent: 1, message: 'Installation wird vorbereitet …' });
                    post('mod-install');
                } else if (action === 'folder') {
                    post('mod-select-folder');
                } else if (action === 'open-folder') {
                    post('mod-open-folder');
                } else {
                    requestStatus();
                }
            });
        });
    }

    function showMessage(message, isError = false) {
        if (typeof window.showToast === 'function') {
            try {
                window.showToast(message, isError ? 'error' : 'success');
                return;
            } catch (_) {
                // Fall through to the unobtrusive native banner below.
            }
        }

        const banner = ensureBanner();
        if (!banner) return;
        banner.className = `mod-manager-banner ${isError ? 'is-error' : 'is-ok'}`;
        banner.style.display = 'flex';
        banner.innerHTML = `<div class="mod-manager-banner-copy"><strong>${isError ? 'Aktion fehlgeschlagen' : 'Erledigt'}</strong><span>${escapeHtml(message)}</span></div>`;
        if (!isError) setTimeout(() => renderBanner(currentStatus || {}), 3500);
    }

    bridge.addEventListener('message', event => {
        const message = event.data || {};
        if (message.type === 'mod-manager-status') {
            busy = false;
            currentStatus = message.status;
            renderSystemCard(message.status);
            renderBanner(message.status);
            if (typeof window.updateStartModStatus === 'function') window.updateStartModStatus(message.status);
            if (message.notice) showMessage(message.notice, false);
        } else if (message.type === 'mod-manager-progress') {
            renderProgress(message.progress || {});
        } else if (message.type === 'mod-manager-error') {
            busy = false;
            showMessage(message.message || 'Die Aktion konnte nicht ausgeführt werden.', true);
            requestStatus();
        }
    });

    document.getElementById('tabBtnSystem')?.addEventListener('click', () => {
        setTimeout(requestStatus, 50);
    });

    requestStatus();
})();
