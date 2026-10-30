const assert = require('node:assert/strict');
const {loadClient} = require('./client-runtime.cjs');

(async () => {
  let now = 0, next = 0, fontsReady;
  const frames = new Map(), timers = new Map(), listeners = new Map(), observers = [];
  const frame = elapsed => {now += elapsed; const pending = [...frames.values()]; frames.clear(); pending.forEach(callback => callback(now));};
  const {modules, m, expand} = loadClient('forum', {
    requestAnimationFrame: callback => {const id = ++next; frames.set(id, callback); return id;},
    cancelAnimationFrame: id => frames.delete(id),
    setTimeout: callback => {const id = ++next; timers.set(id, callback); return id;},
    clearTimeout: id => timers.delete(id),
    performance: {now: () => now},
    getComputedStyle: () => ({lineHeight: '15px'}),
    document: {fonts: {ready: new Promise(resolve => {fontsReady = resolve;})}},
    window: {addEventListener: (name, callback) => listeners.set(name, callback), removeEventListener: name => listeners.delete(name)},
    ResizeObserver: class {
      constructor(callback) {this.callback = callback; observers.push(this);}
      observe() {}
      disconnect() {this.disconnected = true;}
    },
  });
  const exported = modules.get('common/cards/CardDescription');
  const Description = exported.default || exported;
  // В адаптере ядра DOM не монтируется. Здесь подставляем измеряемое окно описания.
  const parent = Object.getPrototypeOf(Description.prototype);
  parent.oncreate = function(vnode) {this.element = vnode.dom;};
  parent.onremove = function() {};
  m.redraw = () => {};
  const text = {scrollHeight: 240, clientHeight: 45, scrollTop: 0};
  const component = new Description();
  const vnode = {attrs: {name: 'Лечащее слово I', description: 'Длинный текст'}, dom: {querySelector: () => text}};
  component.oninit(vnode);
  component.oncreate(vnode);
  frame(16);
  const nodes = () => expand(component.view());
  const buttons = () => nodes().filter(node => node.tag === 'button');
  assert.equal(buttons().length, 2);
  assert.equal(buttons()[0].attrs.disabled, true, 'At the beginning only downward scrolling is available');
  assert.equal(buttons()[1].attrs.disabled, false);
  let stopped = 0;
  const pointer = {button: 0, pointerId: 1, currentTarget: {setPointerCapture() {}}, stopPropagation() {stopped++;}, preventDefault() {}};
  buttons()[1].attrs.onpointerdown(pointer);
  buttons()[1].attrs.onpointerup();
  buttons()[1].attrs.onclick({detail: 1, stopPropagation() {stopped++;}});
  assert.equal(text.scrollTop, 15, 'Pointerdown and click together scroll one line, not two');
  assert.equal(stopped, 2, 'Arrow actions cannot bubble into card selection or equipment');
  buttons()[1].attrs.onclick({detail: 0, stopPropagation() {}});
  assert.equal(text.scrollTop, 30, 'Native keyboard activation scrolls one line');

  // Долгое нажатие продолжает прокрутку, отпускание и потеря окна останавливают её.
  buttons()[1].attrs.onpointerdown(pointer);
  const afterPress = text.scrollTop;
  now += 320;
  const pending = [...timers.values()]; timers.clear(); pending.forEach(callback => callback());
  frame(32);
  assert.ok(text.scrollTop > afterPress);
  listeners.get('blur')();
  const afterBlur = text.scrollTop;
  frame(32);
  assert.equal(text.scrollTop, afterBlur);
  assert.equal(timers.size, 0);
  assert.equal(frames.size, 0);

  text.scrollTop = 195;
  nodes().find(node => node.attrs?.className === 'RpCard-description').attrs.onscroll({});
  frame(16);
  assert.equal(buttons()[1].attrs.disabled, true, 'Wheel or swipe to the bottom disables the downward arrow');
  assert.equal(buttons()[0].attrs.disabled, false);
  buttons()[0].attrs.onclick({detail: 0, stopPropagation() {}});
  assert.equal(text.scrollTop, 180, 'The opposite direction remains usable');

  // После изменения размеров короткий текст больше не показывает управление.
  text.clientHeight = 240; text.scrollTop = 0;
  observers[0].callback(); frame(16);
  assert.equal(buttons().length, 0);
  component.onremove(vnode);
  assert.equal(observers[0].disconnected, true);
  assert.equal(listeners.size, 0);
  fontsReady(); await Promise.resolve();
  assert.equal(frames.size, 0, 'Late font loading cannot restart a removed component');
  console.log('Card description checks passed: boundaries, one-line clicks, hold, keyboard, event isolation, resizing and cleanup.');
})().catch(error => {console.error(error); process.exitCode = 1;});
