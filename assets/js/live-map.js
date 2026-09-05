(() => {
    'use strict';
    if (window.__hofDashboardLiveMapLoaded) return;
    window.__hofDashboardLiveMapLoaded = true;

    const state = {
        players: [],
        vehicles: [],
        showPlayers: true,
        showVehicles: true,
        showImplements: false,
        lastHitTargets: [],
        selected: null,
    };

    function escapeText(value) {
        return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
    }

    function getVehicleCategory(v) {
        return String(v.vehicleCategory || v.vehicleType || 'IMPLEMENT').toUpperCase();
    }

    function normalizeLive(data) {
        state.players = Array.isArray(data?.farm?.players) ? data.farm.players.filter(p => Number.isFinite(Number(p.x)) && Number.isFinite(Number(p.z))) : [];
        state.vehicles = Array.isArray(data?.vehicles) ? data.vehicles.filter(v => Number.isFinite(Number(v.mapX)) && Number.isFinite(Number(v.mapZ))) : [];
    }

    async function refresh() {
        try {
            const res = await fetch(`api.php?action=live_data&t=${Date.now()}`, { cache: 'no-store' });
            if (!res.ok) return;
            normalizeLive(await res.json());
            if (typeof window.mapRedraw === 'function') window.mapRedraw();
        } catch (_) {}
    }

    function installControls() {
        const mapTab = document.getElementById('tabMap');
        const toolbar = mapTab?.querySelector('.toolbar');
        if (!toolbar || document.getElementById('liveMapControls')) return;
        const wrap = document.createElement('div');
        wrap.id = 'liveMapControls';
        wrap.className = 'live-map-controls';
        wrap.innerHTML = `
            <button type="button" class="live-map-toggle is-active" data-live-layer="players">Spieler</button>
            <button type="button" class="live-map-toggle is-active" data-live-layer="vehicles">Fahrzeuge</button>
            <button type="button" class="live-map-toggle" data-live-layer="implements">Geräte</button>`;
        toolbar.insertBefore(wrap, toolbar.firstChild?.nextSibling || null);
        wrap.addEventListener('click', event => {
            const btn = event.target.closest('[data-live-layer]');
            if (!btn) return;
            const key = btn.dataset.liveLayer;
            if (key === 'players') state.showPlayers = !state.showPlayers;
            if (key === 'vehicles') state.showVehicles = !state.showVehicles;
            if (key === 'implements') state.showImplements = !state.showImplements;
            btn.classList.toggle('is-active', key === 'players' ? state.showPlayers : key === 'vehicles' ? state.showVehicles : state.showImplements);
            window.mapRedraw?.();
        });
    }

    function drawDirection(ctx, cx, cy, yaw, size, color) {
        ctx.save();
        ctx.translate(cx, cy);
        ctx.rotate(Number(yaw || 0));
        ctx.beginPath();
        ctx.moveTo(0, -size - 5);
        ctx.lineTo(-3, -size + 1);
        ctx.lineTo(3, -size + 1);
        ctx.closePath();
        ctx.fillStyle = color;
        ctx.fill();
        ctx.restore();
    }

    function drawLiveLayers() {
        try {
            if (typeof mapCtx === 'undefined' || !mapCtx || typeof mapCanvasEl === 'undefined' || !mapCanvasEl || typeof worldToCanvas !== 'function' || typeof activeTab === 'undefined' || activeTab !== 'map') return;
            const rect = mapCanvasEl.getBoundingClientRect();
            state.lastHitTargets = [];

            if (state.showVehicles) {
                for (const v of state.vehicles) {
                    const category = getVehicleCategory(v);
                    if ((category === 'IMPLEMENT' || category === 'TRAILER') && !state.showImplements) continue;
                    const [cx, cy] = worldToCanvas(Number(v.mapX), Number(v.mapZ));
                    if (cx < -20 || cx > rect.width + 20 || cy < -20 || cy > rect.height + 20) continue;
                    const controlled = !!v.isControlled;
                    const radius = controlled ? 8 : 6;
                    const color = controlled ? '#F2C230' : (category === 'VEHICLE' ? '#E7E5D8' : '#9DB48E');
                    mapCtx.beginPath();
                    mapCtx.arc(cx, cy, radius, 0, Math.PI * 2);
                    mapCtx.fillStyle = '#11140d';
                    mapCtx.fill();
                    mapCtx.strokeStyle = color;
                    mapCtx.lineWidth = controlled ? 3 : 2;
                    mapCtx.stroke();
                    drawDirection(mapCtx, cx, cy, Number(v.mapYaw || 0), radius, color);
                    state.lastHitTargets.push({ type: 'vehicle', entity: v, cx, cy, radius: 13 });
                    if (typeof mapView !== 'undefined' && mapView.scale > 1.6) {
                        mapCtx.font = '11px "IBM Plex Mono", monospace';
                        mapCtx.fillStyle = '#ECE7D8';
                        mapCtx.fillText(String(v.name || v.model || 'Fahrzeug'), cx + 10, cy - 8);
                    }
                }
            }

            if (state.showPlayers) {
                for (const p of state.players) {
                    const [cx, cy] = worldToCanvas(Number(p.x), Number(p.z));
                    if (cx < -20 || cx > rect.width + 20 || cy < -20 || cy > rect.height + 20) continue;
                    const color = p.isLocal ? '#6EDC5F' : '#74B8FF';
                    mapCtx.beginPath();
                    mapCtx.arc(cx, cy, p.isLocal ? 7 : 6, 0, Math.PI * 2);
                    mapCtx.fillStyle = color;
                    mapCtx.fill();
                    mapCtx.strokeStyle = '#11140d';
                    mapCtx.lineWidth = 2;
                    mapCtx.stroke();
                    drawDirection(mapCtx, cx, cy, Number(p.yaw || 0), 6, color);
                    state.lastHitTargets.push({ type: 'player', entity: p, cx, cy, radius: 13 });
                    if (typeof mapView !== 'undefined' && mapView.scale > 1.2) {
                        mapCtx.font = '11px "IBM Plex Mono", monospace';
                        mapCtx.fillStyle = '#ECE7D8';
                        mapCtx.fillText(String(p.name || 'Spieler'), cx + 10, cy - 8);
                    }
                }
            }
        } catch (_) {}
    }

    function showDetail(target) {
        const wrap = document.querySelector('#tabMap .map-wrap');
        if (!wrap) return;
        let panel = document.getElementById('liveMapDetail');
        if (!panel) {
            panel = document.createElement('div');
            panel.id = 'liveMapDetail';
            panel.className = 'live-map-detail';
            wrap.appendChild(panel);
        }
        const e = target.entity;
        if (target.type === 'player') {
            panel.innerHTML = `<button class="live-map-close">×</button><div class="live-map-kicker">Spieler</div><strong>${escapeText(e.name || 'Spieler')}</strong><div>${e.isLocal ? 'Lokaler Spieler' : 'Mitspieler'}</div>`;
        } else {
            panel.innerHTML = `<button class="live-map-close">×</button><div class="live-map-kicker">${escapeText(getVehicleCategory(e) === 'VEHICLE' ? 'Fahrzeug' : 'Gerät')}</div><strong>${escapeText(e.name || e.model || 'Fahrzeug')}</strong><div>${Number(e.speedKph || 0).toLocaleString('de-DE', {maximumFractionDigits:1})} km/h${e.isWorking ? ' · KI aktiv' : ''}${e.isControlled ? ' · gesteuert' : ''}</div>`;
        }
        panel.querySelector('.live-map-close')?.addEventListener('click', () => panel.remove());
    }

    function installCanvasInteraction() {
        const canvas = document.getElementById('mapCanvas');
        if (!canvas || canvas.dataset.liveMapClick === '1') return;
        canvas.dataset.liveMapClick = '1';
        canvas.addEventListener('click', event => {
            if (typeof editMode !== 'undefined' && editMode !== 'view') return;
            const rect = canvas.getBoundingClientRect();
            const x = event.clientX - rect.left;
            const y = event.clientY - rect.top;
            const target = state.lastHitTargets.slice().reverse().find(t => Math.hypot(t.cx - x, t.cy - y) <= t.radius);
            if (target) showDetail(target);
        });
    }

    function installVehicleMapButtons() {
        document.querySelectorAll('#vehiclesContainer .vehicle-card').forEach(card => {
            if (card.querySelector('.vehicle-map-button')) return;
            const name = card.querySelector('.vehicle-name')?.textContent?.trim();
            if (!name) return;
            const vehicle = state.vehicles.find(v => String(v.name || v.model || '').trim() === name);
            if (!vehicle) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'vehicle-map-button';
            btn.textContent = 'Auf Karte anzeigen';
            btn.addEventListener('click', () => window.showVehicleOnMap(vehicle.id || vehicle.uniqueId || name));
            card.querySelector('.vehicle-card-header')?.appendChild(btn);
        });
    }

    window.showVehicleOnMap = function(identifier) {
        const vehicle = state.vehicles.find(v => String(v.id || v.uniqueId || v.name) === String(identifier)) || state.vehicles.find(v => String(v.name) === String(identifier));
        if (!vehicle) { window.showToast?.('Fahrzeugposition noch nicht verfügbar.', 'err'); return; }
        if (typeof window.switchTab === 'function') window.switchTab('map');
        setTimeout(() => {
            try {
                if (typeof mapView !== 'undefined') {
                    mapView.centerX = Number(vehicle.mapX);
                    mapView.centerZ = Number(vehicle.mapZ);
                    mapView.scale = Math.max(Number(mapView.scale || 1), 2.5);
                    window.mapRedraw?.();
                    const target = state.lastHitTargets.find(t => t.type === 'vehicle' && t.entity === vehicle);
                    if (target) showDetail(target);
                }
            } catch (_) {}
        }, 250);
    };

    function hookMapRedraw() {
        if (typeof window.mapRedraw !== 'function' || window.mapRedraw.__liveMapWrapped) return false;
        const original = window.mapRedraw;
        const wrapped = function(...args) {
            const result = original.apply(this, args);
            drawLiveLayers();
            return result;
        };
        wrapped.__liveMapWrapped = true;
        window.mapRedraw = wrapped;
        return true;
    }

    function install() {
        installControls();
        installCanvasInteraction();
        hookMapRedraw();
        refresh();
        setInterval(refresh, 1000);
        const observer = new MutationObserver(() => {
            installControls();
            installCanvasInteraction();
            installVehicleMapButtons();
            hookMapRedraw();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', install, { once: true });
    else install();
})();
