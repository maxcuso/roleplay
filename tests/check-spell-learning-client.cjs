const assert = require('node:assert/strict');
const {loadClient} = require('./client-runtime.cjs');
(async () => {
  const {modules, m, app, expand} = loadClient('forum');
  const exported = name => {const value = modules.get(name); assert.ok(value, name); return value.default || value;};
  const Panel = exported('forum/profile/LearningPanel');
  const Confirm = exported('forum/profile/LearningConfirmModal');
  const source = {instance_id: 15, item_id: 10, name: 'Учебная книга', consumed: false};
  const option = {id: 20, spell: {name: 'Искра', level: 1, distance_mode: 'self', cast_mode: 'instant'},
    path: {id: 1, name: 'Пламя', icon: 'fas fa-fire', color: '#ef9775'}, cost: 5, allowed: true, reasons: [], sources: [source]};
  const calls = [];
  const profile = {selected: 1, actionsBlocked: false, view: {learning: {experience: 7, rules_hash: 'test', spells: [option,
    {...option, id: 21, spell: {...option.spell, name: 'Высший круг', level: 2}, cost: 10, allowed: false,
      reasons: ['Требуется 2 круг', 'Не хватает 3 опыта']}] }}, develop: async (...args) => {calls.push(args); return true;}};
  const panel = new Panel(); panel.oninit(m(Panel, {profile}));
  let nodes = expand(panel.view());
  const entries = list => list.filter(n => n.tag === 'article' && n.attrs?.className?.startsWith('RpLearning-entry'));
  assert.equal(entries(nodes).length, 2);
  assert.ok(nodes.some(n => n.children === 'Требуется II круг'));
  const isLearn = n => n.tag === 'button' && JSON.stringify(n.children).includes('Изучить');
  const learn = nodes.find(isLearn);
  assert.ok(learn, 'Allowed knowledge has an action');
  learn.attrs.onclick(); assert.equal(app.lastModal.ModalClass, Confirm);
  const modal = new Confirm(); modal.oninit(m(Confirm, {...app.lastModal.attrs, onLearned: () => {}}));
  let confirmNodes = expand(modal.view());
  assert.equal(modal.modalHeading().purpose, 'learn', 'Learning has its own semantic heading icon');
  assert.ok(confirmNodes.some(n => n.attrs?.className === 'RpLearningConfirm-costs'));
  assert.ok(confirmNodes.some(n => n.attrs?.className === 'RpLearningConfirm-source'));
  const confirmButton = confirmNodes.find(isLearn);
  await confirmButton.attrs.onclick(); assert.deepEqual(calls[0], ['spell', 20, 15]);
  panel.circle = '2'; nodes = expand(panel.view());
  assert.equal(entries(nodes).length, 1, 'Circle filtering works');
  panel.category = 'recipes'; nodes = expand(panel.view());
  assert.ok(nodes.some(n => n.children === 'Этот раздел в разработке'));
  assert.equal(entries(nodes).length, 0);
  const switched = new Confirm(); switched.oninit(m(Confirm, {profile, option, onLearned: () => {}}));
  profile.selected = 2;
  confirmNodes = expand(switched.view());
  assert.equal(confirmNodes.find(isLearn).attrs.disabled, true,
    'An old confirmation cannot learn for a different selected character');
  profile.selected = 1;
  const multiple = new Confirm();
  multiple.oninit(m(Confirm, {profile, option: {...option, sources: [source, {...source, instance_id: 16, name: 'Свиток', consumed: true}]}, onLearned: () => {}}));
  let multipleNodes = expand(multiple.view());
  multipleNodes.find(n => n.tag === 'select').attrs.onchange({target: {value: '16'}});
  multipleNodes = expand(multiple.view());
  assert.ok(multipleNodes.some(n => n.children === 'При изучении расходуется одна единица этого предмета'),
    'Changing the source updates its consumption notice');
  const history = exported('forum/profile/historyPresentation');
  assert.match(history.historyHeading({type: 'character_developed', details: {kind: 'spell', name: 'Искра', level: 1, source: 'Учебная книга'}}, {}).caption, /Изучено заклинание/);
  console.log('Learning UI: actual cards, circle filter, unavailable reasons, confirmation source, character switch and history passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
