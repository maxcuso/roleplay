const assert = require('node:assert/strict');
const {loadClient} = require('./client-runtime.cjs');
const tick = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  const {modules, app, m, expand} = loadClient('forum');
  const exported = name => modules.get(name).default || modules.get(name);
  const Modal = exported('forum/checks/CheckModal'), Api = exported('forum/checks/CheckApi');
  const context = {discussionId: 9, requestId: 'source-request', request: {kind: 'skill', target_id: 2, difficulty: 10},
    attributes: [{id: 1, name: 'Физиология'}], skills: [{id: 2, name: 'Внимательность', attribute_id: 1}],
    characters: [{id: 11, name: 'Персонаж'}], defaultCharacterId: 11, difficulties: [{value: 10, name: 'Средне'}]};
  const character = {id: 11, name: 'Персонаж', attributes: [{id: 1, name: 'Физиология', value: 7, bonus: 2, extra: 0}],
    skills: [{id: 2, name: 'Внимательность', attribute_id: 1, level: 2, bonus: 4, extra: 0}]};
  let prepared, inserted;
  const api = {prepare: async (type, payload) => {prepared = {type, payload}; return '[check-roll=example]';},
    character: async () => character, targets: async () => ({rows: [{id: 11, name: 'Персонаж'}], page: 1, total: 1})};
  const roll = new Modal();
  roll.oninit(m(Modal, {type: 'roll', api, context, character, onInsert: code => {inserted = code;}, state: {}}));
  let nodes = expand(roll.view());
  assert.equal(nodes.filter(n => n.tag === 'textarea').length, 0, 'Request description is absent from the roll form');
  assert.equal(roll.selectedCheck().bonus, 4, 'The server bonus is not incremented with skill level a second time');
  assert.ok(nodes.some(n => n.tag === 'option' && n.attrs.value === ''), 'Standalone rolls may omit difficulty');
  roll.modifier = 2;
  await roll.insert();
  assert.equal(prepared, undefined); assert.ok(roll.error.includes('причину'));
  roll.reason = 'Хорошее освещение';
  await roll.insert();
  assert.equal(prepared.type, 'roll'); assert.equal(prepared.payload.character_id, 11);
  assert.equal(prepared.payload.request_id, 'source-request'); assert.equal(prepared.payload.modifier, 2);
  assert.equal(prepared.payload.bonus, undefined); assert.equal(prepared.payload.dice, undefined);
  assert.equal(inserted, '[check-roll=example]'); assert.ok(roll.hidden);
  const request = new Modal();
  request.oninit(m(Modal, {type: 'request', api, context: {...context, requestId: null, request: null}, onInsert() {}, state: {}}));
  request.target = 'selected'; await request.insert(); assert.ok(request.error.includes('персонажа'));
  await request.loadTargets(); request.selected = [request.rows[0]]; request.description = '<example>';
  await request.insert();
  assert.equal(prepared.type, 'request'); assert.equal(prepared.payload.description, '<example>');
  assert.deepEqual(Array.from(prepared.payload.characters), [11]);
  let resolveOld;
  const stale = new Modal();
  stale.oninit(m(Modal, {type: 'roll', api: {...api, character: id => id === 12 ? new Promise(resolve => {resolveOld = resolve;}) : Promise.resolve({...character, id})}, context, character, onInsert() {}, state: {}}));
  const old = stale.chooseCharacter(12); await stale.chooseCharacter(13); resolveOld({...character, id: 12}); await old;
  assert.equal(stale.character.id, 13, 'Late character data never replaces the newest choice');
  let resolvePending;
  const closed = new Modal();
  closed.oninit(m(Modal, {type: 'roll', api: {...api, character: () => new Promise(resolve => {resolvePending = resolve;})}, context, character, onInsert() {}, state: {}}));
  const pending = closed.chooseCharacter(12); closed.disposed = true; resolvePending({...character, id: 12}); await pending;
  assert.equal(closed.character, undefined, 'Closing the window ignores a late character response');
  const service = new Api(9); app.result = {code: 'prepared'};
  const payload = {kind: 'attribute', target_id: 1, difficulty: null, character_id: 11, request_id: null, modifier: 0, reason: ''};
  // app.request captures payloads here so a retry can be checked independently of native transport.
  const calls = []; app.request = async options => {calls.push(options); return {data: {code: 'prepared'}};};
  await service.prepare('roll', payload); await service.prepare('roll', payload);
  assert.equal(calls[0].body.operation_key, calls[1].body.operation_key);
  await service.prepare('roll', {...payload, modifier: 1, reason: 'Причина'});
  assert.notEqual(calls[1].body.operation_key, calls[2].body.operation_key);
  console.log('Check forms passed: request targets, no roll preview, server bonuses, modifier reason, stale reads and idempotent insertion');
})().catch(error => {console.error(error); process.exitCode = 1;});
