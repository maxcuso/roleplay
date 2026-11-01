const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');

/** Выполняем Mithril из source map установленного ядра Flarum.
 * В репозиторий карту не сохраняем. Для теста нужен tests/.runtime/core-admin.js.map.
 * Ядро компонентов заменяем небольшими адаптерами; это проверка разметки, не скриншот браузера.
 */
function mithril() {
  const map = JSON.parse(fs.readFileSync(path.join(__dirname, '.runtime/core-admin.js.map'), 'utf8'));
  const sources = new Map();
  map.sources.forEach((name, index) => {
    const marker = '/mithril/';
    const start = name.indexOf(marker);
    if (start >= 0 && map.sourcesContent[index]) sources.set(name.slice(start + marker.length), map.sourcesContent[index]);
  });
  const cache = new Map();
  function requireSource(name) {
    name = path.posix.normalize(name);
    if (!name.endsWith('.js')) name += '.js';
    if (cache.has(name)) return cache.get(name).exports;
    if (!sources.has(name)) throw new Error('Mithril source is missing: ' + name);
    const module = {exports: {}};
    cache.set(name, module);
    const evaluate = vm.runInThisContext('(function(require, module, exports) {' + sources.get(name) + '\n})', {filename: name});
    evaluate(dependency => requireSource(path.posix.join(path.posix.dirname(name), dependency)), module, module.exports);
    return module.exports;
  }
  const m = requireSource('render/hyperscript.js');
  m.redraw = () => {};
  m.route = {param: () => '', set: () => {}};
  return m;
}
function loadClient(kind = 'admin', environment = {}) {
  const m = mithril();
  const modules = new Map();
  let pageClass;
  class Component {
    oninit(vnode) { this.attrs = vnode.attrs; this.children = vnode.children; }
  }
  class ExtensionPage extends Component {
    view() { return this.content(); }
  }
  class Modal extends Component {
    onremove() {}
    onbeforeremove() {}
    wrapper(children) { return m('div', children); }
    view() { return this.wrapper(m('div', [m('h2', this.title()), this.content()])); }
    hide() { this.hidden = true; }
  }
  class Button extends Component {
    view() {
      const attrs = {...this.attrs, type: this.attrs.type || 'button'};
      delete attrs.loading;
      delete attrs.icon;
      return m('button', attrs, this.children);
    }
  }
  class Icon extends Component {
    view() { return m('i', {className: this.attrs.name, title: this.attrs.title}); }
  }
  class Dropdown extends Component {
    showing = false;
    view(vnode) {
      return m('div.Dropdown', [this.getButton(), this.getMenu((vnode.children || []).filter(Boolean).map(child => m('li', child)))]);
    }
  }
  class Tooltip extends Component {
    view() {
      const child = this.children[0];
      child.attrs = {...child.attrs, 'aria-label': this.attrs.text};
      return child;
    }
  }
  class LoadingIndicator extends Component {
    view() { return m('span', {role: 'status'}); }
  }
  class Alert extends Component {
    view() { return m('div', {className: 'Alert Alert--' + (this.attrs.type || 'warning'), role: 'alert'}, this.children, this.attrs.controls); }
  }
  class Page extends Component { onremove() {} }
  class Model {}
  class Post extends Model {
    constructor(data = {}, store) {super(); this.data = data; this.store = store;}
    attribute(name) {return this.data.attributes?.[name];}
    contentHtml() {return this.attribute('contentHtml');}
  }
  class Notification extends Component {
    view() { return m('div', {className: 'Notification'}, this.content()); }
  }
  class NotificationGrid extends Component {
    notificationTypes() {const entries = new Map(); return {add: (key, value) => entries.set(key, value), entries};}
  }
  class ModalManager extends Component {
    animateHide(callback) {this.hidden = true; callback?.();}
  }
  class UserPage extends Page {
    show(user) { this.user = user; }
    async loadUser() { if (this.user) this.show(this.user); }
    navItems() { return {add() {}}; }
    view() { return this.content(); }
  }
  class HeaderSecondary extends Component {
    items() {
      const values = new Map();
      return {add: (key, value) => values.set(key, value), has: key => values.has(key)};
    }
  }
  class IndexSidebar extends Component {
    items() {
      const values = new Map([['newDiscussion', m(Button, {
        className: 'Button Button--primary IndexPage-newDiscussion', itemClassName: 'App-primaryControl',
        onclick: () => {app.discussionOpened = true;},
      }, 'Start a Discussion')]]);
      const priorities = new Map([['newDiscussion', 0]]);
      return {
        has: key => values.has(key), get: key => values.get(key), remove: key => values.delete(key),
        add: (key, value, priority = 0) => {values.set(key, value); priorities.set(key, priority);},
        toArray: () => [...values.entries()].sort(([a], [b]) => (priorities.get(b) || 0) - (priorities.get(a) || 0)).map(([, value]) => value),
      };
    }
  }
  const requests = [], initializers = new Map();
  let alertId = 0;
  const alertEntries = new Map(), alertCalls = [];
  const app = {
    data: {resources: [{type: 'forums', id: '1', attributes: {}}], settings: {}},
    initializers: {add(name, initialize) { initializers.set(name, initialize); initialize(); }},
    session: {user: {id: () => 1, slug: () => 'player', attribute: () => undefined, isEmailConfirmed: () => true}}, routes: {}, setTitle() {}, setTitleCount() {},
    search: {state: {params: () => ({})}},
    route(name, params = {}) { return (this.routes[name]?.path || '/').replace(/:([a-z]+)/g, (_match, key) => encodeURIComponent(params[key] ?? ':' + key)); },
    translator: {trans(key) { return key; }},
    registry: {for() { return {registerPage(Page) { pageClass = Page; }, registerPermission() {}}; }},
    forum: {attribute() { return '/api'; }},
    store: {all: () => [], models: {}}, notificationComponents: {},
    alerts: {
      entries: alertEntries, calls: alertCalls,
      show(attrs, children) {const id = ++alertId; alertEntries.set(id, {attrs, children}); alertCalls.push({id, attrs, children}); return id;},
      dismiss(id) {alertEntries.delete(id);},
    },
    modal: {show(ModalClass, attrs, stack = false) { app.lastModal = {ModalClass, attrs, stack}; return Promise.resolve(); }},
    request: async request => {requests.push(request); return {data: app.result};},
  };
  const dependencies = new Map([
    ['common/app', app], ['admin/app', app], ['common/Component', Component], ['admin/components/ExtensionPage', ExtensionPage],
    ['common/components/Modal', Modal], ['common/components/Button', Button], ['common/components/Icon', Icon],
    ['common/components/Dropdown', Dropdown],
    ['common/components/TextEditor', Component], ['forum/components/CommentPost', Component],
    ['common/components/Tooltip', Tooltip],
    ['common/components/LoadingIndicator', LoadingIndicator],
    ['common/components/Alert', Alert],
    ['common/Model', Model], ['common/models/Post', Post], ['forum/components/Notification', Notification], ['forum/components/NotificationGrid', NotificationGrid],
    ['common/helpers/textContrastClass', () => 'text-contrast--dark'],
    ['forum/components/UserPage', UserPage], ['forum/app', app], ['common/components/Page', Page], ['forum/components/HeaderSecondary', HeaderSecondary],
    ['common/components/LinkButton', Button],
    ['forum/components/IndexSidebar', IndexSidebar],
    ['common/components/ModalManager', ModalManager],
    ['common/extend', {extend(prototype, name, callback) {
      if (typeof prototype === 'string') prototype = dependencies.get(prototype.replace(/^flarum\//, '')).prototype;
      const previous = prototype[name];
      prototype[name] = function(...args) { const result = previous.apply(this, args); callback.call(this, result, ...args); return result; };
    }, override(prototype, name, callback) {
      const previous = prototype[name];
      prototype[name] = function(...args) {return callback.call(this, previous.bind(this), ...args);};
    }}],
  ]);
  vm.runInNewContext(fs.readFileSync(path.join(root, 'js/dist/' + kind + '.js'), 'utf8'), {
    module: {exports: {}}, m, window: {confirm: () => true, getSelection: () => null}, URL, FormData, setTimeout, clearTimeout,
    crypto: require('node:crypto'),
    flarum: {reg: {
      get(_package, name) {
        if (!dependencies.has(name)) throw new Error('Unexpected Flarum dependency: ' + name);
        return dependencies.get(name);
      },
      add(_package, name, value) { modules.set(name, value); },
    }},
    ...environment,
  });
  /** Раскрываем дерево и вызываем lifecycle/view каждого компонента, включая вложенные карточки. */
  function expand(vnode, collected = []) {
    if (vnode == null || vnode === false || vnode === true) return collected;
    if (Array.isArray(vnode)) {vnode.forEach(child => expand(child, collected)); return collected;}
    collected.push(vnode);
    if (typeof vnode.tag === 'function') {
      const instance = new vnode.tag(vnode);
      if (typeof instance.view !== 'function') throw new Error('Invalid Mithril component: ' + vnode.tag.name);
      if (instance.oninit) instance.oninit(vnode);
      expand(instance.view(vnode), collected);
    } else if (Array.isArray(vnode.children)) vnode.children.forEach(child => expand(child, collected));
    return collected;
  }
  return {modules, m, app, requests, initializers, pageClass, expand, IndexSidebar, HeaderSecondary, ModalManager};
}
module.exports = {loadClient};
