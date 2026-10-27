// Standalone behavioral checks: node tests/frontend.cjs (no npm dependencies).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
  constructor(tag = 'div') {
    this.tag = tag;
    this.attributes = {};
    this.classes = new Set();
    this.children = [];
    this.handlers = {};
    this.textContent = '';
    this.clientWidth = 800;
    this.clientHeight = 500;
  }
  setAttribute(key, value) { this.attributes[key] = String(value); }
  appendChild(child) { this.children.push(child); return child; }
  get lastChild() { return this.children.at(-1); }
  set innerHTML(value) { this.children = []; }
  addEventListener(event, callback, options = {}) {
    this.handlers[event] = callback;
    options.signal?.addEventListener('abort', () => delete this.handlers[event]);
  }
  querySelector(selector) {
    for (const child of this.children) {
      if ((child.attributes.class || '').split(' ').includes(selector.slice(1))) return child;
      const found = child.querySelector(selector);
      if (found) return found;
    }
    return null;
  }
  getBBox() { return {x: 0, y: 0, width: 190, height: 50}; }
}
const elements = new Map();
const svg = new Element('svg');
class Query {
  constructor(element) { this.element = element; this[0] = element; this.length = element ? 1 : 0; }
  on(event, selector, callback) { this.element.handlers[event] = callback || selector; return this; }
  off(event) { delete this.element.handlers[event]; return this; }
  attr(key, value) {
    if (typeof key === 'object') Object.assign(this.element.attributes, key);
    else if (value === undefined) return this.element.attributes[key];
    else this.element.setAttribute(key, value);
    return this;
  }
  toggleClass(name, enabled) {
    if (enabled) this.element.classes.add(name); else this.element.classes.delete(name);
    return this;
  }
  addClass(names) { names.split(' ').forEach(name => this.element.classes.add(name)); return this; }
  removeClass(names) { names.split(' ').forEach(name => this.element.classes.delete(name)); return this; }
  hasClass(name) { return this.element.classes.has(name); }
  data(key) { return this.element[key]; }
  removeData(key) { delete this.element[key]; return this; }
  removeAttr(key) { delete this.element.attributes[key]; return this; }
  val(value) { this.element.value = value; return this; }
  text(value) { if (value === undefined) return this.element.textContent; this.element.textContent = value; return this; }
  empty() { this.element.children = []; return this; }
  append(child) { this.element.children.push(child.element || child); return this; }
  find(selector) { return selector === 'svg.service-graph-svg' ? new Query(svg) : $(selector); }
  each(callback) { if (this.element) callback.call(this.element); return this; }
  prop() { return this; }
  is() { return true; }
}
function $(selector) {
  if (selector instanceof Query) return selector;
  if (selector instanceof Element) return new Query(selector);
  if (selector.startsWith('<')) return new Query(new Element(selector));
  if (!elements.has(selector)) elements.set(selector, new Element());
  return new Query(elements.get(selector));
}
class Request {
  constructor() { this.doneCallbacks = []; this.failCallbacks = []; this.alwaysCallbacks = []; this.aborted = false; }
  done(callback) { this.doneCallbacks.push(callback); return this; }
  fail(callback) { this.failCallbacks.push(callback); return this; }
  always(callback) { this.alwaysCallbacks.push(callback); return this; }
  resolve(response) { this.doneCallbacks.forEach(callback => callback(response)); this.alwaysCallbacks.forEach(callback => callback()); }
  reject() { this.failCallbacks.forEach(callback => callback({status: 0})); this.alwaysCallbacks.forEach(callback => callback()); }
  abort() { this.aborted = true; this.reject(); }
}
const requests = [];
$.ajax = options => { const request = new Request(); request.options = options; requests.push(request); return request; };
class Curl {
  constructor() { this.args = {}; }
  setArgument(key, value) { this.args[key] = value; }
  unsetArgument(key) { delete this.args[key]; }
  getArgumentsObject() { return this.args; }
  getUrl() { return 'zabbix.php'; }
}
const timers = new Map();
let timerId = 0;
const context = vm.createContext({
  $, Curl, AbortController, URLSearchParams, console,
  document: {cookie: '', createElementNS: (_, tag) => new Element(tag)},
  window: {location: {search: ''}},
  setTimeout: callback => { timers.set(++timerId, callback); return timerId; },
  clearTimeout: id => timers.delete(id)
});
const source = fs.readFileSync(`${__dirname}/../views/js/monitoring.service.view.js.php`, 'utf8')
  .split('<script type="text/javascript">')[1].split('</script>')[0]
  .replace(/<\?= json_encode\(_\('([^']*)'\), [^?]+\) \?>/g, (_, value) => JSON.stringify(value));
vm.runInContext(source + '\nthis.view = view;', context);
const view = context.view;
view.refresh_url = new Curl();
view.refresh_url.setArgument('cols', []);
view.refresh_url.setArgument('status', ['0', '-1']);
view.refresh_url.setArgument('page', null);
assert.equal(view.graph_open, false, 'Graph must be collapsed for every page visit.');
assert.equal(view.getRefreshPostData().graph_open, 0);
assert.equal(view.getRefreshPostData().cols.join(), '', 'Empty columns must be sent explicitly.');
assert.equal(view.getRefreshPostData().status.join(), '0,-1', 'Keep severity zero.');
assert.equal('page' in view.getRefreshPostData(), false, 'Do not post null pagination defaults as empty input.');
view.setExpandedServices(['1', '2', '2', 'bad']);
view.serviceToFromRefreshUrl('1', true);
assert.equal(view.getRefreshPostData().expanded_services, '2');
view.serviceToFromRefreshUrl('2', false);
assert.equal(view.getRefreshPostData().expanded_services, '2', 'Repeated expansion must preserve state.');
view.setExpandedServices([]);
assert.equal(view.getRefreshPostData().expanded_services, '');

let refreshCount = 0;
let destroyed = 0;
const actualRefresh = view.refresh;
view.refresh = () => refreshCount++;
view.host_view_form = $('form[name=host_view]');
view.refresh_interval = 0;
view.initServiceGraphCollapse();
const toggle = elements.get('.js-service-graph-toggle');
assert.equal(toggle.attributes['aria-expanded'], 'false');
toggle.handlers['click.service_graph_collapse']({preventDefault() {}});
assert.equal(view.graph_open, true);
assert.equal(refreshCount, 1, 'Opening must fetch graph data.');
view.service_graph = {getState: () => ({}), destroy: () => destroyed++};
toggle.handlers['click.service_graph_collapse']({preventDefault() {}});
assert.equal(view.graph_open, false);
assert.equal(destroyed, 1, 'Collapse must destroy SVG rendering.');
assert.equal(refreshCount, 1, 'Collapse must not fetch hidden data.');

// A stale response must never replace the latest state or schedule a competing refresh.
view.refresh = actualRefresh;
view.host_view_form = $('form[name=host_view]');
view.running = true;
view.refresh_interval = 0;
let responses = [];
const actualDone = view.onDataDone;
view.onDataDone = response => responses.push(response.id);
const first = view.refresh();
const second = view.refresh();
assert.equal(first.aborted, true);
first.resolve({id: 'stale'});
second.resolve({id: 'latest'});
assert.equal(responses.join(), 'latest');
view.onDataDone = actualDone;

// Render the real graph code against a small DOM and verify lazy, deduplicated root causes.
view.graph_state = {selected: '1'};
const node = {id: '1', name: 'Root', status: 4, status_text: 'High', status_class: 'red',
  sla: '-', slo: '-', has_children: true, is_collapsed: true, root_causes_loaded: false, url: '#'};
const canvas = $('.service-graph-canvas');
const graph = view.createServiceGraph(svg, canvas, {nodes: [node], edges: [], root_services: ['1']});
const before = requests.length;
graph.render();
graph.render();
assert.equal(requests.length, before + 1, 'Deduplicate pending root cause requests.');
requests.at(-1).resolve({root_causes: [{name: 'Problem', url: '#'}]});
assert.equal(node.root_causes_loaded, true);
graph.setSearch('no match');
assert.equal(svg.querySelector('.service-graph-nodes').children.length, 0, 'Unmatched focus must show no nodes.');
graph.destroy();
assert.equal(svg.children.length, 0);
assert.equal(Object.keys(svg.handlers).length, 0, 'Collapse must release pointer and wheel listeners.');

const failedNode = {...node, root_causes_loaded: false};
const retryGraph = view.createServiceGraph(svg, canvas, {nodes: [failedNode], edges: [], root_services: ['1']});
retryGraph.render();
requests.at(-1).reject();
assert.equal(failedNode.root_causes_error, true);
assert.equal(failedNode.root_causes_loaded, false, 'Failure must remain retryable, not become an empty cached result.');
retryGraph.destroy();

// A deeply expanded tree must hit the render limit before recursive layout or SVG creation.
const largeNodes = Array.from({length: 10001}, (_, index) => ({
  id: String(index + 1), name: `Service ${index + 1}`, status: -1,
  has_children: index < 10000, is_collapsed: false, root_causes_loaded: true
}));
const largeEdges = largeNodes.slice(1).map((item, index) => ({from: String(index + 1), to: item.id}));
view.graph_state = {};
const largeGraph = view.createServiceGraph(svg, canvas, {nodes: largeNodes, edges: largeEdges, root_services: ['1']});
largeGraph.render();
assert.equal(svg.querySelector('.service-graph-nodes').children.length, 1);
assert.equal(svg.querySelector('.service-graph-nodes').children[0].tag, 'text', 'Render notice before laying out a large tree.');
largeGraph.destroy();

console.log('Frontend regression checks passed (graph lifecycle, request races, focus, root causes).');
