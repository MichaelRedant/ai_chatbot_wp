(function () {
    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
            return;
        }
        callback();
    }

    function clearQueryParams() {
        if (!window.history || !window.history.replaceState || !window.URL) {
            return;
        }

        var vars = window.octopusAiAdminSettingsVars || {};
        var paramsToClear = Array.isArray(vars.queryParamsToClear) ? vars.queryParamsToClear : [];
        if (!paramsToClear.length) {
            return;
        }

        var url = new URL(window.location.href);
        var changed = false;

        paramsToClear.forEach(function (param) {
            if (url.searchParams.has(param)) {
                url.searchParams.delete(param);
                changed = true;
            }
        });

        if (!changed) {
            return;
        }

        var search = url.searchParams.toString();
        var nextUrl = url.pathname + (search ? '?' + search : '') + url.hash;
        window.history.replaceState({}, document.title, nextUrl);
    }

    function bindModelInfoToggle() {
        var link = document.getElementById('octopus-ai-toggle-model-info');
        var table = document.getElementById('model-info-table');
        if (!link || !table) {
            return;
        }

        link.setAttribute('aria-expanded', 'false');

        link.addEventListener('click', function (event) {
            event.preventDefault();
            var currentlyHidden = window.getComputedStyle(table).display === 'none';
            table.style.display = currentlyHidden ? 'block' : 'none';
            link.setAttribute('aria-expanded', currentlyHidden ? 'true' : 'false');
        });
    }

    function bindVisibilityControls() {
        var renderMode = document.getElementById('octopus_ai_render_mode');
        var displayMode = document.getElementById('octopus_ai_display_mode');
        var displayModeRow = document.getElementById('octopus_ai_display_mode_row');
        var pageRow = document.getElementById('octopus_ai_page_selector_row');

        function updateRows() {
            var isFloating = !renderMode || renderMode.value === 'floating';

            if (displayModeRow) {
                displayModeRow.style.display = isFloating ? '' : 'none';
            }

            if (!pageRow) {
                return;
            }

            if (!isFloating) {
                pageRow.style.display = 'none';
                return;
            }

            pageRow.style.display = displayMode && displayMode.value === 'selected' ? '' : 'none';
        }

        if (displayMode) {
            displayMode.addEventListener('change', updateRows);
        }

        if (renderMode) {
            renderMode.addEventListener('change', updateRows);
        }

        updateRows();
    }

    function bindSourceStrategyControls() {
        var strategyInputs = document.querySelectorAll('input[name="octopus_ai_source_strategy"]');
        if (!strategyInputs.length) {
            return;
        }

        var cards = document.querySelectorAll('.source-mode-card');
        var panels = document.querySelectorAll('.source-mode-panel[data-mode]');
        var groupedPanels = document.querySelectorAll('.source-mode-panel[data-mode-group]');
        var manualModeField = document.getElementById('octopus_ai_manual_mode_hidden');

        function activateStrategy(value) {
            cards.forEach(function (card) {
                if (card.dataset.target) {
                    card.classList.toggle('is-active', card.dataset.target === value);
                }
            });

            panels.forEach(function (panel) {
                panel.classList.toggle('is-active', panel.dataset.mode === value);
            });

            groupedPanels.forEach(function (panel) {
                var list = panel.dataset.modeGroup ? panel.dataset.modeGroup.split(',') : [];
                panel.classList.toggle('is-active', list.indexOf(value) !== -1);
            });

            if (manualModeField) {
                manualModeField.value = value === 'live_manual' ? 'live' : 'local';
            }
        }

        strategyInputs.forEach(function (input) {
            input.addEventListener('change', function () {
                activateStrategy(input.value);
            });
        });

        var initial = document.querySelector('input[name="octopus_ai_source_strategy"]:checked');
        if (initial) {
            activateStrategy(initial.value);
        }
    }

    onReady(function () {
        clearQueryParams();
        bindModelInfoToggle();
        bindVisibilityControls();
        bindSourceStrategyControls();
    });
})();
