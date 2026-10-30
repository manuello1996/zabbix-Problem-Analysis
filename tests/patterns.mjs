import assert from 'node:assert/strict';
import vm from 'node:vm';

// Small DOM fixtures exercise the real renderer against PHP-emitted button metadata.
class Node {
    constructor(attributes = {}) {
        this.attributes = {...attributes}; this.dataset = {}; this.style = {}; this.handlers = new Map();
        this.textContent = ''; this.hidden = false; this.fields = {};
        for (const [key, value] of Object.entries(attributes)) {
            if (key.startsWith('data-')) this.dataset[key.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = value;
        }
        const classes = new Set();
        this.classList = {toggle(name, value) { value ? classes.add(name) : classes.delete(name); }};
    }
    setAttribute(name, value) { this.attributes[name] = value; }
    hasAttribute(name) { return Object.hasOwn(this.attributes, name); }
    matches(selector) { return selector.split(',').some(part => this.hasAttribute(part.trim().slice(1, -1))); }
    closest(selector) { return this.matches(selector) ? this : null; }
    querySelector(selector) { return this.fields[selector] || null; }
    addEventListener(name, callback, options) {
        this.handlers.set(name, callback);
        options.signal.addEventListener('abort', () => this.handlers.delete(name));
    }
    focus() { this.focused = true; }
}

function patternDOM(fixture) {
    const {body, pattern_data: data} = fixture.response;
    const panel = new Node();
    const buttons = [...body.matchAll(/<button\b([^>]*)>/g)].map(match => {
        const attributes = Object.fromEntries([...match[1].matchAll(/([\w-]+)="([^"]*)"/g)].map(m => [m[1], m[2]]));
        return new Node(attributes);
    });
    const slots = buttons.filter(button => button.hasAttribute('data-pattern-slot'));
    assert.equal(slots.length, 168, 'Compact heatmap has seven rows and twenty-four accessible cells');
    const charts = {};
    for (const [kind, labels] of Object.entries({month: data.monthLabels, day: Array.from({length: 31}, (_, i) => i + 1),
        hour: data.hourLabels, weekday: data.weekLabels})) {
        const items = ['month', 'day'].includes(kind) ? buttons.filter(button => button.hasAttribute('data-pattern-' + kind))
            : labels.map((_, index) => new Node({'data-pattern-index': String(index)}));
        assert.equal(items.length, labels.length);
        for (const item of items) {
            item.fields['[data-pattern-count]'] = new Node();
            item.fields['[data-pattern-bar]'] = new Node();
        }
        charts[kind] = items;
        panel.fields['[data-pattern-bars="' + kind + '"]'] = {querySelectorAll: () => items};
    }
    for (const key of ['filter', 'filter-label', 'status', 'days', 'day-title']) {
        panel.fields['[data-pattern-' + key + ']'] = new Node();
    }
    for (const kind of ['hour', 'weekday']) {
        const body = new Node(); body.hidden = true;
        panel.fields['[data-pattern-body="' + kind + '"]'] = body;
    }
    panel.querySelectorAll = selector => selector === '[data-pattern-slot]' ? slots : [];
    panel.contains = () => true;
    const clear = buttons.find(button => button.hasAttribute('data-pattern-clear'));
    return {panel, slots, charts, clear,
        toggle: buttons.find(button => button.hasAttribute('data-pattern-toggle')),
        closeDays: buttons.find(button => button.hasAttribute('data-pattern-hide-days')),
        click(button) { panel.handlers.get('click')({target: button, preventDefault() {}}); },
        status: () => panel.fields['[data-pattern-status]'].textContent,
        count(kind, index) { return Number(charts[kind][index].fields['[data-pattern-count]'].textContent); },
        slot(weekday, hour) { return slots.find(button => Number(button.dataset.weekday) === weekday && Number(button.dataset.hour) === hour); },
        root: {querySelector: () => panel}};
}

export function runPatternChecks(fixture, emptyFixture, asset) {
    const start = asset.indexOf('    function initializePatterns(');
    const end = asset.indexOf('    function createServiceUi(', start);
    const initialize = vm.runInNewContext(asset.slice(start, end) + '\ninitializePatterns;', {AbortController});
    const dom = patternDOM(fixture);
    const renderer = initialize(dom.root, fixture.response.pattern_data);
    assert.equal(dom.status(), 'All occurrences: 6');
    assert.equal(dom.slot(1, 8).textContent, '3', 'Same-second occurrences retain their full count');
    dom.click(dom.charts.month[0]);
    assert.equal(dom.slot(1, 8).textContent, '2');
    assert.equal(dom.slot(1, 8).dataset.level, '2', 'The restored heatmap palette keeps a stable scale after filtering');
    dom.click(dom.slot(1, 8));
    assert.equal(dom.status(), 'Matching occurrences: 2', 'A slot must intersect the selected month');
    assert.equal(dom.count('hour', 8), 2);
    assert.equal(dom.count('weekday', 1), 2);
    assert.equal(dom.count('month', 0), 2);
    assert.equal(dom.slot(1, 8).attributes['aria-pressed'], 'true');
    assert(dom.slot(1, 8).attributes['aria-label'].includes(':00: 2'));
    dom.click(dom.charts.day[1]);
    assert.equal(dom.status(), 'Matching occurrences: 2');
    assert(dom.charts.day[28].hidden && !dom.charts.day[27].hidden, 'February omits impossible calendar days');
    dom.click(dom.closeDays);
    assert(dom.panel.fields['[data-pattern-days]'].hidden, 'Close hides the day drilldown without clearing its filter');
    assert.equal(dom.status(), 'Matching occurrences: 2');
    dom.click(dom.charts.month[0]); dom.click(dom.charts.month[0]);
    assert(!dom.panel.fields['[data-pattern-days]'].hidden, 'Selecting a month reopens its drilldown');
    dom.click(dom.charts.day[2]);
    assert.equal(dom.status(), 'No occurrences match the selected filters.');
    dom.click(dom.clear);
    assert.equal(dom.status(), 'All occurrences: 6');
    dom.click(dom.toggle);
    assert.equal(dom.toggle.textContent, 'Collapse');
    assert(!dom.panel.fields['[data-pattern-body="hour"]'].hidden);
    dom.click(dom.toggle);
    assert.equal(dom.toggle.textContent, 'Expand');
    assert(dom.panel.fields['[data-pattern-body="hour"]'].hidden);
    assert.equal(dom.slot(1, 8).attributes['aria-pressed'], 'false');
    dom.click(dom.slot(1, 8)); dom.click(dom.slot(1, 8));
    assert.equal(dom.status(), 'All occurrences: 6', 'Selecting an active cell again clears that slot');
    dom.panel.handlers.get('keydown')({target: dom.slots[0], key: 'ArrowDown', preventDefault() {}});
    assert(dom.slots[24].focused && dom.slots[24].tabIndex === 0, 'Arrow keys move focus to the same hour next weekday');
    dom.panel.handlers.get('keydown')({target: dom.slots[24], key: 'End', preventDefault() {}});
    assert(dom.slots[47].focused);
    const empty = patternDOM(emptyFixture);
    const emptyRenderer = initialize(empty.root, emptyFixture.response.pattern_data);
    assert.equal(empty.status(), 'No occurrences in the selected period.');
    assert(empty.slots.every(cell => cell.textContent === '0' && cell.dataset.level === '0'));
    assert.equal(dom.status(), 'All occurrences: 6', 'Another popup must not change the first popup filters');
    renderer.destroy(); emptyRenderer.destroy();
    assert.equal(dom.panel.handlers.size, 0, 'Refresh/close removes heatmap handlers');
}
