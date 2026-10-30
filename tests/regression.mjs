import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {spawnSync} from 'node:child_process';
import {fileURLToPath, URLSearchParams} from 'node:url';
import {runPatternChecks} from './patterns.mjs';

export async function runRegression(fixture, asset, popupAsset, fragments = {}, actionFixtures = {}) {
    const {inline} = fixture;
    new vm.Script(inline);
    new vm.Script(asset);
    let config;
    const context = {window: {AnalistProblemPopup: {mount(value) { config = value; }}}, URLSearchParams};
    vm.createContext(context);
    vm.runInContext(inline, context);
    assert.equal(config.eventData.hostname, fixture.host.name);
    assert.equal(config.eventData.problem_name, fixture.event.name);
    if (actionFixtures['timeline-start']) {
        let timelineConfig;
        vm.runInNewContext(actionFixtures['timeline-start'].inline,
            {window: {AnalistProblemPopup: {mount(value) { timelineConfig = value; }}}});
        assert.equal(timelineConfig.initialTab, 'timeline', 'The native popup response retains the requested initial tab');
    }
    assert(fixture.response.body.includes('data-problem-status="">Problem</span>'));
    assert(fixture.response.body.includes('>Started</th>') && fixture.response.body.includes('>Event ID</th>'));
    assert(fixture.response.body.includes('>#1</a>'), 'The event reference links to native event details');
    assert(!fixture.response.body.includes('chart.php'), 'Graphs must not load in the initial popup');
    assert(!fixture.response.body.includes('Monthly Comparison'), 'Comparison belongs only to Timeline');
    const cancelButton = fixture.response.buttons.at(-1);
    assert.equal(cancelButton.title, 'Cancel');
    assert(cancelButton.cancel && cancelButton.class.includes('js-cancel'),
        'The final footer button is always the native Cancel action');
    assert.equal(vm.runInNewContext('(function () {' + cancelButton.action + '})()'), true,
        'Cancel must close the popup');
    for (const [tab, fragment] of Object.entries(fragments)) {
        assert.equal(fragment.response.body.includes('Monthly Comparison'), tab === 'timeline');
        assert.equal(fragment.response.body.includes('chart.php'), tab === 'graphs');
        assert.equal(fragment.inline, '', 'Lazy fragments must not mount additional popup instances');
    }
    if (fragments.patterns) {
        const {body, pattern_data: data} = fragments.patterns.response;
        assert.equal(data.total, 1);
        assert(body.includes('<z-select') && body.includes('toggleCalendar('), 'Pattern filters use native selects and calendars');
        assert.equal((body.match(/data-pattern-slot="/g) || []).length, 168);
        assert.equal((body.match(/data-pattern-toggle="/g) || []).length, 2, 'Hourly/weekday charts retain the original Expand controls');
        assert(body.includes('mnz-heatmap-grid') && body.includes('mnz-investigation-monthly-bars'));
        assert(!body.includes('analist-heatmap-table') && !body.includes('analist-heatmap-legend'),
            'The compact original heatmap replaces the native table presentation');
        assert(body.match(/<button[^>]*data-pattern-apply=""[^>]*>/)[0].includes('hidden="hidden"'));
        assert(body.includes('Europe/Zurich') && body.includes('2025-04-01'));
    }
    if (actionFixtures['patterns-rich']) {
        runPatternChecks(actionFixtures['patterns-rich'], actionFixtures['patterns-empty'], popupAsset);
        assert.equal(actionFixtures['patterns-short'].response.pattern_data.monthKeys.length, 1);
        assert(!actionFixtures['patterns-custom'].response.body.match(/<button[^>]*data-pattern-apply=""[^>]*>/)[0].includes('hidden="hidden"'));
    }
    if (fragments.graphs) {
        assert(fragments.graphs.response.body.includes('width="800"'));
        assert(fragments.graphs.response.body.includes('height="300"'), 'Graph images reserve space before loading');
        assert(!fragments.graphs.response.body.includes('file:///'), 'Charts use frontend URLs');
        assert(fragments.graphs.response.body.includes('<z-select') && fragments.graphs.response.body.includes('toggleCalendar('),
            'Graph controls use native Zabbix selects and calendars');
        assert(fragments.graphs.response.body.includes('toggleSection('), 'Graphs use native collapsible sections');
        assert(fragments.graphs.response.body.includes('Open interactive graph'));
        assert(!fragments.graphs.response.body.includes('name="graph_layout"'),
            'A single graphable item has no Display dropdown');
        assert(fragments.graphs.response.body.includes('data-graph-custom-range="" hidden="hidden"'));
        assert(fragments.graphs.response.body.match(/<button[^>]*data-graph-apply=""[^>]*>/)?.[0].includes('hidden="hidden"'),
            'The initial preset period hides Apply');
        const ids = [...fragments.graphs.response.body.matchAll(/\bid="([^"]+)"/g)].map(match => match[1]);
        assert.equal(ids.length, new Set(ids).size, 'Graph controls and calendars have unique IDs');
    }
    const graphImages = body => [...body.matchAll(/<img[^>]+src="([^"]+)"/g)]
        .map(match => new URL(match[1].replaceAll('&amp;', '&'), 'http://zabbix.test/'));
    for (const rendered of [fragments.graphs, ...Object.entries(actionFixtures)
            .filter(([scenario]) => scenario.startsWith('graphs-')).map(([, value]) => value)].filter(Boolean)) {
        assert.equal(rendered.chart_periods.length, graphImages(rendered.response.body).length);
        for (const period of rendered.chart_periods) {
            assert.equal(period.from_ts, rendered.graph_timeline.from_ts,
                'The native chart resolver must honor the selected start instead of using the default period');
            assert.equal(period.to_ts, rendered.graph_timeline.to_ts,
                'The native chart resolver must honor the selected end instead of using now');
        }
    }
    if (actionFixtures['graphs-before']) {
        const rendered = actionFixtures['graphs-before'];
        assert.equal(rendered.chart_periods.length, 1);
        assert.equal(rendered.chart_periods[0].to_ts - rendered.chart_periods[0].from_ts, 6 * 3600,
            '6 hours before problem must render a six-hour chart');
        assert.equal(rendered.chart_periods[0].to_ts, rendered.event.clock,
            'The six-hour chart ends at problem start');
    }
    if (actionFixtures['graphs-many']) {
        const body = actionFixtures['graphs-many'].response.body;
        assert(body.includes('name="graph_layout"'), 'Two graphable items enable the Display dropdown');
        const images = graphImages(body);
        assert.equal(images.length, 2, 'Individual mode gives each item its own scale');
        images.forEach((url, index) => {
            assert.equal(url.pathname, '/chart.php');
            assert.equal(url.searchParams.get('itemids[0]'), String(index + 1));
            assert.equal(url.searchParams.get('itemids[1]'), null);
        });
        assert(!body.includes('<metric>') && body.includes('&lt;metric&gt;'), 'Graph item names are escaped');
        const links = [...body.matchAll(/href="(history\.php[^\"]+)"/g)]
            .map(match => new URL(match[1].replaceAll('&amp;', '&'), 'http://zabbix.test/'));
        assert.equal(links.length, 4);
        assert(links.some(url => url.searchParams.get('action') === 'showvalues'));
        links.forEach(url => {
            assert.equal(url.searchParams.get('from'), images[0].searchParams.get('from'));
            assert.equal(url.searchParams.get('to'), images[0].searchParams.get('to'));
        });
    }
    if (actionFixtures['graphs-combined']) {
        const body = actionFixtures['graphs-combined'].response.body;
        const images = graphImages(body);
        assert.equal(images.length, 1);
        assert.equal(images[0].searchParams.get('itemids[1]'), '2');
        assert(body.includes('action=batchgraph'), 'Combined graphs open the native batch graph view');
    }
    if (actionFixtures['graphs-empty']) {
        assert(!actionFixtures['graphs-empty'].response.body.includes('name="graph_layout"'));
        assert.equal(graphImages(actionFixtures['graphs-empty'].response.body).length, 0);
        assert(actionFixtures['graphs-empty'].response.body.includes('No numeric items'));
    }
    if (actionFixtures['graphs-custom']) {
        assert(!actionFixtures['graphs-custom'].response.body.includes('data-graph-custom-range="" hidden="hidden"'));
        assert(!actionFixtures['graphs-custom'].response.body.match(/<button[^>]*data-graph-apply=""[^>]*>/)?.[0].includes('hidden="hidden"'),
            'Custom period renders Apply visible');
    }
    if (actionFixtures['timeline-page']) {
        const body = actionFixtures['timeline-page'].response.body;
        assert.equal((body.match(/data-timeline-event="/g) || []).length, 20);
        assert(body.includes('data-timeline-more=""') && body.includes('data-before-eventid="81"'));
        assert(body.includes('acknowledgePopUp(') && body.includes('tr_events.php?triggerid=7'),
            'Paged Timeline rows retain native event links and Update actions');
    }
    if (actionFixtures['timeline-more-page']) {
        const rendered = actionFixtures['timeline-more-page'];
        assert.equal(rendered.inline, '');
        assert.equal((rendered.response.body.match(/data-timeline-event="/g) || []).length, 20);
        assert(!rendered.response.body.includes('Monthly Comparison') && !rendered.response.body.includes('<table'),
            'More returns event rows without rebuilding the comparison or table');
        assert(rendered.response.has_more && rendered.response.cursor.eventid === '61');
    }
    if (actionFixtures['history-preview']) {
        const body = actionFixtures['history-preview'].response.body;
        assert(body.includes('data-history-item="10"') && body.includes('data-history-more=""'));
        assert(body.includes('data-before-clock="80"') && body.includes('data-before-ns="0"'));
        assert.equal((body.match(/class="operational-history-value"/g) || []).length, 3,
            'Each preview starts with three values');
        assert(!body.includes('<script>') && body.includes('&lt;script&gt;'), 'Preview values are escaped');
    }
    if (actionFixtures['history-fragment']) {
        const rendered = actionFixtures['history-fragment'];
        assert.equal(rendered.inline, '');
        assert.equal((rendered.response.body.match(/class="operational-history-value"/g) || []).length, 3);
        assert(rendered.response.has_more && rendered.response.cursor.clock === 80);
        assert(!rendered.response.body.includes('<script>'), 'Older values use the same safe native formatting');
    }
    for (const [scenario, rendered] of Object.entries(actionFixtures)) {
        assert.equal(rendered.response.body.includes('data-problem-action="close"'),
            ['permitted', 'acknowledged'].includes(scenario), 'Close row appears only when allowed');
        assert.equal(rendered.response.body.includes('data-problem-action="acknowledge"'),
            ['permitted', 'ack-only'].includes(scenario));
        assert.equal(rendered.response.body.includes('data-problem-action="unacknowledge"'), scenario === 'acknowledged');
        assert.equal(rendered.response.body.includes('data-problem-action="severity-up"'),
            ['permitted', 'acknowledged'].includes(scenario), 'Severity controls require permission');

    }
    if (actionFixtures.fragment) {
        assert.equal(actionFixtures.fragment.inline, '', 'Overview refresh must return a fragment');
        assert(!actionFixtures.fragment.response.body.includes('role="tablist"'));
    }
    if (actionFixtures.recovered) {
        assert(actionFixtures.recovered.response.body.includes('Recovery time'));
        assert(actionFixtures.recovered.response.body.includes('data-problem-status="">Resolved</span>'));
        assert(!actionFixtures.recovered.response.body.includes('data-problem-action="close"'));
    }
    if (actionFixtures.closing) {
        assert(actionFixtures.closing.response.body.includes('Recovery time'));
        assert(actionFixtures.closing.response.body.includes('data-problem-status="">Closing</span>'));
        assert(actionFixtures.closing.response.body.includes('Pending'));
        assert(!actionFixtures.closing.response.body.includes('data-problem-action="close"'));
    }
    if (actionFixtures['recovery-unavailable']) {
        const body = actionFixtures['recovery-unavailable'].response.body;
        assert(body.includes('data-problem-status="">Resolved</span>'));
        const recoveryRow = body.match(/<tr[^>]*data-recovery-row=""[^>]*>(.*?)<\/tr>/s)?.[1];
        assert(recoveryRow && (recoveryRow.match(/>Unknown<\/td>/g) || []).length === 2,
            'An unavailable recovery timestamp must not show a duration that continues growing after resolution');
    }
    if (actionFixtures.details) {
        const body = actionFixtures.details.response.body;
        assert(body.includes('API &lt;primary&gt;') && body.includes('API secondary'),
            'The event context includes the visible name of every trigger host');
        assert(!body.includes('technical-primary') && !body.includes('technical-secondary'));
        assert(body.includes('Rack &lt;one&gt;\n&lt;script&gt;alert(1)&lt;/script&gt;') && !body.includes('<script>'),
            'Inventory notes remain escaped and multiline');
        assert.equal((body.match(/class="analist-host-notes grey"/g) || []).length, 1,
            'Empty inventory notes do not add a blank line');
        assert(body.includes('Investigate &lt;service&gt;\nEscalate if needed.'), 'Description text remains escaped and multiline');
        assert(body.includes('service: &lt;API&gt;'), 'Native event tags remain available and escaped');
        assert(body.includes('analist-problem-technical collapsed') && body.includes('Technical details') && body.includes('toggleSection('),
            'Expressions use a collapsed native section');
        assert(body.includes('last(/API/custom.metric)&lt;5') && body.includes('last(/API/custom.metric)&gt;10'));
        assert(body.indexOf('Technical details') > body.indexOf('Description'));
    }
    for (const scenario of ['permitted', 'readonly', 'comment-only']) {
        const rendered = actionFixtures['update-' + scenario];
        if (!rendered) continue;
        const allowed = scenario !== 'readonly';
        assert.equal(rendered.response.body.includes('data-problem-comment'), allowed,
            'Update renders a comment field only with the add-comments permission');
        assert.equal(rendered.response.body.includes('data-problem-action="comment"'), allowed);
        assert.equal(rendered.response.comment_actions.canComment, allowed);
        if (allowed) {
            assert(rendered.response.body.includes('maxlength="'));
            assert(rendered.response.body.includes('data-comment-form="" hidden="hidden"'),
                'The comment editor is hidden until the footer action is used');
            assert(!rendered.response.body.includes('Add comment'), 'Add comment belongs in the popup footer');
            assert.equal(rendered.response.comment_actions.csrfToken, 'fixture-csrf-acknowledge');
        }
    }
    new vm.Script(popupAsset);
    const exportsSource = popupAsset.replace('return {displayServicesTree, displayNoServicesMessage, selectService, filterServices};',
        'return {displayServicesTree, displayNoServicesMessage, selectService, filterServices, createServiceCard, createHierarchyPath, createServiceSlaInfo, getServiceStatusText, getServiceStatusClass};')
        .replace('window.AnalistProblemPopup = AnalistProblemPopup;',
        'window.createServiceUi = createServiceUi; window.AnalistProblemPopup = AnalistProblemPopup;');
    vm.runInContext(exportsSource, context);
    const serviceUi = context.window.createServiceUi({root: {}, config});
    const payload = '<img src=x onerror="test()"> & quoted';
    const service = {serviceid: '1', name: payload, status: -1, has_sla: false};
    const card = serviceUi.createServiceCard(service, true);
    assert(!card.includes('<img'), 'Service names must be escaped');
    assert(card.includes('&lt;img'), 'Service name text must be preserved');
    assert(!card.includes('onclick='), 'Service actions must not embed JavaScript');
    assert(!serviceUi.createHierarchyPath([{...service, serviceid: '1" onclick="bad()'}]).includes('onclick="bad()'));
    assert(serviceUi.createServiceSlaInfo({...service, has_sla: true, sli: 99, uptime: payload}).includes('&lt;img'));
    assert(serviceUi.createServiceSlaInfo(service).includes('No accessible SLA configured'));
    assert(serviceUi.createServiceSlaInfo({...service, has_sla: null, sla_state: 'unavailable'})
        .includes('SLA information unavailable'), 'Failed lookups must not claim no SLA');
    assert(serviceUi.createServiceSlaInfo({...service, has_sla: true, sli: null}).includes('SLI unavailable'));
    assert(!serviceUi.createServiceSlaInfo({...service, has_sla: true, sli: -1}).includes('No accessible SLA'));
    assert(serviceUi.createServiceSlaInfo({...service, has_sla: true, sli: 0, uptime: '0s'}).includes('0.00%'));
    const slaHtml = serviceUi.createServiceSlaInfo({...service, has_sla: true, sla_state: 'configured', slas: [
        {name: payload, slo: '97.25', sli: 93.75, sli_state: 'available', uptime: '1h', downtime: '4m', error_budget: '-2m',
            reporting_period: {period_from: 100, period_to: 200}},
        {name: 'Critical SLA', slo: '99.95', sli: null, sli_state: 'unavailable'},
        {name: 'Disabled SLA', slo: '95', sli: null, sli_state: 'disabled'}
    ]});
    assert(!slaHtml.includes('<img') && slaHtml.includes('&lt;img'), 'Configured SLA names must be escaped');
    assert(slaHtml.includes('97.25%') && slaHtml.includes('99.95%'), 'Render every configured target');
    assert(slaHtml.includes('93.75%') && slaHtml.includes('-2m'));
    assert(slaHtml.includes('SLI unavailable') && slaHtml.includes('SLA disabled'));
    assert(slaHtml.includes('Reporting period (UTC)'));
    assert(slaHtml.includes('Below target'), 'Available SLI is compared with its configured target');
    const serviceCards = new Map(['1', '2'].map(id => [id, {
        dataset: {serviceid: id}, closest() { return null; }, style: {}, classList: {add() {}, remove() {}}, open: false, hidden: false,
        scrollIntoView() {}
    }]));
    const emptySearch = {hidden: true};
    const treeFixture = {innerHTML: '', querySelectorAll: () => [...serviceCards.values()]};
    let detailRequests = 0;
    const serviceRoot = {
        querySelector(selector) { return selector === '#services-tree-container' ? treeFixture : emptySearch; },
        querySelectorAll() { return [...serviceCards.values()]; }
    };
    const servicesPopup = {root: serviceRoot, config, closed: false, scheduleLayout() {},
        fetchJson() { detailRequests++; throw new Error('Unexpected service request'); }};
    const interactiveServices = context.window.createServiceUi(servicesPopup);
    interactiveServices.displayServicesTree([
        {serviceid: '1', name: 'Database', status: -1, has_sla: false,
            hierarchy_path: [{serviceid: '9', name: 'Platform'}, {serviceid: '1', name: 'Database'}],
            problem_tags: [{tag: 'app', operator: 0, value: payload}]},
        {serviceid: '2', name: 'Web', status: 2, has_sla: false,
            hierarchy_path: [{serviceid: '9', name: 'Platform'}, {serviceid: '8', name: 'Applications'}, {serviceid: '2', name: 'Web'}]}
    ]);
    assert.equal((treeFixture.innerHTML.match(/class="services-hierarchy-path"/g) || []).length, 2,
        'Each matching service renders its own hierarchy');
    assert(treeFixture.innerHTML.includes('data-services-search'));
    assert(!treeFixture.innerHTML.includes('data-refresh-services') && !treeFixture.innerHTML.includes('data-service-refresh'),
        'Services uses the popup Refresh button without duplicate tab or card controls');
    assert(treeFixture.innerHTML.includes('Matching tag rules') && !treeFixture.innerHTML.includes('<img'));
    assert(treeFixture.innerHTML.includes('<details class="analist-related-services" data-services-related><summary>Related services (2)'),
        'Related services are preloaded in a collapsed group');
    assert.equal((treeFixture.innerHTML.match(/data-serviceid="9"/g) || []).length, 1,
        'A shared ancestor is rendered only once');
    assert.equal((treeFixture.innerHTML.match(/data-serviceid="1"/g) || []).length, 1,
        'A matching service is not duplicated among related services');
    interactiveServices.selectService('1');
    assert.equal(detailRequests, 0, 'Hierarchy navigation uses preloaded data without fetching');
    interactiveServices.filterServices('  database  ');
    assert(!serviceCards.get('1').hidden && serviceCards.get('2').hidden);
    interactiveServices.filterServices('applications');
    assert(serviceCards.get('1').hidden && !serviceCards.get('2').hidden, 'Search includes hierarchy names');
    interactiveServices.filterServices('missing'); assert(!emptySearch.hidden);
    interactiveServices.filterServices(''); assert(emptySearch.hidden);
    assert(serviceCards.get('1').open, 'Hierarchy links still expand the selected service');
    assert.equal(detailRequests, 0, 'Service navigation uses cached cards');
    interactiveServices.displayNoServicesMessage();
    assert(!treeFixture.innerHTML.includes('data-refresh-services'), 'Empty Services has no duplicate refresh control');
    for (const [value, label] of Object.entries(config.statusLabels)) {
        assert.equal(serviceUi.getServiceStatusText(Number(value)), label);
    }
    assert.equal(serviceUi.getServiceStatusClass(0), 'status-na-bg');
    assert.equal(serviceUi.getServiceStatusClass(2), 'status-warning-bg');
    assert.equal(serviceUi.getServiceStatusClass(3), 'status-average-bg');

    // Exercise the popup controller with pending network requests and independent DOM roots.
    class Target {
        constructor() { this.handlers = new Map(); }
        addEventListener(name, callback, options = {}) {
            if (!this.handlers.has(name)) this.handlers.set(name, new Set());
            this.handlers.get(name).add(callback);
            options.signal?.addEventListener('abort', () => this.handlers.get(name).delete(callback));
        }
        dispatch(name, event) { this.handlers.get(name)?.forEach(callback => callback(event)); }
    }
    class AbortFixture {
        constructor() { this.signal = new Target(); this.signal.aborted = false; }
        abort() { this.signal.aborted = true; this.signal.dispatch('abort'); }
    }
    class Panel extends Target {
        constructor(tab) { super(); this.dataset = {tab}; this.style = {}; this.attributes = {}; this.children = []; }
        setAttribute(name, value) { this.attributes[name] = value; }
        removeAttribute(name) { delete this.attributes[name]; }
        append(...children) { this.children.push(...children); }
        querySelector() { return this.children[0]; }
        replaceChildren(value = '') { this.textContent = value; this.innerHTML = ''; this.children = []; }
        focus() { this.focused = true; }
        remove() { this.removed = true; }
    }
    function popupRoot() {
        const root = new Target();
        root.isConnected = true;
        root.dialogue = new Target();
        root.dialogue.dataset = {dialogueid: String(roots.size)};
        const dialogueClasses = new Set();
        root.dialogue.classList = {add: name => dialogueClasses.add(name), remove: name => dialogueClasses.delete(name),
            contains: name => dialogueClasses.has(name)};
        root.cancelButton = {className: 'btn-alt js-cancel', disabled: false};
        root.footerButtons = [root.cancelButton];
        root.headerButtons = [];
        root.dialogue.querySelector = selector => selector === '.btn-overlay-close'
            ? {before(button) {
                root.headerButtons.push(button);
                root.headerRefresh = button;
            }} : selector === '.overlay-dialogue-footer .js-cancel' ? {before(button) {
                root.footerButtons.splice(root.footerButtons.length - 1, 0, button);
                root.footerComment = button;
            }} : null;
        root.contains = target => target.popupRoot === root;
        root.panels = ['overview', 'host', 'timeline', 'patterns', 'graphs', 'update', 'services'].map(key => new Panel(key));
        root.buttons = root.panels.map(panel => ({dataset: {tabTarget: panel.dataset.tab},
            setAttribute() {}, parentElement: {classList: {toggle() {}}}, focus() {}}));
        root.actionButtons = [{disabled: false}, {disabled: false}];
        root.message = new Panel('');
        root.commentMessage = new Panel('');
        root.comment = new Panel(''); root.comment.value = '';
        root.commentForm = new Panel(''); root.commentForm.id = 'comment-form-' + roots.size; root.commentForm.hidden = true;
        root.commentRendered = false;
        let updateMarkup = '';
        Object.defineProperty(root.panels.find(panel => panel.dataset.tab === 'update'), 'innerHTML', {
            get() { return updateMarkup; },
            set(value) {
                updateMarkup = value;
                root.commentRendered = String(value).includes('data-comment-form');
                root.commentForm.hidden = true;
                root.comment.value = '';
                root.commentMessage.textContent = '';
            }
        });
        root.servicesTree = new Panel('');
        root.querySelector = selector => selector === '[data-pattern-view]' ? null
            : selector === '[data-problem-comment]' ? (root.commentRendered ? root.comment : null)
            : selector === '[data-comment-message]' ? (root.commentRendered ? root.commentMessage : null)
            : selector === '[data-comment-form]' ? (root.commentRendered ? root.commentForm : null)
            : selector === '#services-tree-container' ? root.servicesTree : root.message;
        root.querySelectorAll = selector => selector === '[data-tab]' ? root.panels
            : selector === '[data-problem-action]' ? root.actionButtons : root.buttons;
        root.closest = () => root.dialogue;
        return root;
    }
    const roots = new Map();
    const pending = [];
    const frames = new Map();
    const overlays = new Map();
    const recoveryTimers = new Map();
    let recoveryTimerId = 0;
    context.window.setTimeout = callback => { recoveryTimers.set(++recoveryTimerId, callback); return recoveryTimerId; };
    context.window.clearTimeout = id => recoveryTimers.delete(id);
    let frameId = 0;
    context.window.requestAnimationFrame = callback => { frames.set(++frameId, callback); return frameId; };
    context.window.cancelAnimationFrame = id => frames.delete(id);
    context.window.overlays_stack = {getById: id => overlays.get(id)};
    function flushFrames() {
        const callbacks = [...frames.values()]; frames.clear(); callbacks.forEach(callback => callback());
    }
    Object.assign(context, {
        AbortController: AbortFixture,
        DOMException: class extends Error { constructor(message, name) { super(message); this.name = name; } },
        document: {getElementById: id => roots.get(id), createElement: () => new Panel('')},
        fetch(url, options) {
            return new Promise((resolve, reject) => {
                pending.push({url, options, resolve: body => resolve({ok: true, json: async () => body})});
                options.signal.addEventListener('abort', () => reject(Object.assign(new Error('Cancelled'), {name: 'AbortError'})));
            });
        }
    });
    delete context.window.AnalistProblemPopup;
    const coldRoot = popupRoot(); roots.set(config.rootId, coldRoot);
    vm.runInContext(inline, context);
    const Popup = context.window.AnalistProblemPopup;
    assert.equal(typeof Popup?.mount, 'function',
        'A native popup response provides its runtime without a preloaded popup asset');
    const initialPopup = [...Popup.instances].find(instance => instance.root === coldRoot);
    assert(initialPopup && initialPopup.activeTab === 'overview' && coldRoot.handlers.get('click').size === 1,
        'The response defines the runtime before mounting the popup controls');
    initialPopup.destroy(); roots.delete(config.rootId);
    const rootA = popupRoot(); roots.set('a', rootA);
    let layouts = 0;
    overlays.set(rootA.dialogue.dataset.dialogueid, {$dialogue: [rootA.dialogue], centerDialog() { layouts++; }});
    const popupA = Popup.mount({...config, rootId: 'a'});
    flushFrames();
    assert.equal(pending.length, 0, 'Opening Overview must not fetch other tabs');
    const first = popupA.activateTab('timeline');
    assert.equal(popupA.loadTab('timeline'), first, 'Concurrent visits reuse the pending request');
    assert.equal(pending.length, 1);
    const reopenRoot = popupRoot(); roots.set(config.rootId, reopenRoot);
    vm.runInContext(inline, context);
    assert.equal(context.window.AnalistProblemPopup, Popup,
        'Opening a second popup must preserve the existing runtime and its instance registry');
    assert(Popup.instances.has(popupA) && !popupA.closed && !pending[0].options.signal.aborted,
        'Opening a second popup preserves requests and handlers owned by the first popup');
    const reopened = [...Popup.instances].find(instance => instance.root === reopenRoot);
    assert(reopened && !reopened.closed, 'The native response mounts its own popup when the runtime already exists');
    reopened.destroy(); roots.delete(config.rootId);
    assert.equal(pending[0].options.body.get('tab'), 'timeline');
    pending[0].resolve({body: '<p>Timeline</p>'}); await first;
    await popupA.activateTab('overview'); await popupA.activateTab('timeline');
    assert.equal(pending.length, 1, 'Revisiting a loaded tab uses its cache');
    const failed = popupA.activateTab('graphs');
    pending[1].resolve({error: {messages: ['Access denied']}}); await failed;
    assert.equal(popupA.tabs.get('graphs').loaded, false);
    const graphPanel = popupA.panels.get('graphs');
    assert.equal(graphPanel.children[0].textContent, 'Access denied');
    assert.equal(graphPanel.children[1].dataset.retryTab, 'graphs');
    const retried = popupA.loadTab('graphs');
    pending[2].resolve({body: '<img src="chart.php">'}); await retried;
    assert.equal(popupA.tabs.get('graphs').loaded, true);
    flushFrames();
    const beforeImage = layouts;
    const imageEvent = {target: {matches: selector => selector === 'img.chart-image'}};
    rootA.dispatch('load', imageEvent); rootA.dispatch('load', imageEvent);
    assert.equal(frames.size, 1, 'Chart loads share one scheduled layout update');
    flushFrames();
    assert.equal(layouts, beforeImage + 1, 'Late chart loads refresh native scrolling without switching tabs');
    rootA.dispatch('error', imageEvent); flushFrames();
    assert.equal(layouts, beforeImage + 2, 'Failed images also refresh the popup layout');
    const rootB = popupRoot(); roots.set('b', rootB);
    const popupB = Popup.mount({...config, rootId: 'b'});
    const independent = popupB.activateTab('timeline');
    assert.equal(pending.length, 4, 'Separate popups have independent caches');
    pending[3].resolve({body: '<p>Independent timeline</p>'}); await independent;
    const closing = popupA.activateTab('host');
    rootA.dialogue.dispatch('dialogue.close'); await closing;
    assert(pending[4].options.signal.aborted, 'Closing the dialogue aborts pending requests');
    assert(popupA.closed && !Popup.instances.has(popupA));
    assert.equal(popupA.layoutFrame, null, 'Closing cancels pending layout work');
    assert.equal(rootA.handlers.get('click').size, 0, 'Closing removes delegated handlers');
    assert.equal(rootA.handlers.get('load').size, 0, 'Closing removes image handlers');
    assert(rootA.headerRefresh.removed && rootA.headerRefresh.handlers.get('click').size === 0,
        'Closing removes the header Refresh button and its handler');
    assert(rootA.footerComment.removed && rootA.footerComment.handlers.get('click').size === 0,
        'Closing removes the footer Add comment button and its handler');
    assert(!rootA.dialogue.classList.contains('analist-problem-dialogue'), 'Closing removes the popup layout class');
    rootB.isConnected = false; Popup.cleanupDetached();
    assert(popupB.closed && Popup.instances.size === 0, 'Removed popups are cleaned up');
    assert.equal(frames.size, 0, 'No layout callbacks remain after cleanup');

    const rootC = popupRoot(); roots.set('c', rootC);
    const actions = {...config.problemActions, canAcknowledge: true, canClose: true, csrfToken: 'test-token'};
    const popupC = Popup.mount({...config, rootId: 'c', problemActions: actions});
    popupC.tabs.set('update', {loaded: true});
    let updating = popupC.updateProblem('acknowledge');
    await popupC.updateProblem('acknowledge');
    assert.equal(pending.length, 6, 'Double-clicks must issue only one mutation');
    assert(rootC.actionButtons.every(button => button.disabled));
    assert(pending[5].url.endsWith('popup.acknowledge.create'), 'Use the native protected update endpoint');
    assert.equal(pending[5].options.body.get('eventids[]'), config.eventData.eventid);
    assert.equal(pending[5].options.body.get(actions.csrfTokenName), 'test-token');
    assert.equal(pending[5].options.body.get('acknowledge_problem'), String(actions.acknowledgeAction));
    assert.equal(pending[5].options.body.get('close_problem'), null, 'Acknowledgement must not also close the event');
    pending[5].resolve({success: {title: 'Event updated'}});
    while (pending.length < 7) await Promise.resolve();
    assert.equal(pending[6].options.body.get('tab'), 'overview');
    pending[6].resolve({body: 'Fresh overview', problem_actions: {...actions, acknowledged: true}});
    await updating;
    assert.equal(popupC.config.problemActions.acknowledged, true, 'Refresh the acknowledgement state after success');
    assert(!popupC.tabs.has('update'), 'Successful mutations invalidate cached history');
    rootC.actionButtons.forEach(button => { button.disabled = false; }); // Newly rendered buttons in a real DOM.
    updating = popupC.updateProblem('unacknowledge');
    assert.equal(pending[7].options.body.get('unacknowledge_problem'), String(actions.unacknowledgeAction));
    pending[7].resolve({error: {messages: ['Permission denied']}}); await updating;
    assert.equal(rootC.message.textContent, 'Permission denied');
    assert(rootC.actionButtons.every(button => !button.disabled), 'Failed submissions must restore the buttons');
    popupC.config.problemActions.canClose = false;
    await popupC.updateProblem('close');
    assert.equal(pending.length, 8, 'Unavailable close actions must not submit');
    popupC.config.problemActions.canClose = true;
    updating = popupC.updateProblem('close');
    assert.equal(pending[8].options.body.get('close_problem'), String(actions.closeAction));
    rootC.dialogue.dispatch('dialogue.close'); await updating;
    assert(pending[8].options.signal.aborted, 'Pending updates also follow popup cleanup');

    const rootD = popupRoot(); roots.set('d', rootD);
    const overviewD = rootD.panels.find(panel => panel.dataset.tab === 'overview');
    let pendingRow = '';
    let pendingInsertions = 0;
    let ageRemoved = false;
    let overviewMarkup = '';
    const closingStatus = {textContent: 'Problem', className: 'color-negative'};
    Object.defineProperty(overviewD, 'innerHTML', {
        get() { return overviewMarkup; },
        set(value) { overviewMarkup = value; pendingRow = ''; }
    });
    overviewD.querySelector = selector => {
        if (selector === '[data-problem-status]') return closingStatus;
        if (selector === '[data-recovery-row]') return pendingRow ? {} : null;
        if (selector === '[data-age-row]') return {remove() { ageRemoved = true; }};
        if (selector === '.analist-problem-detail-table tbody') return {
            insertAdjacentHTML(position, value) { pendingRow = value; pendingInsertions++; }
        };
        return null;
    };
    const popupD = Popup.mount({...config, rootId: 'd', problemActions: {...actions, recoveryReady: false}});
    updating = popupD.updateProblem('close');
    pending[9].resolve({success: {title: 'Event updated'}});
    while (pending.length < 11) await Promise.resolve();
    pending[10].resolve({body: 'Closing overview', problem_actions: {...actions, recoveryReady: false, canClose: false}});
    await updating;
    assert.equal(recoveryTimers.size, 1, 'Accepted close must track asynchronous recovery');
    assert(ageRemoved && pendingRow.includes('Recovery time') && pendingRow.includes('Pending'),
        'An accepted close immediately displays a pending Recovery time row');
    assert.equal(closingStatus.textContent, 'Closing');
    assert.equal(closingStatus.className, 'color-warning');
    const nextRecovery = () => {
        const [id, callback] = recoveryTimers.entries().next().value;
        recoveryTimers.delete(id); return callback();
    };
    let refreshing = nextRecovery();
    pending[11].resolve({error: {messages: ['Temporary read failure']}}); await refreshing;
    assert.equal(recoveryTimers.size, 1, 'Transient refresh errors must not lose pending recovery');
    popupD.activeTab = 'graphs';
    refreshing = nextRecovery();
    pending[12].resolve({body: 'Recovered overview: Recovery time', problem_actions: {...actions, recoveryReady: true, canClose: false}});
    await refreshing;
    assert(popupD.panels.get('overview').innerHTML.includes('Recovery time'));
    assert.equal(popupD.activeTab, 'graphs', 'Recovery refresh must preserve the selected tab');
    assert.equal(recoveryTimers.size, 0, 'Tracking stops once the real recovery time is available');
    popupD.waitingForRecovery = true; popupD.scheduleRecoveryRefresh();
    rootD.dialogue.dispatch('dialogue.close');
    assert.equal(recoveryTimers.size, 0, 'Closing the popup cancels recovery tracking');
    const rootE = popupRoot(); roots.set('e', rootE);
    const popupE = Popup.mount({...config, rootId: 'e', problemActions: {...actions, closingPending: true, recoveryReady: false}});
    assert.equal(recoveryTimers.size, 1, 'A mounted popup resumes server-reported pending closure automatically');
    popupE.destroy();
    assert.equal(recoveryTimers.size, 0);

    // A small table fixture checks column alignment and idempotence independently of a browser.
    class Element extends Target {
        constructor(tagName) {
            super(); this.tagName = tagName.toUpperCase(); this.children = []; this.className = ''; this.colSpan = 1;
            this.isConnected = true; this.attributes = {}; this.textContent = '';
        }
        appendChild(child) { child.parent = this; this.children.push(child); return child; }
        insertBefore(child, before) {
            child.parent = this;
            const index = before ? this.children.indexOf(before) : this.children.length;
            this.children.splice(index, 0, child); return child;
        }
        remove() { this.parent.children = this.parent.children.filter(child => child !== this); }
        setAttribute(name, value) { this.attributes[name] = value; }
        querySelector(selector) {
            if (selector.includes('data-menu-popup')) return this.problemData ? {} : null;
            const classes = selector.match(/\.([\w-]+)/g) || [];
            const all = this.children.flatMap(child => [child, ...child.children]);
            return all.find(child => classes.some(name => child.className.split(' ').includes(name.slice(1)))) || null;
        }
        get cells() { return this.children.filter(child => ['TD', 'TH'].includes(child.tagName)); }
    }
    const severityRoot = popupRoot(); roots.set('severity', severityRoot);
    const severityActions = {...actions, canChangeSeverity: true, severity: 3};
    const severityPopup = Popup.mount({...config, rootId: 'severity', problemActions: severityActions});
    for (const [action, current, expected] of [['severity-up', 3, 4], ['severity-down', 3, 2]]) {
        severityPopup.config.problemActions.severity = current;
        const index = pending.length;
        const change = severityPopup.updateProblem(action);
        assert.equal(pending[index].options.body.get('change_severity'), String(actions.severityAction));
        assert.equal(pending[index].options.body.get('severity'), String(expected));
        assert.equal(pending[index].options.body.get('close_problem'), null);
        pending[index].resolve({success: {title: 'Event updated'}});
        while (pending.length < index + 2) await Promise.resolve();
        pending[index + 1].resolve({body: 'Updated severity', problem_actions: {...severityActions, severity: expected}});
        await change;
        assert.equal(severityPopup.config.problemActions.severity, expected);
    }
    const severityRequestCount = pending.length;
    severityPopup.config.problemActions.severity = severityActions.maxSeverity;
    await severityPopup.updateProblem('severity-up');
    severityPopup.config.problemActions.severity = severityActions.minSeverity;
    await severityPopup.updateProblem('severity-down');
    severityPopup.config.problemActions.canChangeSeverity = false;
    await severityPopup.updateProblem('severity-up');
    assert.equal(pending.length, severityRequestCount, 'Limits and permissions prevent severity requests');
    severityRoot.dialogue.dispatch('dialogue.close');

    const refreshRoot = popupRoot(); roots.set('refresh', refreshRoot);
    const refreshPopup = Popup.mount({...config, rootId: 'refresh', problemActions: {...actions}});
    assert.equal(refreshRoot.headerRefresh, refreshPopup.refreshButton, 'Refresh is inserted beside the header Close button');
    assert.equal(refreshPopup.refreshButton.textContent, config.refreshLabel);
    let index = pending.length;
    let loading = refreshPopup.activateTab('timeline');
    pending[index].resolve({body: 'Cached timeline'}); await loading;
    index = pending.length;
    loading = refreshPopup.activateTab('host');
    await refreshPopup.activateTab('timeline');
    const oldServices = refreshPopup.serviceUi;
    const refreshIndex = pending.length;
    let reloading = refreshPopup.refreshTab();
    assert.equal(refreshPopup.refreshTab(), reloading, 'Repeated refreshes share the active request');
    assert(pending[index].options.signal.aborted, 'Refreshing cancels loads for inactive tabs');
    assert(refreshPopup.refreshButton.disabled);
    await loading;
    assert.equal(pending.length, refreshIndex + 1, 'Refresh fetches only the selected tab');
    assert.equal(pending[refreshIndex].options.body.get('tab'), 'timeline');
    assert.equal(refreshPopup.activeTab, 'timeline');
    assert.equal(refreshPopup.tabs.size, 1);
    assert.equal(refreshPopup.panels.get('overview').innerHTML, '', 'Discard inactive markup as well as cached state');
    assert.notEqual(refreshPopup.serviceUi, oldServices, 'Discard cached service cards and requests');
    assert.equal(refreshPopup.panels.get('timeline').attributes['aria-busy'], 'true',
        'A cancelled load cannot clear the current request loading state');
    pending[refreshIndex].resolve({body: 'Fresh timeline'}); await reloading;
    assert.equal(refreshPopup.panels.get('timeline').innerHTML, 'Fresh timeline');
    assert(!refreshPopup.refreshButton.disabled);
    index = pending.length;
    loading = refreshPopup.activateTab('overview');
    assert.equal(pending.length, index + 1, 'Discarded Overview refetches on its next visit');
    pending[index].resolve({body: 'Fresh overview', problem_actions: {...actions}}); await loading;
    index = pending.length;
    reloading = refreshPopup.refreshTab();
    pending[index].resolve({error: {messages: ['Refresh failed']}}); await reloading;
    assert(!refreshPopup.refreshButton.disabled && !refreshPopup.tabs.get('overview').loaded,
        'Failed refreshes remain retryable and restore the header button');
    index = pending.length;
    reloading = refreshPopup.refreshTab();
    pending[index].resolve({body: 'Retried overview', problem_actions: {...actions}}); await reloading;
    index = pending.length;
    loading = refreshPopup.activateTab('graphs');
    reloading = refreshPopup.refreshTab();
    await loading;
    assert.equal(refreshPopup.panels.get('graphs').attributes['aria-busy'], 'true',
        'The aborted request for the same tab cannot finish the new request');
    pending[index + 1].resolve({body: 'Fresh graphs'}); await reloading;
    index = pending.length;
    loading = refreshPopup.activateTab('services');
    pending[index].resolve({success: true, data: []}); await loading;
    index = pending.length;
    reloading = refreshPopup.refreshTab();
    assert(pending[index].url.endsWith('problemanalist.service.get'), 'The shared Refresh reloads Services');
    assert.equal(pending[index].options.body.get('eventid'), config.eventData.eventid);
    assert.equal(pending.length, index + 1, 'Services refresh issues one request');
    pending[index].resolve({success: true, data: []}); await reloading;
    assert.equal(refreshPopup.activeTab, 'services');
    assert.equal(refreshPopup.tabs.size, 1, 'Services refresh invalidates other tabs');
    refreshRoot.dialogue.dispatch('dialogue.close');

    const commentRoot = popupRoot(); roots.set('comment', commentRoot);
    const commentPopup = Popup.mount({...config, rootId: 'comment', problemActions: {...actions, canComment: false}});
    assert.equal(commentRoot.footerButtons.at(-2), commentPopup.commentButton, 'Add comment is immediately before Cancel');
    assert.equal(commentRoot.footerButtons.at(-1), commentRoot.cancelButton, 'Cancel stays last at the bottom right');
    assert(!commentRoot.headerButtons.includes(commentPopup.commentButton), 'Add comment is removed from the header');
    assert(commentRoot.dialogue.classList.contains('analist-problem-dialogue'), 'The popup uses a layout that keeps its footer at the bottom');
    assert.equal(commentPopup.commentButton.textContent, config.addCommentLabel);
    assert(commentPopup.commentButton.hidden, 'The footer action obeys the add-comments permission');
    index = pending.length;
    await commentPopup.openCommentForm();
    assert.equal(pending.length, index, 'An unavailable comment action must not fetch a tab');
    const commentMarkup = actionFixtures['update-permitted']?.response.body || '<div data-comment-form></div>';
    index = pending.length;
    loading = commentPopup.activateTab('update');
    pending[index].resolve({body: commentMarkup, comment_actions: {canComment: true, csrfToken: 'comment-token'}});
    await loading;
    assert(commentPopup.config.problemActions.canComment && commentPopup.config.problemActions.canClose,
        'Loading comment permissions must preserve Overview action permissions');
    assert(commentRoot.commentForm.hidden && !commentPopup.commentButton.hidden,
        'Visiting Update shows history with the editor initially collapsed');
    await commentPopup.activateTab('overview');
    index = pending.length;
    await commentPopup.openCommentForm();
    assert.equal(commentPopup.activeTab, 'update', 'The footer action opens Update from another tab');
    assert(!commentRoot.commentForm.hidden && commentRoot.comment.focused);
    assert.equal(commentPopup.commentButton.attributes['aria-expanded'], 'true');
    assert.equal(commentPopup.commentButton.attributes['aria-controls'], commentRoot.commentForm.id);
    assert.equal(pending.length, index, 'Opening the editor reuses loaded Update history');
    index = pending.length;
    commentRoot.comment.value = ' \n ';
    await commentPopup.updateProblem('comment');
    assert.equal(pending.length, index, 'Blank comments must not be submitted');
    assert.equal(commentRoot.commentMessage.textContent, actions.emptyCommentLabel);
    assert(commentRoot.comment.focused);
    const commentText = 'Investigating <script> & "quoted"\nSecond line';
    commentRoot.comment.value = commentText;
    let commenting = commentPopup.updateProblem('comment');
    await commentPopup.updateProblem('comment');
    await commentPopup.refreshTab();
    assert.equal(pending.length, index + 1, 'Duplicate comments and refreshes cannot interrupt a pending mutation');
    assert(commentRoot.comment.disabled && commentPopup.refreshButton.disabled && commentPopup.commentButton.disabled);
    assert(!commentRoot.cancelButton.disabled, 'Cancel stays enabled during comment submissions');
    const commentParams = pending[index].options.body;
    assert.equal(commentParams.get('message'), commentText, 'Preserve multiline comments as form data');
    assert.equal(commentParams.get(actions.csrfTokenName), 'comment-token');
    assert.equal(commentParams.get('eventids[]'), config.eventData.eventid);
    assert.equal(commentParams.get('scope'), String(actions.selectedScope));
    for (const field of ['close_problem', 'acknowledge_problem', 'unacknowledge_problem', 'change_severity']) {
        assert.equal(commentParams.get(field), null, 'Adding a comment does not perform other update actions');
    }
    pending[index].resolve({error: {messages: ['Cannot add comment']}}); await commenting;
    assert.equal(commentRoot.comment.value, commentText, 'Failed comments retain the draft');
    assert.equal(commentRoot.commentMessage.textContent, 'Cannot add comment');
    assert(!commentRoot.commentForm.hidden, 'Failed submissions keep the editor open');
    assert(!commentRoot.comment.disabled && !commentPopup.refreshButton.disabled);
    index = pending.length;
    commenting = commentPopup.updateProblem('comment');
    pending[index].resolve({success: {title: 'Event updated'}});
    while (pending.length < index + 2) await Promise.resolve();
    assert.equal(pending[index + 1].options.body.get('tab'), 'update', 'Successful comments reload Update history');
    pending[index + 1].resolve({body: commentMarkup, comment_actions: {canComment: true, csrfToken: 'next-token'}});
    await commenting;
    assert.equal(commentPopup.activeTab, 'update', 'Adding a comment keeps Update selected');
    assert.equal(commentPopup.tabs.size, 1, 'Other tabs stay invalidated after adding a comment');
    assert.equal(commentRoot.commentMessage.textContent, actions.commentAddedLabel);
    assert(commentRoot.commentForm.hidden && commentRoot.comment.value === '', 'Success closes and clears the editor');
    assert.equal(commentPopup.commentButton.attributes['aria-expanded'], 'false');
    commentPopup.invalidateTabs();
    index = pending.length;
    const openingComment = commentPopup.openCommentForm();
    assert.equal(pending[index].options.body.get('tab'), 'update', 'The footer action fetches discarded Update history');
    pending[index].resolve({body: commentMarkup, comment_actions: {canComment: true, csrfToken: 'next-token'}});
    await openingComment;
    assert(!commentRoot.commentForm.hidden && commentRoot.comment.focused);
    commentPopup.config.problemActions.canComment = false;
    commentPopup.syncCommentButton();
    index = pending.length;
    await commentPopup.updateProblem('comment');
    await commentPopup.openCommentForm();
    assert.equal(pending.length, index, 'Unavailable comment actions must not submit');
    assert(commentPopup.commentButton.hidden);
    commentRoot.dialogue.dispatch('dialogue.close');

    const historyRoot = popupRoot(); roots.set('history', historyRoot);
    const historyPopup = Popup.mount({...config, rootId: 'history'});
    const historyRow = new Panel(''); historyRow.dataset.historyItem = '10'; historyRow.popupRoot = historyRoot;
    const historyValues = {innerHTML: 'Initial three values', insertAdjacentHTML(position, html) { this.innerHTML += html; }};
    const historyMessage = new Panel('');
    historyRow.querySelector = selector => selector === '[data-history-values]' ? historyValues : historyMessage;
    const moreButton = new Panel(''); moreButton.closest = () => historyRow;
    moreButton.dataset = {beforeClock: '180', beforeNs: '0'};
    index = pending.length;
    let more = historyPopup.loadMoreHistory(moreButton);
    assert.equal(historyPopup.loadMoreHistory(moreButton), more, 'Repeated More clicks reuse the pending item request');
    assert.equal(pending.length, index + 1);
    assert.equal(pending[index].options.body.get('tab'), 'history');
    assert.equal(pending[index].options.body.get('itemid'), '10');
    assert.equal(pending[index].options.body.get('before_clock'), '180');
    assert.equal(pending[index].options.body.get('before_ns'), '0');
    assert(moreButton.disabled && historyRow.attributes['aria-busy'] === 'true');
    const moreBody = actionFixtures['history-fragment']?.response.body || '<div>100</div><div>90</div><div>80</div>';
    pending[index].resolve({body: moreBody, has_more: true, cursor: {clock: 80, ns: 0}}); await more;
    assert.equal(historyValues.innerHTML, 'Initial three values' + moreBody, 'More appends older values without replacing the preview');
    assert.equal(moreButton.dataset.beforeClock, 80);
    assert(!moreButton.disabled && !moreButton.hidden);
    index = pending.length;
    more = historyPopup.loadMoreHistory(moreButton);
    pending[index].resolve({error: {messages: ['History unavailable']}}); await more;
    assert.equal(historyMessage.textContent, 'History unavailable');
    assert(!moreButton.disabled, 'Failed history requests retain a retryable More button');
    index = pending.length;
    more = historyPopup.loadMoreHistory(moreButton);
    pending[index].resolve({body: '<div>70</div>', has_more: false, cursor: {clock: 70, ns: 0}}); await more;
    assert(moreButton.hidden && historyValues.innerHTML.endsWith('<div>70</div>'), 'The final partial batch hides More');
    index = pending.length;
    more = historyPopup.loadMoreHistory(moreButton);
    pending[index].resolve({body: '', has_more: false, cursor: null}); await more;
    assert.equal(historyMessage.textContent, config.noMoreHistoryLabel);
    index = pending.length;
    more = historyPopup.loadMoreHistory(moreButton);
    const beforeDetachedResponse = historyValues.innerHTML;
    historyRow.popupRoot = null;
    pending[index].resolve({body: 'Detached response', has_more: false, cursor: null}); await more;
    assert.equal(historyValues.innerHTML, beforeDetachedResponse, 'Replaced item rows ignore old responses');
    historyRow.popupRoot = historyRoot;
    index = pending.length;
    more = historyPopup.loadMoreHistory(moreButton);
    reloading = historyPopup.refreshTab();
    await more;
    assert(pending[index].options.signal.aborted && historyPopup.historyRequests.size === 0,
        'Refreshing discards pending history requests');
    pending[index + 1].resolve({body: 'Fresh overview'}); await reloading;
    historyRow.popupRoot = null;
    index = pending.length;
    await historyPopup.loadMoreHistory(moreButton);
    assert.equal(pending.length, index, 'Detached item previews cannot fetch more history');
    historyRow.popupRoot = historyRoot;
    more = historyPopup.loadMoreHistory(moreButton);
    historyRoot.dialogue.dispatch('dialogue.close'); await more;
    assert(pending[index].options.signal.aborted && historyPopup.historyRequests.size === 0,
        'Closing cancels item history requests');

    const eventsRoot = popupRoot(); roots.set('timeline-more', eventsRoot);
    const eventsPopup = Popup.mount({...config, rootId: 'timeline-more'});
    const initialEventsBody = actionFixtures['timeline-page']?.response.body || '';
    index = pending.length;
    const initialEvents = eventsPopup.activateTab('timeline');
    pending[index].resolve({body: initialEventsBody}); await initialEvents;
    const eventsSection = new Panel(''); eventsSection.popupRoot = eventsRoot;
    const initialRows = (initialEventsBody.match(/<tr[^>]*data-timeline-event="\d+"[^>]*>.*?<\/tr>/gs) || []).join('');
    const eventRows = {innerHTML: initialRows, insertAdjacentHTML(position, html) { this.innerHTML += html; }};
    const eventsMessage = {textContent: ''};
    eventsSection.querySelector = selector => selector === '[data-timeline-table] tbody' ? eventRows : eventsMessage;
    const eventsMore = new Panel(''); eventsMore.popupRoot = eventsRoot;
    eventsMore.dataset = {beforeEventid: '81', beforeClock: '100'};
    eventsMore.hasAttribute = name => name === 'data-timeline-more';
    eventsMore.closest = selector => selector === '[data-timeline-events]' ? eventsSection : eventsMore;
    index = pending.length;
    eventsRoot.dispatch('click', {target: eventsMore, preventDefault() {}});
    let moreEvents = eventsPopup.timelineRequest;
    assert(moreEvents && eventsPopup.loadMoreEvents(eventsMore) === moreEvents,
        'The delegated More button deduplicates pending event loads');
    assert.equal(pending[index].options.body.get('tab'), 'timeline_events');
    assert.equal(pending[index].options.body.get('before_eventid'), '81');
    assert.equal(pending[index].options.body.get('before_clock'), '100');
    assert(eventsMore.disabled && !eventsRoot.cancelButton.disabled);
    await eventsPopup.activateTab('overview');
    const moreEventsBody = actionFixtures['timeline-more-page']?.response.body || '<tr data-timeline-event="80"></tr>';
    pending[index].resolve({body: moreEventsBody, has_more: true, cursor: {eventid: '61', clock: 80}}); await moreEvents;
    assert.equal(eventsPopup.activeTab, 'overview', 'Late event pages preserve the selected tab');
    assert.equal(eventRows.innerHTML, initialRows + moreEventsBody, 'More appends rows without replacing previous events');
    if (actionFixtures['timeline-page']) assert.equal((eventRows.innerHTML.match(/data-timeline-event="/g) || []).length, 40);
    assert.equal(eventsMore.dataset.beforeEventid, '61');
    assert(!eventsMore.disabled && !eventsMore.hidden);
    await eventsPopup.activateTab('timeline');
    index = pending.length;
    moreEvents = eventsPopup.loadMoreEvents(eventsMore);
    pending[index].resolve({error: {messages: ['Unable to load recent events']}}); await moreEvents;
    assert.equal(eventsMessage.textContent, 'Unable to load recent events');
    assert(!eventsMore.disabled && eventRows.innerHTML === initialRows + moreEventsBody,
        'A failed event page retains loaded rows and a retryable More button');
    index = pending.length;
    moreEvents = eventsPopup.loadMoreEvents(eventsMore);
    const finalEventsBody = actionFixtures['timeline-final-page']?.response.body || '<tr data-timeline-event="60"></tr>';
    pending[index].resolve({body: finalEventsBody, has_more: false, cursor: {eventid: '58', clock: 70}}); await moreEvents;
    assert(eventsMore.hidden && eventsMessage.textContent === config.noMoreEventsLabel,
        'The final partial page hides More and announces exhaustion');
    index = pending.length;
    moreEvents = eventsPopup.loadMoreEvents(eventsMore);
    reloading = eventsPopup.refreshTab(); await moreEvents;
    assert(pending[index].options.signal.aborted && eventsPopup.timelineRequest === null,
        'Refresh aborts event pagination and resets its request state');
    pending[index + 1].resolve({body: initialEventsBody}); await reloading;
    eventsSection.popupRoot = null;
    index = pending.length;
    await eventsPopup.loadMoreEvents(eventsMore);
    assert.equal(pending.length, index, 'Detached Timeline controls cannot load another page');
    eventsSection.popupRoot = eventsRoot;
    moreEvents = eventsPopup.loadMoreEvents(eventsMore);
    eventsRoot.dialogue.dispatch('dialogue.close'); await moreEvents;
    assert(pending[index].options.signal.aborted && eventsPopup.timelineRequest === null,
        'Close cancels pending event pages');

    const patternRoot = popupRoot(); roots.set('pattern-controls', patternRoot);
    const patternPopup = Popup.mount({...config, rootId: 'pattern-controls'});
    const patternResponse = fragments.patterns.response;
    index = pending.length;
    loading = patternPopup.activateTab('patterns');
    pending[index].resolve(patternResponse); await loading;
    const patternForm = new Panel(''); patternForm.popupRoot = patternRoot;
    patternForm.matches = selector => selector === '[data-pattern-controls]';
    const patternPeriod = {value: '12', disabled: false,
        matches: selector => selector.includes('[data-pattern-period]'), closest: () => patternForm};
    const patternCustom = {hidden: true}, patternApply = {disabled: false, hidden: true};
    const patternFrom = {value: 'now-6M'}, patternTo = {value: 'now'};
    const patternMessage = {textContent: ''};
    const patternFields = {'[name="pattern_period"]': patternPeriod, '[data-pattern-custom-range]': patternCustom,
        '[data-pattern-from] input': patternFrom, '[data-pattern-to] input': patternTo,
        '[type="submit"]': patternApply, '[data-pattern-message]': patternMessage};
    patternForm.querySelector = selector => patternFields[selector];
    for (const period of ['1', '3', '6', '12']) {
        index = pending.length;
        patternPeriod.value = period;
        patternRoot.dispatch('change', {target: patternPeriod});
        const applying = patternPopup.patternRequest;
        assert(applying && patternApply.hidden && patternCustom.hidden, 'Pattern presets apply immediately without Apply');
        assert.equal(pending[index].options.body.get('pattern_period'), period);
        assert(patternPeriod.disabled && !patternRoot.cancelButton.disabled);
        pending[index].resolve(patternResponse); await applying;
        assert(!patternPeriod.disabled && !patternApply.disabled);
    }
    patternPeriod.value = 'custom';
    index = pending.length;
    patternRoot.dispatch('change', {target: patternPeriod});
    assert.equal(pending.length, index, 'Custom waits for Apply');
    assert(!patternApply.hidden && !patternCustom.hidden);
    patternRoot.dispatch('submit', {target: patternForm, preventDefault() {}, stopPropagation() {}});
    let patternLoading = patternPopup.patternRequest;
    assert.equal(patternPopup.updatePatterns(patternForm), patternLoading, 'Duplicate custom submissions share a request');
    assert.equal(pending[index].options.body.get('pattern_from'), 'now-6M');
    assert.equal(pending[index].options.body.get('pattern_to'), 'now');
    pending[index].resolve({error: {messages: ['Invalid pattern period']}}); await patternLoading;
    assert.equal(patternMessage.textContent, 'Invalid pattern period');
    assert.equal(patternPopup.panels.get('patterns').innerHTML, patternResponse.body,
        'Failed periods keep the existing heatmap and filters visible');
    assert.equal(patternFrom.value, 'now-6M');
    index = pending.length;
    patternLoading = patternPopup.updatePatterns(patternForm);
    pending[index].resolve({body: 'Incomplete replacement', pattern_data: {}}); await patternLoading;
    assert.equal(patternMessage.textContent, 'Invalid pattern response');
    assert.equal(patternPopup.panels.get('patterns').innerHTML, patternResponse.body,
        'Malformed pattern data is rejected before replacing the working heatmap');
    index = pending.length;
    patternLoading = patternPopup.updatePatterns(patternForm);
    await patternPopup.activateTab('overview');
    pending[index].resolve(patternResponse); await patternLoading;
    assert.equal(patternPopup.activeTab, 'overview', 'A late response never switches tabs');
    await patternPopup.activateTab('patterns');
    index = pending.length;
    reloading = patternPopup.refreshTab();
    assert.equal(pending[index].options.body.get('pattern_period'), 'custom');
    assert.equal(pending[index].options.body.get('pattern_from'), 'now-6M', 'Refresh retains the active pattern period');
    pending[index].resolve(patternResponse); await reloading;
    index = pending.length;
    loading = patternPopup.activateTab('overview');
    pending[index].resolve({body: 'Overview'}); await loading;
    index = pending.length;
    reloading = patternPopup.refreshTab();
    pending[index].resolve({body: 'Overview refreshed'}); await reloading;
    assert.equal(Object.keys(patternPopup.patternOptions).length, 0, 'Refreshing another tab discards the cached pattern period');
    index = pending.length;
    loading = patternPopup.activateTab('patterns');
    pending[index].resolve(patternResponse); await loading;
    index = pending.length;
    patternLoading = patternPopup.updatePatterns(patternForm);
    reloading = patternPopup.refreshTab();
    await patternLoading;
    assert(pending[index].options.signal.aborted, 'Refresh cancels pending pattern changes');
    pending[index + 1].resolve(patternResponse); await reloading;
    index = pending.length;
    patternLoading = patternPopup.updatePatterns(patternForm);
    patternRoot.dialogue.dispatch('dialogue.close'); await patternLoading;
    assert(pending[index].options.signal.aborted && patternPopup.patternRequest === null);

    const graphRoot = popupRoot(); roots.set('graph-controls', graphRoot);
    const graphPopup = Popup.mount({...config, rootId: 'graph-controls'});
    index = pending.length;
    loading = graphPopup.activateTab('graphs');
    const graphBody = fragments.graphs?.response.body || 'Native graph controls';
    pending[index].resolve({body: graphBody}); await loading;
    const graphForm = new Panel(''); graphForm.popupRoot = graphRoot;
    graphForm.matches = selector => selector === '[data-graph-controls]';
    const periodInput = {value: 'before', disabled: false,
        matches: selector => selector.includes('[data-graph-period]'), closest: () => graphForm};
    const customRange = {hidden: true};
    const fromInput = {value: 'now-6h'};
    const toInput = {value: 'now'};
    const graphSubmit = {disabled: false, hidden: true};
    const graphMessage = {textContent: ''};
    const graphFields = {
        '[name="graph_period"]': periodInput,
        '[data-graph-custom-range]': customRange, '[data-graph-from] input': fromInput,
        '[data-graph-to] input': toInput, '[type="submit"]': graphSubmit, '[data-graph-message]': graphMessage
    };
    graphForm.querySelector = selector => graphFields[selector];
    index = pending.length;
    periodInput.value = 'custom';
    graphRoot.dispatch('change', {target: periodInput});
    assert(!customRange.hidden, 'Custom period reveals the native date fields');
    assert(!graphSubmit.hidden, 'Custom period reveals Apply');
    assert.equal(pending.length, index, 'Selecting Custom waits for an explicit Apply');
    for (const preset of ['before', 'around', 'duration', 'day']) {
        index = pending.length;
        periodInput.value = preset;
        graphRoot.dispatch('change', {target: periodInput});
        const applied = graphPopup.graphRequest;
        assert(customRange.hidden, 'Preset periods hide custom date fields');
        assert(graphSubmit.hidden, 'Preset periods hide Apply');
        assert(applied && pending.length === index + 1, 'Selecting a preset immediately reloads the graphs');
        assert.equal(pending[index].options.body.get('graph_period'), preset);
        assert.equal(pending[index].options.body.get('graph_from'), null);
        assert(periodInput.disabled && graphSubmit.disabled && !graphRoot.cancelButton.disabled,
            'A pending graph update prevents conflicting selections while Cancel stays available');
        pending[index].resolve({body: graphBody}); await applied;
        assert(!periodInput.disabled && !graphSubmit.disabled);
        assert.equal(graphPopup.graphOptions.graph_period, preset);
    }
    const layoutInput = {value: 'items', disabled: false,
        matches: selector => selector.includes('[data-graph-layout]'), closest: () => graphForm};
    graphFields['[name="graph_layout"]'] = layoutInput;
    for (const layout of ['combined', 'items']) {
        index = pending.length;
        layoutInput.value = layout;
        graphRoot.dispatch('change', {target: layoutInput});
        const applied = graphPopup.graphRequest;
        assert(applied && pending.length === index + 1, 'Changing Display immediately reloads the graphs');
        assert.equal(pending[index].options.body.get('graph_layout'), layout);
        assert.equal(pending[index].options.body.get('graph_period'), 'day');
        assert(graphSubmit.hidden && layoutInput.disabled && periodInput.disabled);
        pending[index].resolve({body: graphBody}); await applied;
        assert(!layoutInput.disabled && !periodInput.disabled);
    }
    periodInput.value = 'custom';
    index = pending.length;
    graphRoot.dispatch('change', {target: periodInput});
    assert(!customRange.hidden && pending.length === index, 'Returning to Custom reveals dates without loading');
    assert(!graphSubmit.hidden);
    layoutInput.value = 'combined';
    graphRoot.dispatch('change', {target: layoutInput});
    const customDisplay = graphPopup.graphRequest;
    assert(customDisplay && !graphSubmit.hidden, 'Changing Display in Custom period applies the current dates');
    assert.equal(pending[index].options.body.get('graph_layout'), 'combined');
    assert.equal(pending[index].options.body.get('graph_from'), 'now-6h');
    assert.equal(pending[index].options.body.get('graph_to'), 'now');
    pending[index].resolve({body: graphBody}); await customDisplay;
    index = pending.length;
    let prevented = false, stopped = false;
    graphRoot.dispatch('submit', {target: graphForm, preventDefault() { prevented = true; }, stopPropagation() { stopped = true; }});
    let graphLoading = graphPopup.graphRequest;
    assert(prevented && stopped, 'Graph forms update this tab without submitting the popup');
    assert.equal(graphPopup.updateGraphs(graphForm), graphLoading, 'Repeated Apply clicks share the pending request');
    assert(graphSubmit.disabled && !graphRoot.cancelButton.disabled);
    assert.equal(pending[index].options.body.get('graph_from'), 'now-6h');
    assert.equal(pending[index].options.body.get('graph_to'), 'now');
    pending[index].resolve({error: {messages: ['Invalid graph period']}}); await graphLoading;
    assert.equal(graphMessage.textContent, 'Invalid graph period');
    assert.equal(graphPopup.panels.get('graphs').innerHTML, graphBody, 'Invalid periods retain the existing graphs and form');
    assert(!graphSubmit.disabled && !periodInput.disabled && fromInput.value === 'now-6h');
    index = pending.length;
    graphLoading = graphPopup.updateGraphs(graphForm);
    await graphPopup.activateTab('overview');
    pending[index].resolve({body: 'Updated custom graphs'}); await graphLoading;
    assert.equal(graphPopup.activeTab, 'overview', 'Late graph updates must preserve the selected tab');
    assert.equal(graphPopup.graphOptions.graph_period, 'custom');
    await graphPopup.activateTab('graphs');
    index = pending.length;
    reloading = graphPopup.refreshTab();
    assert.equal(pending[index].options.body.get('graph_from'), 'now-6h', 'Refresh preserves the active graph period');
    pending[index].resolve({body: 'Refreshed custom graphs'}); await reloading;
    index = pending.length;
    loading = graphPopup.activateTab('overview');
    pending[index].resolve({body: 'Overview'}); await loading;
    index = pending.length;
    reloading = graphPopup.refreshTab();
    pending[index].resolve({body: 'Overview refreshed'}); await reloading;
    assert.equal(Object.keys(graphPopup.graphOptions).length, 0, 'Refreshing another tab discards graph options');
    index = pending.length;
    loading = graphPopup.activateTab('graphs');
    assert.equal(pending[index].options.body.get('graph_period'), null);
    pending[index].resolve({body: graphBody}); await loading;
    periodInput.value = 'before';
    index = pending.length;
    graphLoading = graphPopup.updateGraphs(graphForm);
    reloading = graphPopup.refreshTab();
    await graphLoading;
    assert(pending[index].options.signal.aborted, 'Refresh cancels an in-flight graph update');
    pending[index + 1].resolve({body: graphBody}); await reloading;
    index = pending.length;
    graphLoading = graphPopup.updateGraphs(graphForm);
    graphRoot.dialogue.dispatch('dialogue.close'); await graphLoading;
    assert(pending[index].options.signal.aborted && graphPopup.graphRequest === null);
    assert.equal(graphRoot.handlers.get('submit').size, 0);
    assert.equal(graphRoot.handlers.get('change').size, 0);

    const timelineRoot = popupRoot(); roots.set('initial-timeline', timelineRoot);
    index = pending.length;
    const initialTimeline = Popup.mount({...config, rootId: 'initial-timeline', initialTab: 'timeline'});
    assert.equal(initialTimeline.activeTab, 'timeline', 'Recurrence links open directly on Timeline');
    assert.equal(pending.length, index + 1);
    assert.equal(pending[index].options.body.get('tab'), 'timeline');
    pending[index].resolve({body: 'Initial timeline'}); await initialTimeline.tabs.get('timeline').promise;
    initialTimeline.destroy();

    function tableFixture() {
        const header = new Element('tr');
        header.appendChild(new Element('th')); header.appendChild(new Element('th'));
        const problem = new Element('tr');
        problem.problemData = {eventid: '1', triggerid: '7', hostid: '11'};
        problem.appendChild(new Element('td')); problem.appendChild(new Element('td'));
        const separator = new Element('tr'); const span = new Element('td'); span.colSpan = 2; separator.appendChild(span);
        const table = {header, problem, separator, bodyRows: [problem, separator],
            querySelector(selector) { return selector === 'tbody' ? {rows: this.bodyRows} : header; }};
        return table;
    }
    const table = tableFixture();
    const selectors = [];
    const recurrenceRequests = [];
    const openedPopups = [];
    let viewport;
    const dom = {window: {}, AbortController, URLSearchParams,
        IntersectionObserver: class {
            constructor(callback) { this.callback = callback; this.targets = new Set(); viewport = this; }
            observe(target) { this.targets.add(target); }
            unobserve(target) { this.targets.delete(target); }
            disconnect() { this.targets.clear(); }
        },
        PopUp(...args) { openedPopups.push(args); },
        fetch(url, options) {
            return new Promise((resolve, reject) => {
                recurrenceRequests.push({url, options, resolve: result => resolve({ok: true, json: async () => result})});
                options.signal.addEventListener('abort', () => reject(Object.assign(new Error('Cancelled'), {name: 'AbortError'})));
            });
        }, document: {
        addEventListener(name, callback) { callback(); },
        createElement(name) { return new Element(name); },
        querySelectorAll(selector) { selectors.push(selector); return selector === '.dashboard-widget-problems table.list-table' ? [table] : []; }
    }};
    vm.createContext(dom);
    vm.runInContext(asset.replace('new AnalistProblem();', 'window.TestAnalistProblem = AnalistProblem;'), dom);
    const controller = Object.create(dom.window.TestAnalistProblem.prototype);
    controller.events = new AbortController();
    controller.initRecurrences();
    const scheduled = new Map();
    let timerId = 0;
    dom.setTimeout = callback => { scheduled.set(++timerId, callback); return timerId; };
    dom.clearTimeout = id => scheduled.delete(id);
    controller.scheduleUpdate(); controller.scheduleUpdate();
    assert.equal(scheduled.size, 1, 'Repeated DOM updates must share one debounce timer');
    let disconnected = false;
    controller.observer = {disconnect() { disconnected = true; }};
    controller.suspend();
    assert(disconnected && scheduled.size === 0, 'Suspending removes the observer and timer');
    controller.suspended = false;
    controller.extractProblemDataFromMenuPopup = row => row.problemData || null;
    controller.addLTSButtonToDashboardWidgets();
    controller.addLTSButtonToDashboardWidgets();
    assert.equal(table.header.cells.length, 4, 'Repeated decoration must not duplicate headers');
    assert.equal(table.header.cells[2].className, 'analist-recurrences-header');
    assert.equal(table.header.cells[3].textContent, 'Details');
    assert.equal(table.problem.cells.length, 4, 'Repeated decoration must not duplicate cells');
    assert.equal(table.separator.cells[0].colSpan, 4, 'Separators must span both added columns');
    assert(selectors.every(selector => selector === '.dashboard-widget-problems table.list-table'), 'Only problem widgets may be selected');
    const unrelated = tableFixture(); unrelated.problem.problemData = null;
    controller.decorateProblemTable(unrelated);
    assert.equal(unrelated.header.cells.length, 2, 'Unrelated tables must remain untouched');
    const empty = tableFixture(); empty.problem.problemData = null;
    empty.querySelector = selector => selector === 'tbody' ? {rows: [empty.separator]} : empty.header;
    controller.decorateProblemTable(empty, true);
    assert.equal(empty.header.cells.length, 2, 'Empty widgets must not gain a stray header');

    function extraProblem(eventid, triggerid) {
        const row = new Element('tr');
        row.problemData = {eventid, triggerid, hostid: '11'};
        row.appendChild(new Element('td')); row.appendChild(new Element('td'));
        table.bodyRows.push(row); return row;
    }
    const repeated = extraProblem('3', '7');
    const another = extraProblem('4', '8');
    const offscreen = extraProblem('5', '9');
    controller.decorateProblemTable(table, true);
    assert.equal(recurrenceRequests.length, 0, 'Decorating the table must not fetch counts before cells become visible');
    const recurrenceCell = row => row.querySelector('.analist-recurrences-cell');
    assert(viewport.targets.has(recurrenceCell(offscreen)));
    viewport.callback([table.problem, repeated, another].map(row => ({target: recurrenceCell(row), isIntersecting: true})));
    assert.equal(scheduled.size, 1, 'Visible rows share a single deferred count batch');
    const runBatchTimer = () => {
        const callbacks = [...scheduled.values()]; scheduled.clear(); callbacks.forEach(callback => callback());
    };
    const finishBatch = async () => {
        for (let attempt = 0; attempt < 20 && controller.recurrenceRequest; attempt++) await Promise.resolve();
        assert.equal(controller.recurrenceRequest, null);
    };
    runBatchTimer();
    assert.equal(recurrenceRequests.length, 1);
    assert.equal(recurrenceRequests[0].options.body.get('tab'), 'recurrences');
    assert.deepEqual([...recurrenceRequests[0].options.body.getAll('recurrence_eventids[]')], ['1', '4'],
        'Rows sharing a trigger use one representative event in the batch');
    recurrenceRequests[0].resolve({counts: {'1': '12', '4': '0'}}); await finishBatch();
    assert.equal(recurrenceCell(table.problem).analistRecurrences.button.textContent, '12');
    assert.equal(recurrenceCell(repeated).analistRecurrences.button.textContent, '12');
    assert.equal(recurrenceCell(another).analistRecurrences.button.textContent, '0', 'Zero is a valid count');
    assert.equal(recurrenceCell(offscreen).analistRecurrences.button.textContent, '…');
    const click = {preventDefault() {}, stopPropagation() {}};
    recurrenceCell(repeated).analistRecurrences.button.dispatch('click', click);
    assert.equal(openedPopups[0][0], 'problemanalist.view');
    const recurrencePopupParams = new URLSearchParams(openedPopups[0][1]);
    assert.equal(recurrencePopupParams.get('eventid'), '3', 'Each count opens its own row event');
    assert.equal(recurrencePopupParams.get('initial_tab'), 'timeline');
    table.problem.querySelector('.js-lts-button-widget').dispatch('click', click);
    assert.equal(new URLSearchParams(openedPopups[1][1]).get('initial_tab'), null,
        'The existing Details button keeps its default tab');
    controller.decorateProblemTable(table, true);
    assert.equal(recurrenceRequests.length, 1, 'Fresh counts survive repeated decoration');
    viewport.callback([{target: recurrenceCell(offscreen), isIntersecting: true}]); runBatchTimer();
    recurrenceRequests[1].resolve({error: {messages: ['Unable to count events']}}); await finishBatch();
    const retry = recurrenceCell(offscreen).analistRecurrences.button;
    assert.equal(retry.textContent, 'Retry'); assert(!retry.disabled);
    retry.dispatch('click', click); runBatchTimer();
    recurrenceRequests[2].resolve({counts: {'5': '2'}}); await finishBatch();
    assert.equal(retry.textContent, '2', 'Failed lazy loads remain retryable');
    const batchRows = Array.from({length: 41}, (_, number) => extraProblem(String(100 + number), String(100 + number)));
    controller.decorateProblemTable(table, true);
    const batchStart = recurrenceRequests.length;
    viewport.callback(batchRows.map(row => ({target: recurrenceCell(row), isIntersecting: true}))); runBatchTimer();
    const firstBatchIds = recurrenceRequests[batchStart].options.body.getAll('recurrence_eventids[]');
    assert.equal(firstBatchIds.length, 40, 'Large visible tables use bounded count batches');
    recurrenceRequests[batchStart].resolve({counts: Object.fromEntries(firstBatchIds.map(id => [id, '1']))});
    for (let attempt = 0; attempt < 20 && recurrenceRequests.length === batchStart + 1; attempt++) await Promise.resolve();
    assert.equal(recurrenceRequests.length, batchStart + 2, 'Remaining visible rows load after the active batch finishes');
    const secondBatchIds = recurrenceRequests[batchStart + 1].options.body.getAll('recurrence_eventids[]');
    assert.equal(secondBatchIds.length, 1);
    recurrenceRequests[batchStart + 1].resolve({counts: {[secondBatchIds[0]]: '2'}}); await finishBatch();
    assert.equal(recurrenceCell(batchRows.at(-1)).analistRecurrences.button.textContent, '2');
    const detached = extraProblem('6', '10'); controller.decorateProblemTable(table, true);
    const detachedCell = recurrenceCell(detached); detachedCell.isConnected = false;
    controller.pruneRecurrences();
    viewport.callback([{target: detachedCell, isIntersecting: true}]);
    assert.equal(scheduled.size, 0, 'Late viewport notifications must not fetch detached rows');
    assert(!controller.recurrenceGroups.has('trigger:10'));
    const waiting = extraProblem('7', '11'); controller.decorateProblemTable(table, true);
    viewport.callback([{target: recurrenceCell(waiting), isIntersecting: true}]); runBatchTimer();
    const lastCountRequest = recurrenceRequests.at(-1);
    controller.suspend(); await Promise.resolve(); await Promise.resolve();
    assert(lastCountRequest.options.signal.aborted && viewport.targets.size === 0,
        'Page suspension cancels pending counts and disconnects the viewport observer');
    console.log('JavaScript regression checks passed.');
}

if (typeof process !== 'undefined' && process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const root = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
    const container = process.env.ZABBIX_CONTAINER;
    const args = container ? ['exec', container, 'php', '/usr/share/zabbix/modules/Custom-Problem-Analysis/tests/emit-popup-script.php']
        : [path.join(root, 'tests/emit-popup-script.php')];
    function render(tab, scenario = 'readonly') {
        const result = spawnSync(container ? 'docker' : 'php', [...args, tab, scenario], {encoding: 'utf8'});
        if (result.status !== 0 || result.stderr) throw new Error(result.stderr || 'PHP fixture failed');
        return JSON.parse(result.stdout);
    }
    const fragments = Object.fromEntries(['host', 'timeline', 'patterns', 'graphs', 'update'].map(tab => [tab, render(tab)]));
    const actionFixtures = Object.fromEntries(['readonly', 'permitted', 'acknowledged', 'ack-only', 'fragment', 'recovered', 'closing',
        'details', 'recovery-unavailable', 'timeline-start']
        .map(scenario => [scenario, render('overview', scenario)]));
    for (const scenario of ['permitted', 'readonly', 'comment-only']) {
        actionFixtures['update-' + scenario] = render('update', scenario);
    }
    actionFixtures['history-preview'] = render('overview', 'history-preview');
    actionFixtures['history-fragment'] = render('history');
    for (const scenario of ['graphs-many', 'graphs-combined', 'graphs-empty', 'graphs-custom', 'graphs-before']) {
        actionFixtures[scenario] = render('graphs', scenario);
    }
    actionFixtures['timeline-page'] = render('timeline', 'timeline-page');
    actionFixtures['timeline-more-page'] = render('timeline_events', 'timeline-more-page');
    actionFixtures['timeline-final-page'] = render('timeline_events', 'timeline-final-page');
    for (const scenario of ['patterns-rich', 'patterns-empty', 'patterns-custom', 'patterns-short']) {
        actionFixtures[scenario] = render('patterns', scenario);
    }
    await runRegression(render('overview'), fs.readFileSync(path.join(root, 'assets/js/analist-problem.js'), 'utf8'),
        fs.readFileSync(path.join(root, 'assets/js/analist-popup.js'), 'utf8'), fragments, actionFixtures);
}
