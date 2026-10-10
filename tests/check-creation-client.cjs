const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {loadClient} = require('./client-runtime.cjs');
const clone = value => JSON.parse(JSON.stringify(value));

(async () => {
  const {modules, m, app, expand} = loadClient('forum');
  const exported = name => {
    assert.ok(modules.has(name), 'Module exists: ' + name);
    const value = modules.get(name);
    return value.default || value;
  };
  const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, '.runtime/creation-view.json'), 'utf8'));
  const State = exported('forum/creation/CreationState');
  const Wizard = exported('forum/creation/CreationWizardModal');
  assert.equal(app.routes['roleplay.creation'], undefined);
  const makeApi = () => ({
    read: async () => clone(fixture),
    save: async body => ({...clone(fixture), draft: {...clone(fixture.draft), revision: body.revision + 1, step: body.step, payload: clone(body.payload)}}),
    create: async () => ({character_id: 123}),
  });
  // Настоящий старый HTTP-ответ содержал [] у пустых карт. Проверяем именно
  // отправленный JSON и повторную загрузку, а не только значение в памяти формы.
  let stored;
  const emptyApi = makeApi();
  emptyApi.read = async () => {
    const view = clone(fixture);
    view.draft.payload.base_attributes = stored?.base_attributes ?? [];
    view.draft.payload.loadout = stored?.loadout ?? [];
    return view;
  };
  emptyApi.save = async body => {
    stored = JSON.parse(JSON.stringify(body.payload));
    return {...clone(fixture), draft: {...clone(fixture.draft), revision: body.revision + 1, payload: clone(stored)}};
  };
  const emptyState = new State(emptyApi, () => {});
  await emptyState.load();
  assert.ok(!Array.isArray(emptyState.view.draft.payload.base_attributes));
  emptyState.step = 'attributes';
  const attributeStep = exported('forum/creation/steps/AttributesStep');
  const plus = expand(attributeStep(emptyState)).find(node => node.attrs?.['aria-label']?.startsWith('Увеличить:'));
  const slug = emptyState.view.catalog.definitions.attributes[0].slug;
  const initial = emptyState.view.draft.payload.base_attributes[slug];
  plus.attrs.onclick();
  emptyState.change(() => {emptyState.view.draft.payload.loadout.gear = ['sword'];});
  await emptyState.flush();
  assert.equal(stored.base_attributes[slug], initial + 1, 'Attribute increment survives JSON serialization');
  assert.deepEqual(stored.loadout.gear, ['sword'], 'Named loadout selection survives JSON serialization');
  await emptyState.load();
  assert.equal(emptyState.view.draft.payload.base_attributes[slug], initial + 1, 'Attribute survives saving and reloading');
  console.log('Empty map regression: attribute and equipment choices persist OK');

  // Три двойки допустимы. Проверяем реальные обработчики, включая два клика
  // до перерисовки и повторный клик по обработчику после ответа сохранения.
  emptyState.view.draft.payload.base_attributes = Object.fromEntries(
    emptyState.view.catalog.definitions.attributes.map(row => [row.slug, 1]));
  const slugs = emptyState.view.catalog.definitions.attributes.map(row => row.slug);
  const increase = expand(attributeStep(emptyState)).filter(node => node.attrs?.['aria-label']?.startsWith('Увеличить:'));
  increase[0].attrs.onclick();
  increase[0].attrs.onclick();
  assert.equal(emptyState.view.draft.payload.base_attributes[slugs[0]], 3);
  increase[0].attrs.onclick();
  assert.equal(emptyState.view.draft.payload.base_attributes[slugs[0]], 3, 'Base attribute cannot exceed 3');
  emptyState.adjustAttribute(slugs[0], -1);
  increase[1].attrs.onclick();
  increase[2].attrs.onclick();
  assert.deepEqual(slugs.map(slug => emptyState.view.draft.payload.base_attributes[slug]), [2, 2, 2, 1]);
  increase[3].attrs.onclick();
  assert.equal(emptyState.view.draft.payload.base_attributes[slugs[3]], 1, 'Cannot spend more than 7 points');
  emptyState.adjustAttribute(slugs[3], -1);
  assert.equal(emptyState.view.draft.payload.base_attributes[slugs[3]], 1, 'Base attribute cannot drop below 1');
  emptyState.dispose();
  // Новый экземпляр состояния проверяет замену черновика ответом сохранения.
  const freshState = new State(makeApi(), () => {});
  await freshState.load();
  freshState.view.draft.payload.base_attributes = Object.fromEntries(slugs.map(slug => [slug, 1]));
  const staleButton = expand(attributeStep(freshState)).find(node => node.attrs?.['aria-label']?.startsWith('Увеличить:'));
  staleButton.attrs.onclick();
  await freshState.flush();
  staleButton.attrs.onclick();
  assert.equal(freshState.view.draft.payload.base_attributes[slugs[0]], 3, 'Handler reads the current draft after saving');
  freshState.dispose();
  console.log('Free starting allocation: 2/2/2/1, bounds, budget and rapid clicks passed');


  const state = new State(makeApi(), () => {});
  await state.load();
  assert.ok(state.ready && state.view.preview.review);
  const page = new Wizard();
  page.attrs = {};
  page.wizard = state;
  // Настоящий Mithril нормализует фрагменты и обнаруживает смесь keyed/unkeyed children.
  for (const step of ['basics', 'attributes', 'training', 'perks', 'spells', 'loadout', 'review']) {
    state.step = step;
    const nodes = expand(page.content());
    assert.ok(nodes.some(node => node.tag === 'main'), step + ' renders');
    assert.ok(nodes.some(node => node.tag === 'nav'), step + ' has progress');
    console.log('Wizard step renders: ' + step + ' OK');
  }
  const choice = exported('common/cards/SelectableEntityCard');
  let toggles = 0;
  const selectedNodes = expand(m(choice, {name: 'Выбор', selected: true, disabled: false, onchange: () => toggles++}, m('p', 'Описание')));
  const selectedRoot = selectedNodes.find(node => node.attrs?.className === 'RpChoice is-selected');
  assert.ok(selectedRoot);
  assert.ok(selectedNodes.some(node => node.attrs?.className === 'RpChoice-check'), 'Green corner checkmark present');
  selectedRoot.attrs.onclick({currentTarget: {contains: () => false}});
  assert.equal(toggles, 1, 'Selected choice can be removed');
  const disabledNodes = expand(m(choice, {name: 'Недоступно', selected: false, disabled: true, onchange: () => toggles++}, 'Текст'));
  disabledNodes.find(node => node.attrs?.role === 'button').attrs.onclick({currentTarget: {contains: () => false}});
  assert.equal(toggles, 1, 'Disabled choice cannot be added');
  let prevented = false;
  const target = {};
  selectedRoot.attrs.onkeydown({key: ' ', target, currentTarget: target, preventDefault: () => {prevented = true;}});
  assert.ok(prevented && toggles === 2, 'Keyboard selection works');
  // Расовые списки берём из серверного preview: UI не строит список по уровню Магии.
  const racialState = new State(makeApi(), () => {});
  await racialState.load();
  const currentRace = racialState.view.catalog.definitions.races.find(row => row.id === racialState.view.draft.payload.race_id);
  currentRace.rules.proficiency_choice_count = 1;
  const magic = racialState.view.catalog.definitions.proficiencies.find(row => row.slug === 'magic-weapon');
  racialState.view.preview.racial_proficiency_ids = [magic.id];
  racialState.step = 'training';
  page.wizard = racialState;
  const racialNodes = expand(page.content());
  assert.ok(racialNodes.some(node => node.tag === 'option' && node.attrs.value === magic.id), 'Racial dropdown offers magic independently of attribute level');

  // Устаревшие скрытые выборы должны оставаться доступными для снятия после смены правил.
  const stale = new State(makeApi(), () => {});
  await stale.load();
  page.wizard = stale;
  const staleRace = stale.view.catalog.definitions.races.find(row => row.id === stale.view.draft.payload.race_id);
  staleRace.rules.perk_choice_count = 0;
  stale.view.draft.payload.perk_ids = [99999999];
  stale.step = 'perks';
  let nodes = expand(page.content());
  const stalePerk = nodes.find(node => node.attrs?.['aria-label'] === 'Убрать недоступный перк');
  assert.ok(stalePerk, 'Unknown selected perk is visible for removal');
  stalePerk.attrs.onclick({currentTarget: {contains: () => false}});
  assert.equal(stale.view.draft.payload.perk_ids.length, 0);
  stale.dirty = false;
  stale.step = 'spells';
  stale.view.preview.spell_choices.ordinary_count = 0;
  stale.view.preview.spell_choices.ordinary_ids = [];
  stale.view.draft.payload.spell_ids = [99999998];
  nodes = expand(page.content());
  const staleSpell = nodes.find(node => node.attrs?.['aria-label'] === 'Убрать недоступное заклинание');
  assert.ok(staleSpell, 'Unknown selected spell is visible even when ordinary count becomes zero');
  staleSpell.attrs.onclick({currentTarget: {contains: () => false}});
  assert.equal(stale.view.draft.payload.spell_ids.length, 0);
  stale.dispose();
  console.log('Unavailable selections can be removed after rules change OK');

  // Недоступные будущие шаги не открываются; возврат к началу остаётся возможным.
  const navigation = new State(makeApi(), () => {});
  await navigation.load();
  navigation.step = 'attributes';
  navigation.view.preview.step_validity.attributes = false;
  await navigation.goTo('training');
  assert.equal(navigation.step, 'attributes', 'Incomplete attributes block forward navigation');
  navigation.view.preview.step_validity.basics = false;
  await navigation.goTo('basics');
  assert.equal(navigation.step, 'basics', 'Backward navigation remains available');
  navigation.dispose();

  let failures = 0;
  const recoveringApi = makeApi();
  const successfulSave = recoveringApi.save;
  recoveringApi.save = async body => {
    if (failures++ === 0) throw new Error('Offline');
    return successfulSave(body);
  };
  const recovering = new State(recoveringApi, () => {});
  await recovering.load();
  recovering.change(() => {recovering.view.draft.payload.first_name = 'Повторное сохранение';});
  assert.equal(await recovering.flush(), false);
  assert.ok(recovering.error);
  assert.equal(await recovering.flush(), true);
  assert.equal(recovering.error, '', 'Successful save clears previous network error');
  recovering.dispose();

  // Сохранение во время ввода: первый ответ не перетирает изменения, второе сохранение использует новую версию.
  let resolveFirst;
  const bodies = [];
  const queuedApi = makeApi();
  queuedApi.save = body => {
    bodies.push(clone(body));
    const response = {...clone(fixture), draft: {...clone(fixture.draft), revision: body.revision + 1, payload: clone(body.payload)}};
    return bodies.length === 1 ? new Promise(resolve => {resolveFirst = () => resolve(response);}) : Promise.resolve(response);
  };
  const queued = new State(queuedApi, () => {});
  await queued.load();
  queued.change(() => {queued.view.draft.payload.first_name = 'Первый ввод';});
  const saving = queued.flush();
  queued.change(() => {queued.view.draft.payload.first_name = 'Последний ввод';});
  resolveFirst();
  assert.equal(await saving, true);
  assert.equal(bodies.length, 2);
  assert.equal(bodies[0].payload.first_name, 'Первый ввод');
  assert.equal(bodies[1].payload.first_name, 'Последний ввод');
  assert.equal(bodies[1].revision, bodies[0].revision + 1);
  assert.equal(queued.view.draft.payload.first_name, 'Последний ввод');
  console.log('Concurrent input keeps latest payload and serializes saves OK');

  const conflictApi = makeApi();
  let calls = 0;
  conflictApi.save = async () => {calls++; throw {status: 409, response: {errors: [{detail: 'Черновик изменён'}]}};};
  const conflict = new State(conflictApi, () => {});
  await conflict.load();
  conflict.change(() => {conflict.view.draft.payload.first_name = 'Локальный ввод';});
  assert.equal(await conflict.flush(), false);
  assert.equal(conflict.conflict, true);
  assert.equal(await conflict.flush(), false);
  assert.equal(calls, 1, 'Conflict does not repeatedly overwrite another tab');
  assert.equal(conflict.view.draft.payload.first_name, 'Локальный ввод');

  let requests = 0, keys = 0;
  const commands = [];
  const retryApi = makeApi();
  retryApi.create = async command => {
    commands.push(clone(command));
    if (++requests === 1) throw new Error('Lost response');
    return {character_id: 321};
  };
  const retry = new State(retryApi, () => {}, () => {keys++; return '11111111-1111-4111-8111-111111111111';});
  await retry.load();
  await retry.create();
  assert.equal(retry.createdId, null);
  await retry.create();
  assert.equal(retry.createdId, 321);
  assert.equal(keys, 1);
  assert.deepEqual(commands[0], commands[1], 'Retry keeps exact operation key and revisions');
  await retry.create();
  assert.equal(requests, 2, 'Successful character cannot be created again');
  console.log('Conflict and create retry safeguards OK');
  // У завершённого мастера ссылка ведёт в профиль с ID новой анкеты.
  const completedPage = new Wizard();
  completedPage.attrs = {};
  completedPage.wizard = retry;
  const completed = expand(completedPage.content());
  assert.ok(completed.some(node => node.attrs?.href === '/u/player/roleplay?character=321'));
  const initialRouteSet = m.route.set;
  let destination;
  m.route.set = url => {destination = url;};
  await completedPage.createCharacter();
  assert.equal(destination, '/u/player/roleplay?character=321');
  m.route.set = initialRouteSet;
  [state, racialState, queued, conflict, retry, page.wizard].forEach(value => value.dispose());
  console.log('Wizard client checks passed; no browser or real accounts used.');
})().catch(error => {console.error(error); process.exitCode = 1;});
