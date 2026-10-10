const assert = require('node:assert/strict');
const {loadClient} = require('./client-runtime.cjs');
const {modules, m, expand} = loadClient('forum');
const exported = modules.get('common/character/CharacterSheet');
const Sheet = exported.default || exported;
const text = node => node == null ? '' : typeof node === 'string' ? node
  : Array.isArray(node) ? node.map(text).join('') : text(node.children);
const calculation = {
  base_damage: {melee_damage: {value: 9}, ranged_damage: {value: 3}, magic_damage: {value: 1}},
  maximum_damage: {melee_damage: null, ranged_damage: null, magic_damage: null},
};
const render = () => expand(m(Sheet, {
  review: {snapshot: {character: {}}, calculation}, lookups: {attributes: []},
})).filter(node => node.attrs?.className === 'RpSheet-combatValue').map(text).map(value => value.trim());

assert.deepEqual(render(), ['Средний', 'Малый', 'Незначительный', 'Нет'],
  'Empty equipment displays attribute damage instead of missing attacks');
// The server result includes attribute + weapon OR attribute + applicable effects, once.
calculation.maximum_damage.melee_damage = {damage: {value: 12}};
calculation.maximum_damage.ranged_damage = {damage: {value: 6}};
assert.deepEqual(render(), ['Средний + Малый', 'Малый ×2', 'Незначительный', 'Нет']);
assert.equal(calculation.base_damage.melee_damage.value, 9, 'Effects do not redefine pure attribute damage');
calculation.maximum_damage.ranged_damage = {damage: {value: 0}};
assert.equal(render()[1], 'Нет', 'An actual zero result is not replaced by the attribute baseline');
console.log('Character damage display passed: attribute baseline, calculated totals without double counting and actual zero.');
