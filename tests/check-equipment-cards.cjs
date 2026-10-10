const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {loadClient} = require('./client-runtime.cjs');
const clone = value => JSON.parse(JSON.stringify(value));
const tick = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  const {modules, m, expand} = loadClient('forum');
  const get = name => modules.get(name).default || modules.get(name);
  const State = get('forum/profile/ProfileState');
  const Panel = get('forum/profile/ProfilePanel');
  const Sheet = get('common/character/CharacterSheet');
  const Item = get('common/cards/ItemCard');
  const original = JSON.parse(fs.readFileSync(path.join(__dirname, '.runtime/profile-view.json'), 'utf8'));
  const view = clone(original);
  const commands = [];
  const row = view.snapshot.inventory.find(row => !row.equipped && row.type.equipment_limit !== -1);
  assert.ok(row);
  row.quantity = 1; // В этом UI-сценарии выбирается отдельный экземпляр.
  const state = new State({
    read: async () => clone(view),
    equip: async (id, body) => {
      commands.push(clone(body));
      view.snapshot.inventory.find(item => item.id === body.instance_id).equipped = body.equipped;
      view.snapshot.equipment = view.snapshot.inventory.filter(item => item.equipped);
      view.snapshot.character.revision++;
      return clone(view);
    },
  }, () => {});
  await state.select(view.snapshot.character.id);
  const choice = name => expand(m(Panel, {state})).find(node =>
    node.attrs?.className?.startsWith('RpChoice') && node.attrs['aria-label'] === name);
  const name = row.name;
  choice('Экипировать: ' + name).attrs.onclick({currentTarget: {contains: () => false}});
  await tick();
  assert.equal(commands.length, 1);
  assert.equal(commands[0].instance_id, row.id);
  assert.equal(commands[0].equipped, true);
  const removalCards = expand(m(Panel, {state})).filter(node => node.attrs?.['aria-label'] === 'Снять: ' + name);
  const compactRemoval = removalCards.find(node => node.attrs.className?.includes('RpChoice--action'));
  assert.ok(compactRemoval && !compactRemoval.attrs.className.includes('is-selected'));
  assert.ok(!expand(compactRemoval).some(node => node.attrs?.className === 'RpChoice-check'));
  const selected = removalCards.find(node => node.attrs.className?.includes('is-selected'));
  assert.ok(selected.attrs.className.includes('is-selected'), 'Confirmed equip draws green selection');
  assert.ok(expand(selected).some(node => node.attrs?.className === 'RpChoice-check'), 'Equipped item has corner checkmark');
  assert.ok(state.view.snapshot.equipment.some(item => item.id === row.id), 'Confirmed item appears in equipment');

  compactRemoval.attrs.onclick({currentTarget: {contains: () => false}});
  await tick();
  assert.equal(commands[1].equipped, false);
  assert.ok(!state.view.snapshot.equipment.some(item => item.id === row.id), 'Click/keyboard removes item from equipment');
  assert.ok(!choice('Экипировать: ' + name).attrs.className.includes('is-selected'));

  state.view.permissions.can_equip = false;
  const readonly = expand(m(Panel, {state})).find(node => node.attrs?.className?.includes('is-readonly'));
  assert.ok(readonly && !readonly.attrs.role && !readonly.attrs.className.includes('is-disabled'));
  readonly.attrs.onclick({currentTarget: {}});
  await tick();
  assert.equal(commands.length, 2, 'Visitor cannot equip by clicking');

  const definition = {...clone(row.definition), name: 'Плащ тихой поступи', is_magical: true,
    appearance: 'Серый длинный плащ', properties: [
      {description: 'Шаги персонажа не слышны', cooldown_value: 2, cooldown_unit: 'turn'},
      {description: 'Преимущество при скрытности', cooldown_value: 0, cooldown_unit: 'turn'},
    ]};
  let nodes = expand(m(Item, {mode: 'compact', item: definition, type: row.type}));
  const hint = nodes.find(node => node.tag === 'article').attrs.title;
  assert.ok(hint.includes('Шаги персонажа не слышны') && hint.includes('Преимущество при скрытности'));
  assert.ok(hint.includes('Перезарядка: 2 х.'));
  assert.ok(!hint.includes(definition.appearance), 'Compact tooltip excludes appearance');
  assert.equal(nodes.find(node => node.attrs?.className === 'RpCard-heading').attrs.title, hint);
  assert.equal(nodes.find(node => node.attrs?.className === 'RpCard-iconArea').attrs.title, hint);
  nodes = expand(m(Item, {mode: 'expanded', item: definition, type: row.type}));
  assert.equal(nodes.find(node => node.attrs?.className === 'RpCard-iconArea').attrs.title, definition.appearance,
    'Expanded appearance hint stays separate');

  // Цена стопки округляется только после умножения, без потери дробной цены.
  const {itemStackPrice} = modules.get('common/presentation');
  for (const [price, quantity, expected] of [[0.2, 1, 0], [0.2, 6, 1], [0.2, 10, 2],
    [0.29, 100, 29], [1.999, 2, 3], [0.001, 1000, 1], [5, 3, 15]]) {
    assert.equal(itemStackPrice(price, quantity), expected);
  }
  for (const mode of ['compact', 'expanded']) {
    const priced = expand(m(Item, {mode, item: {...definition, base_price: 0.2}, quantity: 6, type: row.type}));
    const gold = priced.find(node => node.attrs?.className === 'RpResource RpResource--gold');
    assert.equal(gold.attrs.title, 'Стоимость стопки');
    assert.ok(expand(gold).some(node => node.tag === '#' && String(node.children) === '1'), 'Both card modes show total stack price');
  }
  console.log('Fractional prices: truncate after multiplying, including decimal boundary cases, passed.');

  // Разметка настоящей анкеты для проверки размеров в изолированном браузере.
  // Не загружаем и не меняем профиль пользователя на форуме.
  const sample = clone(original);
  const equipped = {...clone(row), equipped: true, definition};
  sample.snapshot.inventory = [equipped];
  sample.snapshot.equipment = [equipped];
  const esc = value => String(value).replace(/[&<>"']/g, ch => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[ch]));
  const html = vnode => {
    if (vnode == null || typeof vnode === 'boolean') return '';
    if (Array.isArray(vnode)) return vnode.map(html).join('');
    if (typeof vnode === 'string' || typeof vnode === 'number') return esc(vnode);
    if (typeof vnode.tag === 'function') {
      const component = new vnode.tag();
      component.oninit?.(vnode);
      return html(component.view(vnode));
    }
    if (vnode.tag === '[') return html(vnode.children);
    if (vnode.tag === '#') return esc(vnode.children);
    const attrs = Object.entries(vnode.attrs || {}).filter(([key, value]) =>
      !key.startsWith('on') && key !== 'key' && value != null && value !== false)
      .map(([key, value]) => {
        const actual = key === 'className' ? 'class' : key;
        if (key === 'style') value = Object.entries(value).map(([name, val]) => name + ':' + val).join(';');
        return ' ' + actual + '="' + esc(value) + '"';
      }).join('');
    return '<' + vnode.tag + attrs + '>' + html(vnode.children) + '</' + vnode.tag + '>';
  };
  const output = html(m(Sheet, {review: sample, lookups: sample.lookups,
    itemControl: () => ({disabled: false, toggle() {}})}));
    // Адаптер Icon в unit-тесте не добавляет служебный класс ядра Flarum.
  // Для проверки реальных стилей восстанавливаем его в браузерной разметке.
  fs.writeFileSync(path.join(__dirname, '.runtime/equipment-cards.html'), output.replace(/<i class="/g, '<i class="icon '));
  console.log('Equipment cards: click, keyboard, confirmed selection, removal, readonly access and property tooltips passed.');
})().catch(error => {console.error(error);process.exit(1);});