import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {spawnSync} from 'node:child_process';
import {fileURLToPath, URLSearchParams} from 'node:url';

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
    assert(!fixture.response.body.includes('chart.php'), 'Graphs must not load in the initial popup');
    assert(!fixture.response.body.includes('Monthly Comparison'), 'Comparison belongs only to Timeline');
    for (const [tab, fragment] of Object.entries(fragments)) {
        assert.equal(fragment.response.body.includes('Monthly Comparison'), tab === 'timeline');
        assert.equal(fragment.response.body.includes('chart.php'), tab === 'graphs');
        assert.equal(fragment.inline, '', 'Lazy fragments must not mount additional popup instances');
    }
    if (fragments.patterns) assert.equal(fragments.patterns.response.pattern_data.eventClocks.length, 1);
    if (fragments.graphs) {
        assert(fragments.graphs.response.body.includes('width="800"'));
        assert(fragments.graphs.response.body.includes('height="300"'), 'Graph images reserve space before loading');
        assert(!fragments.graphs.response.body.includes('file:///'), 'Charts use frontend URLs');
    }
    for (const [scenario, rendered] of Object.entries(actionFixtures)) {
        assert.equal(rendered.response.body.includes('data-problem-action="close"'),
            ['permitted', 'acknowledged'].includes(scenario), 'Close row appears only when allowed');
        assert.equal(rendered.response.body.includes('data-problem-action="acknowledge"'),
            ['permitted', 'ack-only'].includes(scenario));
        assert.equal(rendered.response.body.includes('data-problem-action="unacknowledge"'), scenario === 'acknowledged');
    }
    if (actionFixtures.fragment) {
        assert.equal(actionFixtures.fragment.inline, '', 'Overview refresh must return a fragment');
        assert(!actionFixtures.fragment.response.body.includes('role="tablist"'));
    }
    if (actionFixtures.recovered) {
        assert(actionFixtures.recovered.response.body.includes('Recovery time'));
        assert(!actionFixtures.recovered.response.body.includes('data-problem-action="close"'));
    }
    if (actionFixtures.closing) {
        assert(actionFixtures.closing.response.body.includes('Recovery time'));
        assert(actionFixtures.closing.response.body.includes('Pending'));
        assert(!actionFixtures.closing.response.body.includes('data-problem-action="close"'));
    }
    new vm.Script(popupAsset);
    const exportsSource = popupAsset.replace('return {displayServicesTree, displayNoServicesMessage, selectService};',
        'return {displayServicesTree, displayNoServicesMessage, selectService, createServiceCard, createHierarchyPath, createServiceSlaInfo, getServiceStatusText, getServiceStatusClass};')
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
    for (const [value, label] of Object.entries(config.statusLabels)) {
        assert.equal(serviceUi.getServiceStatusText(Number(value)), label);
    }
    assert.equal(serviceUi.getServiceStatusClass(0), 'status-not-classified');
    assert.equal(serviceUi.getServiceStatusClass(2), 'status-warning');
    assert.equal(serviceUi.getServiceStatusClass(3), 'status-average');

    // Exercise the actual aggregation and last-week functions without browser timezone conversions.
    const pattern = {d: {eventSlots: {100: {hour: 23, weekday: 1, month: '2026-03', day: 30},
        200: {hour: 0, weekday: 2, month: '2026-03', day: 31}}, lastWeekStart: 50, lastWeekEnd: 150},
        monthKeys: ['2026-03'], clocks: [100, 200]};
    vm.createContext(pattern);
    let start = popupAsset.indexOf('var computeAggregates =');
    let end = popupAsset.indexOf('var renderHeatmap =', start);
    vm.runInContext(popupAsset.slice(start, end), pattern);
    const aggregates = pattern.computeAggregates([100, 200]);
    assert.equal(aggregates.hourly[23], 1);
    assert.equal(aggregates.hourly[0], 1);
    assert.equal(aggregates.monthly[0], 2);
    start = popupAsset.indexOf('var countSameSlotLastWeek =');
    end = popupAsset.indexOf('var updateFilterBar =', start);
    vm.runInContext(popupAsset.slice(start, end), pattern);
    assert.equal(pattern.countSameSlotLastWeek(1, 23), 1);
    assert.equal(pattern.countSameDayLastWeek(2), 0);

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
        replaceChildren(value) { this.textContent = value; }
    }
    function popupRoot() {
        const root = new Target();
        root.isConnected = true;
        root.dialogue = new Target();
        root.dialogue.dataset = {dialogueid: String(roots.size)};
        root.panels = ['overview', 'host', 'timeline', 'patterns', 'graphs', 'update', 'services'].map(key => new Panel(key));
        root.buttons = root.panels.map(panel => ({dataset: {tabTarget: panel.dataset.tab},
            setAttribute() {}, parentElement: {classList: {toggle() {}}}, focus() {}}));
        root.actionButtons = [{disabled: false}, {disabled: false}];
        root.message = new Panel('');
        root.querySelector = () => root.message;
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
    const Popup = context.window.AnalistProblemPopup;
    const rootA = popupRoot(); roots.set('a', rootA);
    let layouts = 0;
    overlays.set(rootA.dialogue.dataset.dialogueid, {$dialogue: [rootA.dialogue], centerDialog() { layouts++; }});
    const popupA = Popup.mount({...config, rootId: 'a'});
    flushFrames();
    assert.equal(pending.length, 0, 'Opening Overview must not fetch other tabs');
    const first = popupA.activateTab('timeline');
    assert.equal(popupA.loadTab('timeline'), first, 'Concurrent visits reuse the pending request');
    assert.equal(pending.length, 1);
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
    Object.defineProperty(overviewD, 'innerHTML', {
        get() { return overviewMarkup; },
        set(value) { overviewMarkup = value; pendingRow = ''; }
    });
    overviewD.querySelector = selector => {
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
    class Element {
        constructor(tagName) { this.tagName = tagName.toUpperCase(); this.children = []; this.className = ''; this.colSpan = 1; }
        appendChild(child) { child.parent = this; this.children.push(child); return child; }
        remove() { this.parent.children = this.parent.children.filter(child => child !== this); }
        addEventListener() {}
        querySelector(selector) {
            if (selector.includes('data-menu-popup')) return this.problemData ? {} : null;
            const classes = selector.match(/\.([\w-]+)/g) || [];
            const all = this.children.flatMap(child => [child, ...child.children]);
            return all.find(child => classes.some(name => child.className.split(' ').includes(name.slice(1)))) || null;
        }
        get cells() { return this.children.filter(child => ['TD', 'TH'].includes(child.tagName)); }
    }
    function tableFixture() {
        const header = new Element('tr');
        header.appendChild(new Element('th')); header.appendChild(new Element('th'));
        const problem = new Element('tr');
        problem.problemData = {eventid: '1', triggerid: '7', hostid: '11'};
        problem.appendChild(new Element('td')); problem.appendChild(new Element('td'));
        const separator = new Element('tr'); const span = new Element('td'); span.colSpan = 2; separator.appendChild(span);
        const table = {header, problem, separator,
            querySelector(selector) { return selector === 'tbody' ? {rows: [problem, separator]} : header; }};
        return table;
    }
    const table = tableFixture();
    const selectors = [];
    const dom = {window: {}, document: {
        addEventListener(name, callback) { callback(); },
        createElement(name) { return new Element(name); },
        querySelectorAll(selector) { selectors.push(selector); return selector === '.dashboard-widget-problems table.list-table' ? [table] : []; }
    }};
    vm.createContext(dom);
    vm.runInContext(asset.replace('new AnalistProblem();', 'window.TestAnalistProblem = AnalistProblem;'), dom);
    const controller = Object.create(dom.window.TestAnalistProblem.prototype);
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
    controller.extractProblemDataFromMenuPopup = row => row.problemData || null;
    controller.addLTSButtonToDashboardWidgets();
    controller.addLTSButtonToDashboardWidgets();
    assert.equal(table.header.cells.length, 3, 'Repeated decoration must not duplicate headers');
    assert.equal(table.problem.cells.length, 3, 'Repeated decoration must not duplicate buttons');
    assert.equal(table.separator.cells[0].colSpan, 3, 'Separators must span the added column');
    assert(selectors.every(selector => selector === '.dashboard-widget-problems table.list-table'), 'Only problem widgets may be selected');
    const unrelated = tableFixture(); unrelated.problem.problemData = null;
    controller.decorateProblemTable(unrelated);
    assert.equal(unrelated.header.cells.length, 2, 'Unrelated tables must remain untouched');
    const empty = tableFixture(); empty.problem.problemData = null;
    empty.querySelector = selector => selector === 'tbody' ? {rows: [empty.separator]} : empty.header;
    controller.decorateProblemTable(empty, true);
    assert.equal(empty.header.cells.length, 2, 'Empty widgets must not gain a stray header');
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
    const actionFixtures = Object.fromEntries(['readonly', 'permitted', 'acknowledged', 'ack-only', 'fragment', 'recovered', 'closing']
        .map(scenario => [scenario, render('overview', scenario)]));
    await runRegression(render('overview'), fs.readFileSync(path.join(root, 'assets/js/analist-problem.js'), 'utf8'),
        fs.readFileSync(path.join(root, 'assets/js/analist-popup.js'), 'utf8'), fragments, actionFixtures);
}
