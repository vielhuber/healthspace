let assert = require('node:assert/strict');
let fs = require('node:fs');
let path = require('node:path');
let vm = require('node:vm');
let { test } = require('node:test');

test('Theme changes recreate every chart with matching colors and preserve metric data', () => {
    let listeners = {};
    let theme = 'light';
    let instances = new Map();
    let destroyed = 0;
    let configuration = [
        { id: 'weight', type: 'line', labels: ['01.11.'], data: [104.5], trend: [104.5] },
        { id: 'sleep', type: 'bar', labels: ['01.11.'], data: [7], stacks: [{ label: 'Deep', data: [1] }] },
        { id: 'pressure', type: 'line', labels: ['01.11.'], series: [{ label: 'Systolic', data: [120] }] },
        { id: 'steps', type: 'bar', labels: ['01.11.'], data: [12000], goal: 10000 }
    ];
    let canvases = Object.fromEntries(configuration.map(chart => [chart.id, { id: chart.id }]));
    let $button = {
        addEventListener: (event, handler) => {
            listeners.button = handler;
        }
    };
    class Chart {
        constructor($canvas, config) {
            this.config = config;
            this.id = $canvas.id;
            instances.set(this.id, this);
        }
        destroy() {
            destroyed++;
            instances.delete(this.id);
        }
        static getChart($canvas) {
            return instances.get($canvas.id);
        }
    }
    let context = vm.createContext({
        Chart,
        Event: class {
            constructor(type) {
                this.type = type;
            }
        },
        localStorage: {
            getItem: () => theme,
            setItem: (key, value) => {
                theme = value;
            }
        },
        document: {
            documentElement: {
                getAttribute: () => theme,
                setAttribute: (name, value) => {
                    theme = value;
                }
            },
            querySelector: () => ({ getAttribute: () => JSON.stringify(configuration) }),
            getElementById: id => (id === 'themeToggle' ? $button : canvases[id]),
            addEventListener: (event, handler) => {
                listeners[event] = handler;
            },
            dispatchEvent: event => {
                listeners[event.type]?.(event);
            }
        }
    });
    for (let script of ['theme.js', 'charts.js']) {
        vm.runInContext(fs.readFileSync(path.join(__dirname, '../js', script), 'utf8'), context);
    }
    listeners.DOMContentLoaded();
    assert.equal(instances.get('weight').config.data.datasets[0].borderColor, '#18181b');
    listeners.button();
    assert.equal(theme, 'dark');
    assert.equal(instances.get('weight').config.data.datasets[0].borderColor, '#e4e4e7');
    assert.equal(instances.get('sleep').config.data.datasets[0].backgroundColor, '#e4e4e7');
    assert.equal(instances.get('pressure').config.data.datasets[0].borderColor, '#e4e4e7');
    assert.equal(instances.get('steps').config.data.datasets[1].borderColor, '#71717a');
    assert.equal(instances.get('weight').config.options.scales.y.ticks.color, '#71717a');
    assert.equal(instances.get('weight').config.data.datasets[0].data[0], 104.5);
    assert.equal(instances.size, 4);
    assert.equal(destroyed, 4);
    listeners.button();
    assert.equal(instances.get('weight').config.data.datasets[0].borderColor, '#18181b');
    assert.equal(instances.size, 4);
    assert.equal(destroyed, 8);
});
