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
function loadClient(kind = 'admin') {
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
  class Page extends Component { onremove() {} }
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
      return {
        has: key => values.has(key), get: key => values.get(key), remove: key => values.delete(key),
        add: (key, value) => values.set(key, value),
      };
    }
  }
  const requests = [];
  const app = {
    initializers: {add(_name, initialize) { initialize(); }},
    session: {user: {id: () => 1, slug: () => 'player'}}, routes: {}, setTitle() {}, setTitleCount() {},
    search: {state: {params: () => ({})}},
    route(name, params = {}) { return (this.routes[name]?.path || '/').replace(/:([a-z]+)/g, (_match, key) => encodeURIComponent(params[key] ?? ':' + key)); },
    translator: {trans(key) { return key; }},
    registry: {for() { return {registerPage(Page) { pageClass = Page; }, registerPermission() {}}; }},
    forum: {attribute() { return '/api'; }},
    modal: {show(ModalClass, attrs, stack = false) { app.lastModal = {ModalClass, attrs, stack}; return Promise.resolve(); }},
    request: async request => {requests.push(request); return {data: app.result};},
  };
  const dependencies = new Map([
    ['common/app', app], ['admin/app', app], ['common/Component', Component], ['admin/components/ExtensionPage', ExtensionPage],
    ['common/components/Modal', Modal], ['common/components/Button', Button], ['common/components/Icon', Icon],
    ['forum/components/UserPage', UserPage], ['forum/app', app], ['common/components/Page', Page], ['forum/components/HeaderSecondary', HeaderSecondary],
    ['common/components/LinkButton', Button],
    ['forum/components/IndexSidebar', IndexSidebar],
    ['common/components/ModalManager', ModalManager],
    ['common/extend', {extend(prototype, name, callback) {
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
  return {modules, m, app, requests, pageClass, expand, IndexSidebar, HeaderSecondary, ModalManager};
}
module.exports = {loadClient};
