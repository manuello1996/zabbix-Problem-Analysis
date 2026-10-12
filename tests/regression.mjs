import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {spawnSync} from 'node:child_process';
import {fileURLToPath, URLSearchParams} from 'node:url';

export async function runRegression(fixture, asset) {
    const {inline} = fixture;
    new vm.Script(inline);
    new vm.Script(asset);
    const context = {window: {}, URLSearchParams};
    vm.createContext(context);
    const dataStart = inline.indexOf('window.currentEventData =');
    const dataEnd = inline.indexOf('// Function to create D3.js', dataStart);
    vm.runInContext(inline.slice(dataStart, dataEnd), context);
    assert.equal(context.window.currentEventData.hostname, fixture.host.name);
    assert.equal(context.window.currentEventData.problem_name, fixture.event.name);

    const serviceStart = inline.indexOf('function escapeServiceHtml');
    const serviceEnd = inline.indexOf('window.loadImpactedServices =', serviceStart);
    vm.runInContext(inline.slice(serviceStart, serviceEnd), context);
    const payload = '<img src=x onerror="test()"> & \'quoted\'';
    const service = {serviceid: '1', name: payload, status: -1, has_sla: false};
    const card = context.createServiceCard(service, true);
    assert(!card.includes('<img'), 'Service names must be escaped');
    assert(card.includes('&lt;img'), 'Service name text must be preserved');
    assert(!card.includes('onclick='), 'Service actions must not embed JavaScript');
    const hierarchy = context.createHierarchyPath([{...service, serviceid: '1" onclick="bad()'}]);
    assert(!hierarchy.includes('onclick="bad()'), 'Service IDs must be escaped');
    assert(context.createServiceSlaInfo({...service, has_sla: true, sli: 99, uptime: payload}).includes('&lt;img'));
    assert.equal(context.getServiceStatusText(-1), 'OK');
    assert.equal(context.getServiceStatusText(0), 'Not classified');
    assert.equal(context.getServiceStatusText(1), 'Information');
    assert.equal(context.getServiceStatusText(2), 'Warning');
    assert.equal(context.getServiceStatusText(3), 'Average');
    assert.equal(context.getServiceStatusClass(0), 'status-not-classified');
    assert.equal(context.getServiceStatusClass(2), 'status-warning');
    assert.equal(context.getServiceStatusClass(3), 'status-average');
    let requests = 0;
    context.fetch = async () => { requests++; return {ok: false, status: 503}; };
    await assert.rejects(context.fetchRelatedServices({eventid: '1'}), /503/);
    assert.equal(requests, 1, 'Failures must not retry with an unfiltered service list');
    await assert.rejects(context.fetchRelatedServices({}), /required/);
    assert.equal(requests, 1, 'Missing event IDs must not request services');
    context.fetch = async () => ({ok: true, json: async () => ({success: true, data: []})});
    assert.equal((await context.fetchRelatedServices({eventid: '1'})).length, 0);
    context.fetch = async () => ({ok: true, json: async () => ({success: false, error: {message: 'Denied'}})});
    await assert.rejects(context.fetchRelatedServices({eventid: '1'}), /Denied/);

    // Exercise the actual aggregation and last-week functions without browser timezone conversions.
    const pattern = {d: {eventSlots: {100: {hour: 23, weekday: 1, month: '2026-03', day: 30},
        200: {hour: 0, weekday: 2, month: '2026-03', day: 31}}, lastWeekStart: 50, lastWeekEnd: 150},
        monthKeys: ['2026-03'], clocks: [100, 200]};
    vm.createContext(pattern);
    let start = inline.indexOf('var computeAggregates =');
    let end = inline.indexOf('var renderHeatmap =', start);
    vm.runInContext(inline.slice(start, end), pattern);
    const aggregates = pattern.computeAggregates([100, 200]);
    assert.equal(aggregates.hourly[23], 1);
    assert.equal(aggregates.hourly[0], 1);
    assert.equal(aggregates.monthly[0], 2);
    start = inline.indexOf('var countSameSlotLastWeek =');
    end = inline.indexOf('var updateFilterBar =', start);
    vm.runInContext(inline.slice(start, end), pattern);
    assert.equal(pattern.countSameSlotLastWeek(1, 23), 1);
    assert.equal(pattern.countSameDayLastWeek(2), 0);

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
    const result = spawnSync(container ? 'docker' : 'php', args, {encoding: 'utf8'});
    if (result.status !== 0 || result.stderr) throw new Error(result.stderr || 'PHP fixture failed');
    await runRegression(JSON.parse(result.stdout), fs.readFileSync(path.join(root, 'assets/js/analist-problem.js'), 'utf8'));
}
