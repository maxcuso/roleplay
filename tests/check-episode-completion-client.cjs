const assert = require('node:assert/strict');
const {loadClient} = require('./client-runtime.cjs');
const clone = value => JSON.parse(JSON.stringify(value));
const tick = () => new Promise(resolve => setImmediate(resolve));

(async () => {
  const {modules, m, expand} = loadClient('forum');
  const exported = name => modules.get(name).default || modules.get(name);
  const State = exported('forum/episodes/EpisodeCompletionState');
  const Modal = exported('forum/episodes/EpisodeCompletionModal');
  const calls = [];
  const character = id => ({id, name: 'Персонаж ' + id, available: true,
    bonuses: id === 11 ? [{name: 'Перк', percent: 10}, {name: 'Снаряжение', percent: 20}] : [],
    perkIds: [71], spellIds: [81]});
  const context = {eventId: 4, isQuest: true, discussionId: 9, revision: 'episode-version', title: 'Переправа',
    characters: [character(11), character(12), character(11)]};
  const rows = {
    items: [{id: 61, name: 'Зелье', enabled: true}, {id: 62, name: 'Верёвка', enabled: true}],
    perks: [{id: 71, name: 'Известный перк', enabled: true}],
    spells: [{id: 81, name: 'Известное заклинание', level: 1, enabled: true}],
    characters: [{id: 13, name: 'Персонаж 13'}],
  };
  const result = (kind, params) => ({rows: clone(params.search ? rows[kind].filter(row => row.name.includes(params.search)) : rows[kind]),
    total: params.search ? 1 : 61, page: Number(params.page), per_page: 30, revision: 1, lookups: {}});
  const api = kind => ({
    list: async params => {calls.push({kind, params: {...params}}); return result(kind, params);},
    save() {assert.fail('The window must not create or edit catalog entries');},
    toggle() {assert.fail('The window must not toggle catalog entries');},
    remove() {assert.fail('The window must not delete catalog entries');},
  });
  const source = {characters: api('characters'), catalog: api, character: async id => character(id)};
  const state = new State(context, source, () => {});
  assert.equal(state.recipients.length, 2, 'A participant is not duplicated');
  state.selected.experience = 15; state.selected.gold = 20;
  assert.equal(state.totalExperience(state.selected), 19, 'Percentage bonuses add and final XP rounds down');

  state.openPicker('items'); await tick();
  assert.deepEqual(calls.at(-1), {kind: 'items', params: {search: '', page: 1, enabled: '1'}});
  state.choose(state.picker.list.data.rows[0]); state.picker.quantity = 2;
  assert.ok(state.validate().includes('закройте выбор'), 'A selected but unadded reward cannot be lost silently');
  await state.addChoice();
  assert.equal(state.selected.items[0].quantity, 2);
  state.openPicker('items'); await tick();
  state.choose(state.picker.list.data.rows[0]); state.picker.quantity = 3;
  await state.addChoice();
  assert.equal(state.selected.items.length, 1);
  assert.equal(state.selected.items[0].quantity, 5, 'Repeated item selections merge quantities');
  state.openPicker('items'); await tick();
  state.choose(state.picker.list.data.rows[0]); state.picker.quantity = 1000;
  await state.addChoice();
  assert.ok(state.error.includes('1000'));
  assert.equal(state.selected.items[0].quantity, 5, 'Invalid combined quantity leaves the draft intact');
  state.closePicker(); state.error = '';

  for (const kind of ['perks', 'spells']) {
    state.openPicker(kind); await tick();
    state.choose(state.picker.list.data.rows[0]); await state.addChoice();
    assert.equal(state.selected[kind].length, 1);
    assert.ok(state.duplicate(state.selected, kind, rows[kind][0].id), 'Known knowledge is marked for publication-time skip');
    state.openPicker(kind); await tick();
    state.choose(state.picker.list.data.rows[0]); await state.addChoice();
    assert.equal(state.selected[kind].length, 1, 'One knowledge entry is not selected twice');
  }
  state.select(12); state.selected.experience = 6;
  state.select(11);
  assert.equal(state.selected.experience, 15, 'Switching recipient preserves its reward');
  assert.equal(context.characters[0].bonuses.length, 2, 'Context remains unchanged');

  state.openPicker('items'); await tick();
  await state.page(1);
  assert.equal(calls.at(-1).params.page, 2, 'Next page is requested from the server');
  state.search('Верёвка'); await state.picker.list.load();
  assert.equal(calls.at(-1).params.search, 'Верёвка');
  assert.equal(calls.at(-1).params.page, 1, 'Search resets pagination');
  assert.equal(state.picker.list.data.rows.length, 1);
  assert.equal(state.picker.selected, null, 'New search clears the previous choice');
  state.closePicker();

  state.openPicker('characters'); await tick();
  state.choose(state.picker.list.data.rows[0]); await state.addChoice();
  assert.equal(state.selectedId, 13, 'Another active character can be added outside initial participants');
  state.select(11);
  const payload = clone(state.payload());
  assert.equal(payload.recipients[0].experience, 15, 'Only base XP is sent, not the calculated 19');
  assert.deepEqual(payload.recipients[0].items, [{id: 61, quantity: 5}]);
  assert.deepEqual(payload.recipients[0].perks, [71]);
  assert.deepEqual(payload.recipients[0].spells, [81]);
  assert.equal(payload.revision, context.revision);
  assert.equal(Object.hasOwn(payload.recipients[0], 'bonuses'), false, 'Client bonuses cannot authorize a future award');
  assert.equal(Object.hasOwn(payload.recipients[0], 'name'), false, 'Payload contains stable IDs only');
  state.selected.experience = 17;
  assert.equal(payload.recipients[0].experience, 15, 'Created payload is independent of later form edits');

  let resolveInsert, insertCalls = 0;
  const inserting = state.insert(async body => {
    ++insertCalls; assert.equal(body.event_id, 4);
    await new Promise(resolve => {resolveInsert = resolve;});
  });
  assert.equal(state.busy, true);
  assert.equal(await state.insert(() => assert.fail('Duplicate insert')), false);
  resolveInsert(); assert.equal(await inserting, true); assert.equal(insertCalls, 1);
  assert.equal(state.busy, false);
  assert.equal(await state.insert(async () => {throw {response: {errors: [{detail: 'Обновите данные эпизода'}]}};}), false);
  assert.equal(state.error, 'Обновите данные эпизода'); assert.equal(state.errorKind, 'operation');
  assert.equal(state.selected.items[0].quantity, 5, 'Insert failure retains the complete draft');
  state.selected.experience = NaN;
  assert.equal(await state.insert(() => assert.fail('Invalid form inserted')), false);
  assert.equal(state.errorKind, 'input');
  state.dispose();

  // Ответ старого поиска не заменяет результат нового. Используется общий CatalogListState.
  let resolveOld;
  const staleSource = {...source, catalog: () => ({...api('items'), list: params => params.search === ''
    ? new Promise(resolve => {resolveOld = resolve;}) : Promise.resolve(result('items', params))})};
  const stale = new State(context, staleSource, () => {});
  stale.openPicker('items');
  stale.search('Верёвка'); await stale.picker.list.load();
  resolveOld(result('items', {page: 1, search: ''})); await tick();
  assert.equal(stale.picker.list.data.rows[0].name, 'Верёвка');
  stale.dispose();

  // Не добавляем персонажа, который стал недоступен после выдачи результатов поиска.
  const unavailable = new State(context, {...source, character: async id => ({...character(id), available: false})}, () => {});
  unavailable.openPicker('characters'); await tick();
  unavailable.choose(unavailable.picker.list.data.rows[0]); await unavailable.addChoice();
  assert.equal(unavailable.recipients.length, 2); assert.ok(unavailable.error);
  unavailable.dispose();
  let resolveCharacter;
  const disposed = new State(context, {...source, character: () => new Promise(resolve => {resolveCharacter = resolve;})}, () => {});
  disposed.openPicker('characters'); await tick();
  disposed.choose(disposed.picker.list.data.rows[0]); const choosing = disposed.addChoice(); disposed.dispose();
  resolveCharacter(character(13)); await choosing;
  assert.equal(disposed.recipients.length, 2, 'Late character response after closing the window is ignored');

  const modal = new Modal();
  let inserted;
  modal.oninit(m(Modal, {context, source, onInsert: body => {inserted = body;}, state: {}}));
  let nodes = expand(modal.view());
  assert.ok(!JSON.stringify(nodes).includes('Предпросмотр'), 'There is no preview step');
  assert.ok(nodes.some(node => node.tag === 'button' && node.attrs.type === 'submit'));
  assert.equal(nodes.filter(node => node.tag === 'input' && node.attrs.type === 'number').length, 2);
  modal.completion.openPicker('items'); await tick();
  nodes = expand(modal.view());
  assert.ok(nodes.some(node => node.tag === 'input' && node.attrs.type === 'search' && node.attrs['aria-label'] === 'Поиск предметов'));
  assert.ok(nodes.some(node => node.tag === 'button' && node.attrs['aria-label'] === 'Следующая страница'));
  modal.completion.choose(modal.completion.picker.list.data.rows[0]);
  await modal.completion.addChoice();
  await modal.insert();
  assert.equal(inserted.discussion_id, 9); assert.ok(modal.hidden, 'Window closes after successful insert callback only');
  modal.completion.dispose();
  assert.ok(calls.every(call => Object.hasOwn(call, 'params')), 'All data source calls made by the form are reads');
  const ordinaryContext = {...context, eventId: null, isQuest: false, characters: []};
  const ordinaryModal = new Modal();
  ordinaryModal.oninit(m(Modal, {context: ordinaryContext, source, onInsert() {}, state: {}}));
  const ordinaryNodes = expand(ordinaryModal.view());
  assert.ok(JSON.stringify(ordinaryNodes).includes('Награды будут выданы после публикации сообщения'));
  assert.ok(!JSON.stringify(ordinaryNodes).includes('тема закрыта'), 'An ordinary reward window never promises to close its topic');
  ordinaryModal.completion.dispose();
  console.log('Episode completion UI passed: isolated drafts, server search and pages, stale responses, XP, duplicates, insertion and native modal. No rewards awarded.');
})().catch(error => {console.error(error); process.exitCode = 1;});
