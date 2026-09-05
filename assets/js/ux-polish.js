(() => {
    'use strict';

    if (window.__hofDashboardUxPolishLoaded) return;
    window.__hofDashboardUxPolishLoaded = true;

    let animalTypeFilter = 'ALL';

    function normalizedText(value) {
        return String(value || '').trim().replace(/\s+/g, ' ');
    }

    function removeRedundantRefreshButtons() {
        document.querySelectorAll('[id^="tab"] .toolbar').forEach(toolbar => {
            const tab = toolbar.closest('[id^="tab"]');
            if (!tab || tab.id === 'tabMarkers' || tab.id === 'tabMap') return;

            toolbar.querySelectorAll('button').forEach(button => {
                if (/aktualisieren/i.test(normalizedText(button.textContent))) button.remove();
            });

            const hasUsefulContent = Array.from(toolbar.children).some(element => {
                if (element.hidden || element.style.display === 'none') return false;
                if (element.matches('input[type="file"]')) return false;
                return true;
            });
            if (!hasUsefulContent) toolbar.hidden = true;
        });
    }

    function moveTabFootnotes() {
        document.querySelectorAll('[id^="tab"] .legend-line').forEach(note => {
            const tab = note.closest('[id^="tab"]');
            if (!tab || note.dataset.footerNote === '1') return;
            note.dataset.footerNote = '1';
            note.classList.add('view-footnote');
            tab.appendChild(note);
        });

        // Einzelne erklärende Toolbar-Hinweise ohne dynamische ID gehören ebenfalls
        // ans Ende des Views. Dynamische Labels wie der Markt-Zeitraum bleiben in der Toolbar.
        document.querySelectorAll('[id^="tab"] .toolbar .map-hint:not([id])').forEach(note => {
            const tab = note.closest('[id^="tab"]');
            if (!tab || note.dataset.footerNote === '1') return;
            note.dataset.footerNote = '1';
            note.classList.add('view-footnote');
            tab.appendChild(note);
        });

        const mapSizeHint = document.getElementById('mapSizeHint');
        const mapTab = document.getElementById('tabMap');
        if (mapSizeHint && mapTab && mapSizeHint.dataset.footerNote !== '1') {
            mapSizeHint.dataset.footerNote = '1';
            mapSizeHint.classList.add('view-footnote', 'map-image-footnote');
            mapTab.appendChild(mapSizeHint);
        }
    }

    function animalCardType(card) {
        return normalizedText(card.querySelector('.animal-kind')?.textContent || 'Tiere');
    }

    function syncAnimalFilterOptions() {
        const select = document.getElementById('animalTypeFilterSelect');
        const container = document.getElementById('animalsContainer');
        if (!select || !container) return;

        const types = Array.from(container.querySelectorAll(':scope > .animal-card'))
            .map(animalCardType)
            .filter(Boolean)
            .filter((value, index, values) => values.indexOf(value) === index)
            .sort((a, b) => a.localeCompare(b, 'de', { numeric: true, sensitivity: 'base' }));

        const previous = animalTypeFilter;
        select.innerHTML = '<option value="ALL">Alle Tierarten</option>'
            + types.map(type => `<option value="${type.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}">${type.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</option>`).join('');

        animalTypeFilter = previous === 'ALL' || types.includes(previous) ? previous : 'ALL';
        select.value = animalTypeFilter;
    }

    function applyAnimalFilter() {
        const container = document.getElementById('animalsContainer');
        if (!container) return;

        container.querySelectorAll(':scope > .animal-card').forEach(card => {
            card.hidden = animalTypeFilter !== 'ALL' && animalCardType(card) !== animalTypeFilter;
        });

        // Die Futterprognose fasst mehrere Tierarten zusammen. Bei aktivem Filter
        // blenden wir sie aus, statt Daten anderer Tierarten in die gefilterte Ansicht zu mischen.
        container.querySelectorAll(':scope > .animal-feed-forecast').forEach(plan => {
            plan.hidden = animalTypeFilter !== 'ALL';
        });

        const visibleCards = Array.from(container.querySelectorAll(':scope > .animal-card'))
            .filter(card => !card.hidden);
        let empty = container.querySelector(':scope > .animal-filter-empty');
        if (visibleCards.length === 0 && animalTypeFilter !== 'ALL') {
            if (!empty) {
                empty = document.createElement('div');
                empty.className = 'empty-note animal-filter-empty';
                container.appendChild(empty);
            }
            empty.textContent = `Keine Tierhaltung für „${animalTypeFilter}“ gefunden.`;
        } else {
            empty?.remove();
        }
    }

    function installAnimalFilter() {
        const tab = document.getElementById('tabAnimals');
        const toolbar = tab?.querySelector(':scope > .toolbar');
        if (!toolbar || document.getElementById('animalTypeFilterSelect')) return;

        toolbar.hidden = false;
        const control = document.createElement('label');
        control.className = 'animal-type-filter';
        control.innerHTML = '<span>Tierart</span><select id="animalTypeFilterSelect" aria-label="Tierart filtern"><option value="ALL">Alle Tierarten</option></select>';
        toolbar.prepend(control);

        control.querySelector('select')?.addEventListener('change', event => {
            animalTypeFilter = event.target.value || 'ALL';
            applyAnimalFilter();
        });

        syncAnimalFilterOptions();
        applyAnimalFilter();
    }

    function decorateOverviewLinks() {
        const grid = document.getElementById('statGrid');
        if (!grid) return;

        grid.querySelectorAll('.stat-card').forEach(card => {
            const label = normalizedText(card.querySelector('.stat-label')?.textContent);
            if (label !== 'Fuhrpark') return;
            card.classList.add('stat-card-link');
            card.setAttribute('role', 'button');
            card.setAttribute('tabindex', '0');
            card.setAttribute('aria-label', 'Fuhrpark öffnen');
            card.title = 'Fuhrpark öffnen';
        });
    }

    function installOverviewNavigation() {
        const grid = document.getElementById('statGrid');
        if (!grid || grid.dataset.vehicleNav === '1') return;
        grid.dataset.vehicleNav = '1';

        const activate = event => {
            const card = event.target.closest('.stat-card-link');
            if (!card || !grid.contains(card)) return;
            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
            if (event.type === 'keydown') event.preventDefault();
            if (typeof window.switchTab === 'function') window.switchTab('vehicles');
        };

        grid.addEventListener('click', activate);
        grid.addEventListener('keydown', activate);
        decorateOverviewLinks();
    }

    function normalizeVehicleGrid() {
        const container = document.getElementById('vehiclesContainer');
        if (!container) return;

        const directCards = Array.from(container.children).filter(child => child.classList.contains('vehicle-card'));
        if (directCards.length === 0) return;

        let grid = container.querySelector(':scope > .vehicle-card-grid');
        if (!grid) {
            grid = document.createElement('div');
            grid.className = 'vehicle-card-grid';
            directCards[0].before(grid);
        }
        directCards.forEach(card => grid.appendChild(card));
    }

    async function reloadAutoDriveMap() {
        const performReload = async () => {
            try {
                if (typeof window.loadMarkers === 'function') await window.loadMarkers();

                // Kursdaten werden von ensureMapLoaded nur bei leerem Cache neu gelesen.
                if (typeof points !== 'undefined') points = new Map();
                if (typeof courseBounds !== 'undefined') courseBounds = null;
                if (typeof guessedMapBounds !== 'undefined') guessedMapBounds = null;
                if (typeof edgesList !== 'undefined') edgesList = [];
                if (typeof courseOriginalSnapshot !== 'undefined') courseOriginalSnapshot = null;
                if (typeof nextNewId !== 'undefined') nextNewId = null;
                if (typeof undoStack !== 'undefined') undoStack = [];
                if (typeof orphanHighlightIds !== 'undefined') orphanHighlightIds = null;

                if (typeof window.ensureMapLoaded === 'function') await window.ensureMapLoaded();
                window.showToast?.('AutoDrive-Karte neu geladen.', 'ok');
            } catch (error) {
                console.error('AutoDrive-Karte konnte nicht neu geladen werden:', error);
                window.showToast?.('AutoDrive-Karte konnte nicht neu geladen werden.', 'err');
            }
        };

        if (typeof window.confirmDiscardIfDirty === 'function') {
            window.confirmDiscardIfDirty('Karte neu laden', performReload);
        } else {
            await performReload();
        }
    }

    function installMapReloadButton() {
        const toolbar = document.querySelector('#tabMap > .toolbar');
        if (!toolbar || document.getElementById('mapReloadButton')) return;

        const button = document.createElement('button');
        button.id = 'mapReloadButton';
        button.type = 'button';
        button.innerHTML = '<span class="ui-icon">↻</span> Neu laden';
        button.addEventListener('click', reloadAutoDriveMap);

        const resetButton = toolbar.querySelector('button');
        if (resetButton) resetButton.insertAdjacentElement('afterend', button);
        else toolbar.prepend(button);
    }

    function wrapRenderFunctions() {
        if (typeof window.renderAnimals === 'function' && !window.renderAnimals.__uxPolished) {
            const original = window.renderAnimals;
            const wrapped = function(...args) {
                const result = original.apply(this, args);
                syncAnimalFilterOptions();
                applyAnimalFilter();
                return result;
            };
            wrapped.__uxPolished = true;
            window.renderAnimals = wrapped;
        }

        if (typeof window.renderVehicles === 'function' && !window.renderVehicles.__uxPolished) {
            const original = window.renderVehicles;
            const wrapped = function(...args) {
                const result = original.apply(this, args);
                normalizeVehicleGrid();
                return result;
            };
            wrapped.__uxPolished = true;
            window.renderVehicles = wrapped;
        }

        if (typeof window.loadFarmOverview === 'function' && !window.loadFarmOverview.__uxPolished) {
            const original = window.loadFarmOverview;
            const wrapped = async function(...args) {
                const result = await original.apply(this, args);
                decorateOverviewLinks();
                return result;
            };
            wrapped.__uxPolished = true;
            window.loadFarmOverview = wrapped;
        }
    }

    function install() {
        removeRedundantRefreshButtons();
        moveTabFootnotes();
        installAnimalFilter();
        installOverviewNavigation();
        installMapReloadButton();
        wrapRenderFunctions();
        normalizeVehicleGrid();
        decorateOverviewLinks();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', install, { once: true });
    } else {
        install();
    }
})();
