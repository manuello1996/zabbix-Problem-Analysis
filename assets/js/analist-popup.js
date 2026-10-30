/* Each popup owns its requests, cached tabs and handlers. */
(() => {
    function initializePatterns(root, d, onLayout = () => {}) {
        const panel = root.querySelector('[data-pattern-view]');
        if (!panel) return null;
        const lifecycle = new AbortController();
        const filters = {month: null, day: null, weekday: null, hour: null};
        let daysOpen = false;
        const select = selector => panel.querySelector(selector);
        const cells = Array.from(panel.querySelectorAll('[data-pattern-slot]'));
        const filterBar = select('[data-pattern-filter]'), filterLabel = select('[data-pattern-filter-label]');
        const status = select('[data-pattern-status]'), days = select('[data-pattern-days]');
        const dayTitle = select('[data-pattern-day-title]');
        const charts = Object.fromEntries(['month', 'day', 'hour', 'weekday'].map(kind => [kind,
            Array.from(select('[data-pattern-bars="' + kind + '"]').querySelectorAll('[data-pattern-index]'), item => ({
                item, index: Number(item.dataset.patternIndex), count: item.querySelector('[data-pattern-count]'),
                bar: item.querySelector('[data-pattern-bar]'), interactive: item.hasAttribute('aria-pressed')
            }))]));
        const aggregate = () => {
            const hourly = Array(24).fill(0), weekly = Array(7).fill(0);
            const weeklyHourlyDetails = Array.from({length: 7}, () => Array(24).fill(0));
            const monthly = d.monthKeys.map(() => 0);
            const monthlyDailyDetails = d.monthDays.map(days => Array(days).fill(0));
            let total = 0;
            for (const [month, day, weekday, hour, count] of d.buckets) {
                if ((filters.month !== null && month !== filters.month) || (filters.day !== null && day !== filters.day)) continue;
                weeklyHourlyDetails[weekday][hour] += count;
                if (filters.weekday !== null && (weekday !== filters.weekday || hour !== filters.hour)) continue;
                hourly[hour] += count;
                weekly[weekday] += count;
                monthly[month] += count;
                monthlyDailyDetails[month][day - 1] += count;
                total += count;
            }
            return {hourly, weekly, weeklyHourlyDetails, monthly, monthlyDailyDetails, total};
        };
        const renderHeatmap = agg => {
            for (const cell of cells) {
                const weekday = Number(cell.dataset.weekday), hour = Number(cell.dataset.hour);
                const count = agg.weeklyHourlyDetails[weekday][hour];
                const selected = filters.weekday === weekday && filters.hour === hour;
                if (cell.textContent !== String(count)) cell.textContent = String(count);
                const intensity = count / d.scaleMax;
                cell.dataset.level = count === 0 ? '0' : intensity > 0.8 ? '3' : intensity > 0.5 ? '2' : '1';
                cell.setAttribute('aria-pressed', String(selected));
                cell.classList.toggle('is-muted', filters.weekday !== null && !selected);
                const label = d.weekLabels[weekday] + ' ' + d.hourLabels[hour] + '–'
                    + String(hour + 1).padStart(2, '0') + ':00: ' + count + ' ' + d.incidents;
                cell.title = label;
                cell.setAttribute('aria-label', label);
            }
        };
        const updateFilterBar = count => {
            const labels = [];
            if (filters.month !== null) labels.push(d.monthLabels[filters.month]);
            if (filters.day !== null) labels.push(String(filters.day));
            if (filters.weekday !== null) labels.push(d.weekLabels[filters.weekday] + ' ' + d.hourLabels[filters.hour]);
            filterBar.hidden = labels.length === 0;
            let label = d.filteredBy + ': ' + labels.join(' / ');
            if (filters.weekday !== null && d.lastWeekStart >= d.fromTs && d.lastWeekEnd <= d.toTs) {
                label += ' · ' + d.sameSlotLastWeek + ': ' + d.lastWeekCounts[filters.weekday][filters.hour];
            }
            filterLabel.textContent = label;
            status.textContent = !d.total ? d.noEvents : count === 0 ? d.noMatches
                : (labels.length ? d.selectedOccurrences : d.allOccurrences) + ': ' + count;
            status.hidden = count > 0;
        };
        const renderBars = (kind, values, labels, active = null) => {
            const max = Math.max(1, ...values);
            for (const {item, index, count: countLabel, bar, interactive} of charts[kind]) {
                item.hidden = index >= values.length;
                if (item.hidden) continue;
                const count = values[index];
                const text = count > 0 || kind === 'month' || kind === 'day' ? String(count) : '';
                if (countLabel.textContent !== text) countLabel.textContent = text;
                const height = kind === 'hour' || kind === 'weekday' ? 110 : kind === 'day' ? 80 : 100;
                bar.style.height = (count / max * height) + 'px';
                bar.classList.toggle('is-empty', count === 0);
                item.title = labels[index] + ': ' + count + ' ' + d.incidents;
                if (interactive) {
                    item.setAttribute('aria-pressed', String(index === active));
                    item.setAttribute('aria-label', item.title);
                }
            }
        };
        const render = () => {
            const agg = aggregate();
            // Keep the selected date's heatmap visible as context while a slot filters the distributions.
            renderHeatmap(agg);
            renderBars('month', agg.monthly, d.monthLabels, filters.month);
            renderBars('hour', agg.hourly, d.hourLabels);
            renderBars('weekday', agg.weekly, d.weekLabels);
            days.hidden = filters.month === null || !daysOpen;
            if (filters.month !== null) {
                dayTitle.textContent = d.monthLabels[filters.month];
                renderBars('day', agg.monthlyDailyDetails[filters.month],
                    Array.from({length: d.monthDays[filters.month]}, (_, i) => i + 1),
                    filters.day === null ? null : filters.day - 1);
            }
            updateFilterBar(agg.total);
            onLayout();
        };
        panel.addEventListener('click', event => {
            const button = event.target.closest('[data-pattern-slot], [data-pattern-month], [data-pattern-day], [data-pattern-clear], [data-pattern-toggle], [data-pattern-hide-days]');
            if (!button || !panel.contains(button)) return;
            event.preventDefault();
            if (button.hasAttribute('data-pattern-toggle')) {
                const body = select('[data-pattern-body="' + button.dataset.patternToggle + '"]');
                body.hidden = !body.hidden;
                button.setAttribute('aria-expanded', String(!body.hidden));
                button.textContent = body.hidden ? d.expandLabel : d.collapseLabel;
                onLayout();
                return;
            } else if (button.hasAttribute('data-pattern-hide-days')) {
                daysOpen = false;
            } else if (button.hasAttribute('data-pattern-clear')) {
                Object.assign(filters, {month: null, day: null, weekday: null, hour: null});
                daysOpen = false;
            } else if (button.hasAttribute('data-pattern-month')) {
                const month = Number(button.dataset.patternMonth);
                filters.month = filters.month === month ? null : month;
                filters.day = null;
                daysOpen = filters.month !== null;
            } else if (button.hasAttribute('data-pattern-day')) {
                const day = Number(button.dataset.patternDay) + 1;
                filters.day = filters.day === day ? null : day;
            } else {
                const weekday = Number(button.dataset.weekday), hour = Number(button.dataset.hour);
                const selected = filters.weekday === weekday && filters.hour === hour;
                filters.weekday = selected ? null : weekday;
                filters.hour = selected ? null : hour;
                cells.forEach(cell => { cell.tabIndex = cell === button ? 0 : -1; });
            }
            render();
        }, {signal: lifecycle.signal});
        panel.addEventListener('keydown', event => {
            const cell = event.target.closest('[data-pattern-slot]');
            if (!cell) return;
            const index = cells.indexOf(cell), column = index % 24;
            let next;
            switch (event.key) {
                case 'ArrowRight': next = index - column + (column + 1) % 24; break;
                case 'ArrowLeft': next = index - column + (column + 23) % 24; break;
                case 'ArrowDown': next = (index + 24) % cells.length; break;
                case 'ArrowUp': next = (index + cells.length - 24) % cells.length; break;
                case 'Home': next = index - column; break;
                case 'End': next = index - column + 23; break;
                default: return;
            }
            event.preventDefault();
            cells.forEach((button, i) => { button.tabIndex = i === next ? 0 : -1; });
            cells[next].focus();
        }, {signal: lifecycle.signal});
        render();
        return {redraw: render, destroy: () => lifecycle.abort()};
    }

    function createServiceUi(popup) {
        const root = popup.root;
        const serviceStatusLabels = popup.config.statusLabels;
        const slaLabels = popup.config.slaLabels;
        const labels = popup.config.serviceLabels;
        let currentServices = [];
        function escapeServiceHtml(value) {
            return String(value ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;")
                .replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/\x27/g, "&#39;");
        }

        function displayServicesTree(services) {
            const tree = root.querySelector('#services-tree-container');
            if (!tree) return;
            currentServices = services;
            const matchedIds = new Set(services.map(service => String(service.serviceid)));
            const relatedServices = new Map();
            services.forEach(service => (service.hierarchy_path || []).forEach(parent => {
                const id = String(parent.serviceid);
                if (!matchedIds.has(id) && !relatedServices.has(id)) relatedServices.set(id, parent);
            }));
            const relatedHtml = relatedServices.size
                ? '<details class="analist-related-services" data-services-related><summary>'
                    + escapeServiceHtml(labels.related) + ' (' + relatedServices.size + ')</summary>'
                    + '<div class="analist-services-list">'
                    + Array.from(relatedServices.values(), service => createServiceCard(service)).join('')
                    + '</div></details>' : '';
            tree.innerHTML = '<div class="services-summary-header"><h4>' + escapeServiceHtml(labels.matching)
                + ' (' + services.length + ')</h4><p>' + escapeServiceHtml(labels.current) + '</p></div>'
                + '<div class="analist-services-toolbar"><label>' + escapeServiceHtml(labels.search)
                + '<input type="search" data-services-search autocomplete="off"></label></div>'
                + '<div class="analist-services-list" data-services-matching>' + services.map(service => createServiceCard(service, true)).join('') + '</div>'
                + '<p data-services-search-empty role="status" hidden>' + escapeServiceHtml(labels.empty) + '</p>'
                + relatedHtml;
        }

        function filterServices(query) {
            const normalized = query.trim().toLocaleLowerCase();
            const ids = new Set(currentServices.filter(service =>
                [service.name, ...(service.hierarchy_path || []).map(parent => parent.name)]
                    .some(name => String(name || '').toLocaleLowerCase().includes(normalized)))
                .map(service => String(service.serviceid)));
            root.querySelectorAll('[data-services-matching] .service-card').forEach(card => {
                card.hidden = !ids.has(card.dataset.serviceid);
            });
            const empty = root.querySelector('[data-services-search-empty]');
            if (empty) empty.hidden = ids.size > 0;
        }

        function createHierarchyPath(path) {
            return '<div class="services-hierarchy-path"><span class="hierarchy-label">' + escapeServiceHtml(labels.hierarchy) + ':</span>'
                + path.map(service => '<button type="button" class="btn-link hierarchy-service" data-service-action="'
                    + escapeServiceHtml(service.serviceid) + '">' + escapeServiceHtml(service.name) + '</button>')
                    .join('<span class="hierarchy-arrow"> → </span>') + '</div>';
        }

        function createServiceSlaInfo(service) {
            const state = service.sla_state || (service.has_sla === false ? 'none'
                : service.has_sla === true ? 'configured' : 'unavailable');
            if (state === 'none') {
                return '<div class="service-no-sla">' + escapeServiceHtml(slaLabels.none) + '</div>';
            }
            if (state !== 'configured') {
                return '<div class="service-no-sla">' + escapeServiceHtml(slaLabels.unknown) + '</div>';
            }
            const slas = Array.isArray(service.slas) && service.slas.length ? service.slas : [{
                name: service.sla_name, slo: service.sla_info?.target?.replace(/%$/, ''),
                sli: service.sli, uptime: service.uptime, downtime: service.downtime, error_budget: service.error_budget
            }];
            return '<div class="service-sla-info">' + slas.map(sla => {
                const sli = Number(sla.sli);
                const available = sla.sli !== null && sla.sli !== undefined && sla.sli !== ''
                    && Number.isFinite(sli) && sli >= 0 && sli <= 100
                    && (!sla.sli_state || sla.sli_state === 'available');
                const rows = [];
                if (sla.name !== undefined && sla.name !== null) rows.push([slaLabels.sla, sla.name]);
                if (sla.slo !== undefined && sla.slo !== null) rows.push([slaLabels.target, String(sla.slo) + '%']);
                const period = sla.reporting_period;
                if (period && Number.isFinite(Number(period.period_from)) && Number.isFinite(Number(period.period_to))) {
                    const format = timestamp => new Date(Number(timestamp) * 1000).toISOString().slice(0, 16).replace('T', ' ');
                    rows.push([slaLabels.period, format(period.period_from) + ' – ' + format(period.period_to)]);
                }
                const target = Number(sla.slo);
                const comparison = available && sla.slo !== undefined && sla.slo !== null && Number.isFinite(target)
                    ? ' (' + (sli >= target ? slaLabels.met : slaLabels.below) + ')' : '';
                rows.push([slaLabels.sli, available ? sli.toFixed(2) + '%' + comparison
                    : sla.sli_state === 'disabled' ? slaLabels.disabled : slaLabels.unavailable]);
                if (available) rows.push([slaLabels.uptime, sla.uptime ?? 'N/A'],
                    [slaLabels.downtime, sla.downtime ?? 'N/A'], [slaLabels.budget, sla.error_budget ?? 'N/A']);
                return '<div class="service-sla-entry"><table class="list-table analist-sla-table"><tbody>' + rows.map(row =>
                    '<tr><th scope="row">' + escapeServiceHtml(row[0])
                    + '</th><td>' + escapeServiceHtml(row[1]) + '</td></tr>'
                ).join('') + '</tbody></table></div>';
            }).join('') + '</div>';
        }

        function serviceSlaSummary(service) {
            return service.slas?.length ? service.slas.length + ' ' + labels.slas
                : service.has_sla === false ? slaLabels.none : slaLabels.unknown;
        }

        function createServiceCard(service, isImpacted = false) {
            const id = escapeServiceHtml(service.serviceid);
            const path = service.hierarchy_path || [];
            const rules = isImpacted && service.problem_tags?.length
                ? '<div class="analist-service-rules"><table class="list-table analist-service-rules-table">'
                    + '<caption>' + escapeServiceHtml(labels.rules) + '</caption>'
                    + '<thead><tr><th scope="col">' + escapeServiceHtml(labels.tag) + '</th>'
                    + '<th scope="col">' + escapeServiceHtml(labels.operator) + '</th>'
                    + '<th scope="col">' + escapeServiceHtml(labels.value) + '</th></tr></thead><tbody>'
                    + service.problem_tags.map(rule => '<tr><td>' + escapeServiceHtml(rule.tag) + '</td><td>'
                        + escapeServiceHtml(Number(rule.operator) === labels.containsOperator ? labels.contains : labels.equals)
                        + '</td><td>' + escapeServiceHtml(rule.value) + '</td></tr>').join('') + '</tbody></table></div>' : '';
            return '<details class="service-card' + (isImpacted ? ' service-card-impacted' : '')
                + '" data-serviceid="' + id + '"><summary class="service-header"><span class="service-name">'
                + escapeServiceHtml(service.name) + '</span><span class="analist-service-summary">'
                + '<span class="analist-service-match">' + escapeServiceHtml(isImpacted ? labels.matched : labels.context) + '</span>'
                + '<span class="analist-service-match" data-service-sla-summary>' + escapeServiceHtml(serviceSlaSummary(service)) + '</span>'
                + '<span class="service-status ' + getServiceStatusClass(service.status) + '">'
                + escapeServiceHtml(getServiceStatusText(service.status)) + '</span></span></summary>'
                + '<div class="analist-service-details">' + (path.length > 1 ? createHierarchyPath(path) : '')
                + rules + '<h5 class="analist-service-details-heading">' + escapeServiceHtml(labels.details) + '</h5>'
                + createServiceSlaInfo(service) + '</div></details>';
        }

        function getServiceStatusClass(status) {
            return popup.config.statusStyles[status] || "status-grey";
        }

        function getServiceStatusText(status) {
            return serviceStatusLabels[status] || "Unknown";
        }

        function findServiceCard(serviceid) {
            const cards = root.querySelectorAll("#services-tree-container .service-card[data-serviceid]");
            return Array.from(cards).find(card => card.dataset.serviceid === String(serviceid));
        }

        function selectService(serviceid) {
            const tree = root.querySelector("#services-tree-container");
            if (!tree) return;
            tree.querySelectorAll(".service-card").forEach(card => {
                card.classList.remove("is-selected");
            });
            const selectedCard = findServiceCard(serviceid);
            if (selectedCard) {
                const related = selectedCard.closest("[data-services-related]");
                if (related) related.open = true;
                selectedCard.open = true;
                selectedCard.hidden = false;
                selectedCard.classList.add("is-selected");
                selectedCard.scrollIntoView({behavior: "smooth", block: "nearest"});
                popup.scheduleLayout();
            }
        }

        function displayNoServicesMessage() {
            const tree = root.querySelector("#services-tree-container");
            if (!tree) return;
            tree.innerHTML = '<div class="services-no-results"><p><strong>' + escapeServiceHtml(labels.none) + '</strong></p>'
                + '<p>' + escapeServiceHtml(labels.explanation) + '</p></div>';
        }

        return {displayServicesTree, displayNoServicesMessage, selectService, filterServices};
    }

    class AnalistProblemPopup {
        static instances = new Set();

        static mount(config) {
            const root = document.getElementById(config.rootId);
            if (!root) return;
            for (const instance of this.instances) {
                if (instance.root === root || !instance.root.isConnected) instance.destroy();
            }
            const instance = new this(root, config);
            this.instances.add(instance);
            return instance;
        }

        static cleanupDetached() {
            for (const instance of this.instances) {
                if (!instance.root.isConnected) instance.destroy();
            }
        }

        constructor(root, config) {
            this.root = root;
            this.config = config;
            this.closed = false;
            this.requests = new Set();
            this.lifecycle = new AbortController();
            this.tabs = new Map();
            this.panels = new Map(Array.from(root.querySelectorAll('[data-tab]'), panel => [panel.dataset.tab, panel]));
            this.tabs.set('overview', {loaded: true, promise: null});
            this.serviceUi = createServiceUi(this);
            this.patternRenderer = null;
            this.updatingProblem = false;
            this.layoutFrame = null;
            this.recoveryTimer = null;
            this.waitingForRecovery = false;
            this.recoveryDelay = 1000;
            this.dataVersion = 0;
            this.refreshPromise = null;
            this.historyRequests = new Map();
            this.graphOptions = {};
            this.graphRequest = null;
            this.patternOptions = {};
            this.patternRequest = null;
            this.timelineRequest = null;
            root.addEventListener('click', event => this.onClick(event), {signal: this.lifecycle.signal});
            root.addEventListener('keydown', event => this.onKeyDown(event), {signal: this.lifecycle.signal});
            root.addEventListener('input', event => {
                if (event.target.matches('[data-services-search]')) this.serviceUi.filterServices(event.target.value);
            }, {signal: this.lifecycle.signal});
            root.addEventListener('change', event => {
                if (event.target.matches('[data-pattern-period]')) {
                    const form = event.target.closest('[data-pattern-controls]');
                    const custom = event.target.value === 'custom';
                    form.querySelector('[data-pattern-custom-range]').hidden = !custom;
                    form.querySelector('[type="submit"]').hidden = !custom;
                    this.scheduleLayout();
                    if (!custom) this.updatePatterns(form);
                    return;
                }
                if (!event.target.matches('[data-graph-period], [data-graph-layout]')) return;
                const form = event.target.closest('[data-graph-controls]');
                if (!form) return;
                const custom = form.querySelector('[name="graph_period"]').value === 'custom';
                const range = form.querySelector('[data-graph-custom-range]');
                if (range) range.hidden = !custom;
                form.querySelector('[type="submit"]').hidden = !custom;
                this.scheduleLayout();
                if (!custom || event.target.matches('[data-graph-layout]')) this.updateGraphs(form);
            }, {signal: this.lifecycle.signal});
            root.addEventListener('submit', event => {
                if (event.target.matches('[data-pattern-controls]')) {
                    event.preventDefault();
                    event.stopPropagation();
                    this.updatePatterns(event.target);
                    return;
                }
                if (!event.target.matches('[data-graph-controls]')) return;
                event.preventDefault();
                event.stopPropagation();
                this.updateGraphs(event.target);
            }, {signal: this.lifecycle.signal});
            root.addEventListener('toggle', event => {
                if (event.target.matches('details.service-card, details.analist-related-services')) this.scheduleLayout();
            }, {capture: true, signal: this.lifecycle.signal});
            // Image sizing changes do not trigger Zabbix 7.0's content mutation observer.
            const imageSettled = event => {
                if (event.target.matches('img.chart-image')) this.scheduleLayout();
            };
            root.addEventListener('load', imageSettled, {capture: true, signal: this.lifecycle.signal});
            root.addEventListener('error', imageSettled, {capture: true, signal: this.lifecycle.signal});
            this.dialogue = root.closest('[data-dialogueid]');
            this.dialogue?.classList.add('analist-problem-dialogue');
            const closeButton = this.dialogue?.querySelector('.btn-overlay-close');
            if (closeButton) {
                this.refreshButton = document.createElement('button');
                this.refreshButton.type = 'button';
                this.refreshButton.className = 'btn-alt analist-popup-refresh';
                this.refreshButton.textContent = config.refreshLabel;
                this.refreshButton.addEventListener('click', event => {
                    event.preventDefault();
                    this.refreshTab();
                }, {signal: this.lifecycle.signal});
                closeButton.before(this.refreshButton);
            }
            const cancelButton = this.dialogue?.querySelector('.overlay-dialogue-footer .js-cancel');
            if (cancelButton) {
                this.commentButton = document.createElement('button');
                this.commentButton.type = 'button';
                this.commentButton.className = 'btn-alt analist-popup-comment';
                this.commentButton.textContent = config.addCommentLabel;
                this.commentButton.addEventListener('click', event => {
                    event.preventDefault();
                    this.openCommentForm();
                }, {signal: this.lifecycle.signal});
                cancelButton.before(this.commentButton);
            }
            this.dialogue?.addEventListener('dialogue.close', () => this.destroy(),
                {once: true, signal: this.lifecycle.signal});
            this.activateTab(config.initialTab === 'timeline' ? 'timeline' : 'overview');
            this.trackPendingRecovery();
        }

        syncCommentButton() {
            if (!this.commentButton || this.closed) return;
            const form = this.root.querySelector('[data-comment-form]');
            this.commentButton.hidden = !this.config.problemActions?.canComment;
            this.commentButton.disabled = this.updatingProblem || this.refreshPromise !== null;
            this.commentButton.setAttribute('aria-expanded', String(Boolean(form && !form.hidden && this.activeTab === 'update')));
            if (form) this.commentButton.setAttribute('aria-controls', form.id);
            else this.commentButton.removeAttribute('aria-controls');
        }

        async openCommentForm() {
            if (this.closed || this.updatingProblem || this.refreshPromise || !this.config.problemActions?.canComment) return;
            await this.activateTab('update');
            if (this.closed || this.activeTab !== 'update' || !this.config.problemActions.canComment
                    || !this.tabs.get('update')?.loaded) return;
            const form = this.root.querySelector('[data-comment-form]');
            if (!form) return;
            form.hidden = false;
            this.syncCommentButton();
            this.root.querySelector('[data-problem-comment]')?.focus();
            this.scheduleLayout();
        }

        trackPendingRecovery() {
            if (this.closed || !this.config.problemActions?.closingPending
                    || this.config.problemActions.recoveryReady) return;
            this.waitingForRecovery = true;
            const panel = this.panels.get('overview');
            const status = panel.querySelector('[data-problem-status]');
            if (status) {
                status.textContent = this.config.problemActions.closingStatusLabel;
                status.className = 'color-warning';
            }
            if (!panel.querySelector('[data-recovery-row]')) {
                panel.querySelector('[data-age-row]')?.remove();
                panel.querySelector('.analist-problem-detail-table tbody')
                    ?.insertAdjacentHTML('beforeend', this.config.problemActions.pendingRecoveryRow);
            }
            const message = this.root.querySelector('[data-problem-action-message]');
            if (message) message.textContent = this.config.problemActions.closingLabel;
            this.scheduleLayout();
            this.scheduleRecoveryRefresh();
        }

        onClick(event) {
            const button = event.target.closest('[data-tab-target], [data-retry-tab], [data-service-action], [data-problem-action], [data-history-more], [data-timeline-more]');
            if (!button || !this.root.contains(button)) return;
            if (button.dataset.tabTarget) {
                event.preventDefault();
                this.activateTab(button.dataset.tabTarget);
            } else if (button.dataset.retryTab) {
                event.preventDefault();
                this.loadTab(button.dataset.retryTab);
            } else if (button.dataset.serviceAction) {
                event.preventDefault();
                this.serviceUi.selectService(button.dataset.serviceAction);
            } else if (button.hasAttribute('data-history-more')) {
                event.preventDefault();
                this.loadMoreHistory(button);
            } else if (button.hasAttribute('data-timeline-more')) {
                event.preventDefault();
                this.loadMoreEvents(button);
            } else if (button.dataset.problemAction) {
                event.preventDefault();
                this.updateProblem(button.dataset.problemAction);
            }
        }

        onKeyDown(event) {
            if (!event.target.matches('[data-tab-target]')) return;
            const buttons = Array.from(this.root.querySelectorAll('[data-tab-target]'));
            const index = buttons.indexOf(event.target);
            let next;
            switch (event.key) {
                case 'ArrowRight': next = (index + 1) % buttons.length; break;
                case 'ArrowLeft': next = (index + buttons.length - 1) % buttons.length; break;
                case 'Home': next = 0; break;
                case 'End': next = buttons.length - 1; break;
                default: return;
            }
            event.preventDefault();
            buttons[next].focus();
            this.activateTab(buttons[next].dataset.tabTarget);
        }

        activateTab(key) {
            if (this.closed || !this.panels.has(key)) return;
            this.root.querySelectorAll('[data-tab-target]').forEach(button => {
                const active = button.dataset.tabTarget === key;
                button.setAttribute('aria-selected', String(active));
                button.tabIndex = active ? 0 : -1;
                button.parentElement.classList.toggle('ui-tabs-active', active);
                button.parentElement.classList.toggle('ui-state-active', active);
            });
            for (const [name, panel] of this.panels) {
                panel.style.display = name === key ? 'block' : 'none';
                panel.setAttribute('aria-hidden', String(name !== key));
            }
            this.activeTab = key;
            this.syncCommentButton();
            this.scheduleLayout();
            return this.loadTab(key);
        }

        scheduleLayout() {
            if (this.closed || this.layoutFrame !== null) return;
            this.layoutFrame = window.requestAnimationFrame(() => {
                this.layoutFrame = null;
                if (this.closed || !this.root.isConnected || !this.dialogue) return;
                const overlay = window.overlays_stack?.getById(this.dialogue.dataset.dialogueid);
                if (overlay?.$dialogue?.[0] === this.dialogue) overlay.centerDialog?.();
            });
        }

        invalidateTabs() {
            this.dataVersion++;
            this.timelineRequest = null;
            if (this.activeTab !== 'graphs') this.graphOptions = {};
            if (this.activeTab !== 'patterns') this.patternOptions = {};
            this.patternRequest = null;
            this.graphRequest = null;
            if (this.recoveryTimer !== null) window.clearTimeout(this.recoveryTimer);
            this.recoveryTimer = null;
            for (const request of this.requests) request.abort();
            this.historyRequests.clear();
            this.tabs.clear();
            this.patternRenderer?.destroy();
            this.patternRenderer = null;
            for (const panel of this.panels.values()) {
                panel.replaceChildren();
                panel.removeAttribute('aria-busy');
            }
            this.serviceUi = createServiceUi(this);
            this.syncCommentButton();
        }

        refreshTab() {
            if (this.closed || this.updatingProblem) return Promise.resolve();
            if (this.refreshPromise) return this.refreshPromise;
            const key = this.activeTab;
            this.invalidateTabs();
            if (this.refreshButton) this.refreshButton.disabled = true;
            this.refreshPromise = this.loadTab(key).finally(() => {
                this.refreshPromise = null;
                if (!this.closed && this.refreshButton) this.refreshButton.disabled = false;
                this.syncCommentButton();
            });
            this.syncCommentButton();
            return this.refreshPromise;
        }

        loadTab(key) {
            if (this.closed || !this.panels.has(key)) return Promise.resolve();
            let state = this.tabs.get(key);
            if (!state) {
                state = {loaded: false, promise: null};
                this.tabs.set(key, state);
            }
            if (state.loaded) return Promise.resolve();
            if (state.promise) return state.promise;
            const panel = this.panels.get(key);
            panel.textContent = this.config.loadingLabel;
            panel.setAttribute('aria-busy', 'true');
            const action = key === 'services' ? 'problemanalist.service.get' : 'problemanalist.tab.get';
            const params = {eventid: this.config.eventData.eventid};
            if (key !== 'services') {
                params.tab = key;
                if (this.config.eventData.hostid) params.hostid = this.config.eventData.hostid;
            }
            if (key === 'graphs') Object.assign(params, this.graphOptions);
            if (key === 'patterns') Object.assign(params, this.patternOptions);
            state.promise = this.fetchJson(action, params).then(result => {
                if (this.closed || this.tabs.get(key) !== state) return;
                this.renderTab(key, result);
                state.loaded = true;
                this.scheduleLayout();
            }).catch(error => {
                if (!this.closed && this.tabs.get(key) === state && error.name !== 'AbortError') {
                    this.showError(panel, key, error);
                }
            }).finally(() => {
                state.promise = null;
                if (!this.closed && this.tabs.get(key) === state) {
                    panel.removeAttribute('aria-busy');
                    this.syncCommentButton();
                    this.scheduleLayout();
                }
            });
            return state.promise;
        }

        renderTab(key, result) {
            const panel = this.panels.get(key);
            if (key === 'services') {
                if (!result.success || !Array.isArray(result.data)) throw new Error(result.error?.message || 'Unable to load services');
                panel.innerHTML = '<div id="services-tree-container" class="services-container"></div>';
                if (result.data.length) this.serviceUi.displayServicesTree(result.data);
                else this.serviceUi.displayNoServicesMessage();
                return;
            }
            if (typeof result.body !== 'string') throw new Error('Invalid tab response');
            if (key === 'patterns' && !Array.isArray(result.pattern_data?.buckets)) throw new Error('Invalid pattern response');
            if (key === 'patterns') this.patternRenderer?.destroy();
            panel.innerHTML = result.body;
            if (key === 'update' && result.comment_actions) Object.assign(this.config.problemActions, result.comment_actions);
            if (key === 'overview' && result.problem_actions) {
                if (this.waitingForRecovery && !result.problem_actions.recoveryReady) result.problem_actions.closingPending = true;
                this.config.problemActions = result.problem_actions;
                if (result.problem_actions.recoveryReady) {
                    this.waitingForRecovery = false;
                    if (this.recoveryTimer !== null) window.clearTimeout(this.recoveryTimer);
                    this.recoveryTimer = null;
                } else {
                    this.trackPendingRecovery();
                }
            }
            if (key === 'patterns') this.patternRenderer = initializePatterns(this.root, result.pattern_data, () => this.scheduleLayout());
        }

        async fetchJson(action, params) {
            if (this.closed) throw new DOMException('Popup closed', 'AbortError');
            const controller = new AbortController();
            this.requests.add(controller);
            try {
                const response = await fetch('zabbix.php?action=' + encodeURIComponent(action), {
                    method: 'POST', body: new URLSearchParams(params), signal: controller.signal, cache: 'no-store'
                });
                if (!response.ok) throw new Error('HTTP error! status: ' + response.status);
                const result = await response.json();
                if (this.closed || controller.signal.aborted) throw new DOMException('Request cancelled', 'AbortError');
                if (result.error) throw new Error(result.error.message || result.error.messages?.join(' ') || 'Unable to load tab');
                return result;
            } finally {
                this.requests.delete(controller);
            }
        }

        updatePatterns(form) { return this.updatePeriod(form, 'patterns', 'pattern'); }
        updateGraphs(form) { return this.updatePeriod(form, 'graphs', 'graph'); }

        updatePeriod(form, key, prefix) {
            if (this.closed || !this.root.contains(form)) return Promise.resolve();
            const requestKey = prefix + 'Request';
            if (this[requestKey]) return this[requestKey];
            const period = form.querySelector('[name="' + prefix + '_period"]');
            const layout = key === 'graphs' ? form.querySelector('[name="graph_layout"]') : null;
            const options = {[prefix + '_period']: period.value};
            if (key === 'graphs') options.graph_layout = layout?.value ?? 'items';
            if (period.value === 'custom') {
                options[prefix + '_from'] = form.querySelector('[data-' + prefix + '-from] input').value;
                options[prefix + '_to'] = form.querySelector('[data-' + prefix + '-to] input').value;
            }
            const version = this.dataVersion, state = this.tabs.get(key);
            const submit = form.querySelector('[type="submit"]');
            const message = form.querySelector('[data-' + prefix + '-message]');
            const selects = [period, layout].filter(Boolean);
            const disabledStates = selects.map(select => select.disabled);
            message.textContent = '';
            submit.disabled = true;
            selects.forEach(select => { select.disabled = true; });
            form.setAttribute('aria-busy', 'true');
            const request = this.fetchJson('problemanalist.tab.get', {
                eventid: this.config.eventData.eventid, tab: key, ...options,
                ...(this.config.eventData.hostid ? {hostid: this.config.eventData.hostid} : {})
            }).then(result => {
                if (this.closed || version !== this.dataVersion || this.tabs.get(key) !== state) return;
                this.renderTab(key, result);
                this[prefix + 'Options'] = options;
                this.scheduleLayout();
            }).catch(error => {
                if (!this.closed && version === this.dataVersion && error.name !== 'AbortError'
                        && this.root.contains(form)) message.textContent = error.message;
            }).finally(() => {
                if (this[requestKey] === request) this[requestKey] = null;
                if (!this.closed && version === this.dataVersion && this.root.contains(form)) {
                    submit.disabled = false;
                    selects.forEach((select, index) => { select.disabled = disabledStates[index]; });
                    form.removeAttribute('aria-busy');
                    this.scheduleLayout();
                }
            });
            this[requestKey] = request;
            return request;
        }

        loadMoreEvents(button) {
            const section = button.closest('[data-timeline-events]');
            if (this.closed || !section || !this.root.contains(section)) return Promise.resolve();
            if (this.timelineRequest) return this.timelineRequest;
            const rows = section.querySelector('[data-timeline-table] tbody');
            const message = section.querySelector('[data-timeline-message]');
            const version = this.dataVersion;
            button.disabled = true;
            section.setAttribute('aria-busy', 'true');
            message.textContent = '';
            const request = this.fetchJson('problemanalist.tab.get', {
                eventid: this.config.eventData.eventid, tab: 'timeline_events',
                before_eventid: button.dataset.beforeEventid, before_clock: button.dataset.beforeClock
            }).then(result => {
                if (this.closed || version !== this.dataVersion || !this.root.contains(section)) return;
                if (typeof result.body !== 'string' || typeof result.has_more !== 'boolean'
                        || (result.has_more && !result.cursor)) throw new Error('Invalid recent events response');
                rows.insertAdjacentHTML('beforeend', result.body);
                if (result.cursor) {
                    button.dataset.beforeEventid = result.cursor.eventid;
                    button.dataset.beforeClock = result.cursor.clock;
                }
                button.hidden = !result.has_more;
                if (!result.has_more) message.textContent = this.config.noMoreEventsLabel;
                this.scheduleLayout();
            }).catch(error => {
                if (!this.closed && version === this.dataVersion && this.root.contains(section) && error.name !== 'AbortError') {
                    message.textContent = error.message;
                }
            }).finally(() => {
                if (this.timelineRequest === request) this.timelineRequest = null;
                if (!this.closed && version === this.dataVersion && this.root.contains(section)) {
                    button.disabled = false;
                    section.removeAttribute('aria-busy');
                    this.scheduleLayout();
                }
            });
            this.timelineRequest = request;
            return request;
        }

        loadMoreHistory(button) {
            const row = button.closest('[data-history-item]');
            if (this.closed || !row || !this.root.contains(row)) return Promise.resolve();
            const itemid = row.dataset.historyItem;
            if (this.historyRequests.has(row)) return this.historyRequests.get(row);
            const values = row.querySelector('[data-history-values]');
            const message = row.querySelector('[data-history-message]');
            const version = this.dataVersion;
            button.disabled = true;
            row.setAttribute('aria-busy', 'true');
            if (message) message.textContent = '';
            const request = this.fetchJson('problemanalist.tab.get', {
                eventid: this.config.eventData.eventid, tab: 'history', itemid,
                before_clock: button.dataset.beforeClock, before_ns: button.dataset.beforeNs
            }).then(result => {
                if (this.closed || version !== this.dataVersion || !this.root.contains(row)) return;
                if (typeof result.body !== 'string' || typeof result.has_more !== 'boolean'
                        || (result.has_more && !result.cursor)) throw new Error('Invalid history response');
                values.insertAdjacentHTML('beforeend', result.body);
                if (result.cursor) {
                    button.dataset.beforeClock = result.cursor.clock;
                    button.dataset.beforeNs = result.cursor.ns;
                }
                button.hidden = !result.has_more;
                if (!result.body && message) message.textContent = this.config.noMoreHistoryLabel;
                this.scheduleLayout();
            }).catch(error => {
                if (!this.closed && version === this.dataVersion && this.root.contains(row)
                        && error.name !== 'AbortError' && message) {
                    message.textContent = error.message;
                }
            }).finally(() => {
                if (this.historyRequests.get(row) === request) this.historyRequests.delete(row);
                if (!this.closed && version === this.dataVersion && this.root.contains(row)) {
                    button.disabled = false;
                    row.removeAttribute('aria-busy');
                    this.scheduleLayout();
                }
            });
            this.historyRequests.set(row, request);
            return request;
        }

        showError(panel, key, error) {
            panel.textContent = '';
            const message = document.createElement('p');
            message.setAttribute('role', 'alert');
            message.textContent = error.message || String(error);
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'btn-alt';
            retry.dataset.retryTab = key;
            retry.textContent = this.config.retryLabel;
            panel.append(message, retry);
        }

        async updateProblem(action) {
            const actions = this.config.problemActions;
            if (this.closed || this.updatingProblem || this.refreshPromise || !actions) return;
            const commenting = action === 'comment';
            const comment = commenting ? this.root.querySelector('[data-problem-comment]') : null;
            const messageSelector = commenting ? '[data-comment-message]' : '[data-problem-action-message]';
            const message = this.root.querySelector(messageSelector);
            const fields = {
                acknowledge: ['acknowledge_problem', actions.acknowledgeAction],
                unacknowledge: ['unacknowledge_problem', actions.unacknowledgeAction],
                close: ['close_problem', actions.closeAction],
                'severity-up': ['change_severity', actions.severityAction],
                'severity-down': ['change_severity', actions.severityAction],
                comment: ['message', comment?.value]
            };
            const changingSeverity = action === 'severity-up' || action === 'severity-down';
            if (!fields[action] || !(commenting ? actions.canComment : changingSeverity ? actions.canChangeSeverity
                    : action === 'close' ? actions.canClose : actions.canAcknowledge)) return;
            if (commenting && !comment?.value.trim()) {
                if (message) message.textContent = actions.emptyCommentLabel;
                comment?.focus();
                return;
            }
            const nextSeverity = Number(actions.severity) + (action === 'severity-up' ? 1 : -1);
            if (changingSeverity && (!Number.isInteger(nextSeverity)
                    || nextSeverity < actions.minSeverity || nextSeverity > actions.maxSeverity)) return;
            this.updatingProblem = true;
            this.syncCommentButton();
            if (this.refreshButton) this.refreshButton.disabled = true;
            if (comment) comment.disabled = true;
            const buttons = Array.from(this.root.querySelectorAll('[data-problem-action]'));
            const disabledStates = buttons.map(button => button.disabled);
            buttons.forEach(button => { button.disabled = true; });
            if (message) message.textContent = '';
            let updated = false;
            try {
                const [field, value] = fields[action];
                const result = await this.fetchJson('popup.acknowledge.create', {
                    'eventids[]': this.config.eventData.eventid,
                    scope: actions.selectedScope,
                    [actions.csrfTokenName]: actions.csrfToken,
                    [field]: value,
                    ...(changingSeverity ? {severity: nextSeverity} : {})
                });
                if (!result.success) throw new Error('Unable to update event');
                updated = true;
                if (action === 'close') {
                    this.config.problemActions.closingPending = true;
                    this.trackPendingRecovery();
                }
                // Discard stale panels and fetch only the tab needed after the update.
                this.invalidateTabs();
                const targetTab = commenting ? 'update' : 'overview';
                await this.activateTab(targetTab);
                const refreshedMessage = this.root.querySelector(messageSelector);
                if (refreshedMessage) refreshedMessage.textContent = commenting ? actions.commentAddedLabel : actions.updatedLabel;
                if (action === 'close' && !this.config.problemActions.recoveryReady) {
                    this.config.problemActions.closingPending = true;
                    this.trackPendingRecovery();
                }
                if (!this.tabs.get(targetTab)?.loaded && !this.closed) {
                    const panel = this.panels.get(targetTab);
                    panel.querySelector('[role="alert"]')?.replaceChildren(actions.refreshErrorLabel);
                }
                if (window.jQuery) window.jQuery.publish?.('acknowledge.create', [result]);
            } catch (error) {
                if (!this.closed && error.name !== 'AbortError' && message) message.textContent = error.message;
            } finally {
                this.updatingProblem = false;
                this.syncCommentButton();
                if (!this.closed && this.refreshButton) this.refreshButton.disabled = false;
                if (!this.closed && comment) comment.disabled = false;
                if (!updated && !this.closed) buttons.forEach((button, index) => { button.disabled = disabledStates[index]; });
            }
        }

        scheduleRecoveryRefresh() {
            if (this.closed || !this.waitingForRecovery || this.recoveryTimer !== null || !this.tabs.has('overview')) return;
            const version = this.dataVersion;
            this.recoveryTimer = window.setTimeout(async () => {
                this.recoveryTimer = null;
                if (this.closed) return;
                try {
                    const params = {eventid: this.config.eventData.eventid, tab: 'overview'};
                    if (this.config.eventData.hostid) params.hostid = this.config.eventData.hostid;
                    const result = await this.fetchJson('problemanalist.tab.get', params);
                    if (version !== this.dataVersion) return;
                    if (typeof result.body !== 'string' || !result.problem_actions) throw new Error('Invalid overview response');
                    // Refresh the existing panel without changing the tab the user is viewing.
                    this.panels.get('overview').innerHTML = result.body;
                    this.config.problemActions = result.problem_actions;
                    this.syncCommentButton();
                    this.tabs.set('overview', {loaded: true, promise: null});
                    this.waitingForRecovery = !result.problem_actions.recoveryReady;
                    if (this.waitingForRecovery) {
                        this.config.problemActions.closingPending = true;
                        this.trackPendingRecovery();
                    }
                    const message = this.root.querySelector('[data-problem-action-message]');
                    if (message) message.textContent = this.waitingForRecovery
                        ? result.problem_actions.closingLabel : result.problem_actions.updatedLabel;
                    this.scheduleLayout();
                } catch (error) {
                    if (this.closed || version !== this.dataVersion) return;
                    const message = this.root.querySelector('[data-problem-action-message]');
                    if (message) message.textContent = this.config.problemActions.closingLabel + ' ' + error.message;
                    // A transient read failure must not lose tracking of an accepted manual close.
                }
                this.recoveryDelay = Math.min(this.recoveryDelay * 2, 5000);
                this.scheduleRecoveryRefresh();
            }, this.recoveryDelay);
        }

        destroy() {
            if (this.closed) return;
            this.closed = true;
            if (this.recoveryTimer !== null) window.clearTimeout(this.recoveryTimer);
            this.recoveryTimer = null;
            this.waitingForRecovery = false;
            if (this.layoutFrame !== null) window.cancelAnimationFrame(this.layoutFrame);
            this.layoutFrame = null;
            this.lifecycle.abort();
            this.dialogue?.classList.remove('analist-problem-dialogue');
            this.refreshButton?.remove();
            this.commentButton?.remove();
            for (const controller of this.requests) controller.abort();
            this.requests.clear();
            this.tabs.clear();
            this.historyRequests.clear();
            this.graphRequest = null;
            this.graphOptions = {};
            this.timelineRequest = null;
            this.patternRequest = null;
            this.patternOptions = {};
            this.patternRenderer?.destroy();
            this.patternRenderer = null;
            AnalistProblemPopup.instances.delete(this);
        }
    }

    window.AnalistProblemPopup = AnalistProblemPopup;
})();
