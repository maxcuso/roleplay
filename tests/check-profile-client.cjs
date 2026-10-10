const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {loadClient} = require('./client-runtime.cjs');
const clone = value => JSON.parse(JSON.stringify(value));
const deferred = () => {let resolve; const promise = new Promise(done => {resolve = done;}); return {promise, resolve};};
const tick = () => new Promise(resolve => setImmediate(resolve));

(async () => {
  const {modules, m, app, expand} = loadClient('forum');
  const exported = name => {
    assert.ok(modules.has(name), 'Module exists: ' + name);
    return modules.get(name).default || modules.get(name);
  };
  const State = exported('forum/profile/ProfileState');
  const Panel = exported('forum/profile/ProfilePanel');
  const Effects = exported('forum/profile/CharacterEffectsModal');
  const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, '.runtime/profile-view.json'), 'utf8'));
  const id = fixture.snapshot.character.id;
  const makeApi = () => ({
    list: async () => ({characters: [{id, name: 'Игрок', status: 'active'}], next_before: null}),
    read: async () => clone(fixture),
    equip: async () => clone(fixture),
  });
  assert.equal(app.routes['user.roleplay'].path, '/u/:username/roleplay');
  assert.equal(app.routes['roleplay.character'], undefined, 'Character sheets have only the user-profile route');
  const state = new State(makeApi(), () => {});
  await state.loadUser(1);
  await tick();
  assert.equal(state.selected, id);
  assert.ok(state.view);

  // Показатели игрока приходят со всем списком, даже когда персонажей для просмотра нет.
  const player = {earned_experience: 1240, active_characters: 2, started_at: '2026-10-10T12:00:00Z'};
  const playerState = new State({...makeApi(), list: async () => ({characters: [], next_before: null, roleplayer: player})}, () => {});
  await playerState.loadUser(1);
  assert.deepEqual(playerState.roleplayer, player);
  const playerNodes = expand(m(Panel, {state: playerState, picker: true}));
  assert.ok(playerNodes.some(node => node.attrs?.['aria-label'] === 'Статистика ролевика'));
  assert.ok(playerNodes.some(node => node.tag === 'time' && node.children[0].children === '2026'));
  // Проверяем исключения 11–14, составные числа и реальную подпись компонента.
  const Summary = exported('forum/profile/RoleplayerSummary');
  for (const [count, word] of [[0, 'Персонажей'], [1, 'Персонаж'], [2, 'Персонажа'], [4, 'Персонажа'],
    [5, 'Персонажей'], [11, 'Персонажей'], [12, 'Персонажей'], [14, 'Персонажей'],
    [21, 'Персонаж'], [22, 'Персонажа'], [25, 'Персонажей'], [101, 'Персонаж'], [111, 'Персонажей']]) {
    const nodes = expand(m(Summary, {stats: {...player, active_characters: count}}));
    assert.ok(nodes.some(node => node.tag === 'dt' && node.children[0].children === word), count + ': ' + word);
  }
  const updatedView = {...clone(fixture), roleplayer: {...player, earned_experience: 1245}};
  playerState.api = {...makeApi(), read: async () => updatedView};
  await playerState.select(id);
  assert.equal(playerState.roleplayer.earned_experience, 1245, 'Refreshing selected character refreshes player statistics');

  // После создания выбирается новая анкета, даже когда у пользователя уже есть активная.
  const preferredReads = [];
  const preferred = new State({...makeApi(),
    list: async () => ({characters: [{id: 222, name: 'Активный', status: 'active'}, {id: 333, name: 'Новый', status: 'creating'}], next_before: null}),
    read: async selected => {preferredReads.push(selected); const view = clone(fixture); view.snapshot.character.id = selected; return view;},
  }, () => {});
  await preferred.loadUser(1, 333); await tick();
  assert.equal(preferred.selected, 333);
  assert.deepEqual(preferredReads, [333], 'Default character is not fetched before requested character');
  await preferred.loadUser(1, 444); await tick();
  assert.equal(preferred.selected, 222, 'A character outside this profile cannot be opened by query parameter');

  // Проверка всеми настоящими vnode, а не только текстовым совпадением JSX.
  let nodes = expand(m(Panel, {state, picker: true, own: true}));
  assert.ok(nodes.some(node => node.tag === 'select' && node.attrs['aria-label'] === 'Выбрать персонажа'));
  assert.ok(!nodes.some(node => node.attrs?.['aria-label'] === 'Открыть анкету'));
  assert.ok(nodes.some(node => node.attrs?.className?.includes('RpProfile-selectChevron')));
  const equipmentCards = nodes.filter(node => node.attrs?.className?.startsWith('RpChoice') && node.attrs.role === 'button');
  assert.ok(equipmentCards.length >= 3, 'Equipment cards are clickable for active owner');
  const effectButton = nodes.find(node => node.tag === 'button' && node.attrs['aria-label'] === 'Эффекты');
  assert.ok(effectButton, 'Profile opens effects through direct modal class');
  effectButton.attrs.onclick();
  assert.equal(app.lastModal.ModalClass, Effects);
  expand(m(Effects, {effects: fixture.calculation.effects}));
  expand(m(Effects, {effects: []}));

  // Подписи получаются из механики и величины, даже если имя/описание не содержат этих слов.
  const presentation = exported('common/character/effectPresentation');
  const bonus = (stat, value, operation = 'add') => ({name: 'Произвольное имя', description: 'Не использовать для числовой строки',
    value, definition: {mechanic: 'stat', stat, operation}, source: {kind: 'perk', label: 'Древняя магия'}});
  assert.equal(presentation.effectLabel(bonus('mana_max', 10)), '+10 к общему запасу маны');
  assert.equal(presentation.effectLabel(bonus('stamina_max', 5)), '+5 к общему запасу выносливости');
  assert.equal(presentation.effectLabel(bonus('mana_max', 2, 'percent')), '+2% к общему запасу маны');
  assert.equal(presentation.effectLabel(bonus('mana_max', -2)), '−2 к общему запасу маны');
  assert.equal(presentation.effectLabel({...bonus('melee_damage', 3), condition: 'unarmed'}), '+малый к урону в ближнем бою (без оружия)');
  assert.equal(presentation.effectLabel({description: 'Не нуждается во сне', definition: {mechanic: 'text'}}), 'Не нуждается во сне');
  assert.equal(presentation.effectLabel({value: 2, definition: {mechanic: 'skill_check', skill_id: 90}},
    {skills: [{id: 90, name: 'Скрытность'}]}), '+2 к проверкам навыка «Скрытность»');
  const compactEffects = expand(m(Effects, {effects: [bonus('mana_max', 10),
    {...bonus('stamina_max', 5), source: {kind: 'item', label: 'Плащ'}}]}));
  assert.deepEqual(compactEffects.filter(node => node.attrs?.className === 'RpEffects-source').map(node => node.attrs.title),
    ['Перк: Древняя магия', 'Предмет: Плащ']);
  assert.ok(!compactEffects.some(node => node.tag === 'h3'), 'No per-effect headings or service panels');

  // Вкладки меняют только представление; существующие игровые действия остаются в «Персонаже».
  const panel = new Panel();
  panel.oninit(m(Panel, {state, picker: true, own: true}));
  nodes = expand(panel.view());
  const tab = name => nodes.find(node => node.tag === 'button' && labelText(node) === name);
  const labelText = node => (node.children || []).map(child => child?.children ?? '').join('');
  assert.ok(tab('Персонаж') && tab('Обучение') && tab('Крафт'));
  tab('Обучение').attrs.onclick();
  nodes = expand(panel.view());
  assert.ok(nodes.some(node => node.children === 'В разработке'));
  assert.ok(!nodes.some(node => node.attrs?.className === 'RpSheet'));
  tab('Крафт').attrs.onclick();
  nodes = expand(panel.view());
  assert.ok(nodes.some(node => node.children === 'В разработке'));
  tab('Персонаж').attrs.onclick();
  nodes = expand(panel.view());
  assert.ok(nodes.some(node => node.attrs?.className === 'RpSheet'));
  assert.ok(!nodes.some(node => node.attrs?.className === 'RpSheet-banner'));
  assert.ok(nodes.some(node => node.attrs?.className === 'RpDevelopment-confirm RpDevelopment-confirm--reserved'),
    'Upgrade action reserves space before an attribute is selected');

  // История открывает прямой класс окна с уже разрешёнными событиями, без нового запроса.
  const History = exported('forum/profile/CharacterHistoryModal');
  state.view.history = [{id: 991, type: 'character_managed', created_at: '2026-10-08 12:00:00',
    details: {action: 'balance', resource: 'gold', delta: 3, comment: 'Награда за задание'}}];
  nodes = expand(panel.view());
  nodes.find(node => node.tag === 'button' && node.attrs['aria-label'] === 'История персонажа').attrs.onclick();
  assert.equal(app.lastModal.ModalClass, History);
  assert.deepEqual(app.lastModal.attrs.events, state.view.history);
  const historyNodes = expand(m(History, app.lastModal.attrs));
  assert.ok(historyNodes.some(node => node.children === 'Награда за задание'));
  expand(m(History, {events: []}));
  // UTC-время переводится в локальный день; награда из двух ресурсов не теряет вторую сумму.
  const historyPresentation = exported('forum/profile/historyPresentation');
  assert.equal(historyPresentation.historyDate('2026-10-10 23:30:00').toISOString(), '2026-10-10T23:30:00.000Z');
  const localNow = new Date(2026, 9, 11, 1);
  const dated = (id, date) => ({id, type: 'character_managed', created_at: date.toISOString(), details: {}});
  const days = historyPresentation.historyDays([dated(1, localNow), dated(2, new Date(2026, 9, 10, 23))], localNow);
  assert.deepEqual(clone(days.map(day => day.label)), ['Сегодня', 'Вчера']);
  assert.deepEqual(clone(historyPresentation.historyResources({action: 'reward', experience: 30, gold: 5}).map(row => row.label)),
    ['+30 опыта', '+5 золота']);
  assert.equal(historyPresentation.historyResources({experience_spent: 10})[0].label, '−10 опыта');
  const customAttribute = {id: 77, name: 'Харизма', icon: 'fas fa-test-custom'};
  assert.equal(historyPresentation.historyHeading({id: 1, type: 'character_developed', created_at: '',
    details: {kind: 'attribute', target_id: 77, name: 'Харизма', level: 7}}, {attributes: [customAttribute]}).icon,
    'fas fa-test-custom', 'History uses the configured attribute icon');
  state.view.permissions.can_equip = false;
  nodes = expand(m(Panel, {state}));
  assert.ok(!nodes.some(node => node.attrs?.className?.startsWith('RpChoice') && node.attrs.role === 'button'),
    'Read-only visitor never gets equipment controls');
  state.view.snapshot.character.status = 'archived';
  assert.ok(expand(m(Panel, {state})).some(node => node.children === 'В АРХИВЕ'), 'Archive status uses shared label');
  const skillPerk = fixture.snapshot.perks.find(row => row.source.definition.icon_source === 'skill');
  if (skillPerk) {
    const presentation = exported('common/perkSource').sourceLabel(skillPerk.source.definition, skillPerk.source.context, fixture.lookups.attributes);
    assert.ok(presentation.icon, 'Source inherits attribute icon in actual profile response');
  }

  // Смена выбора: первый медленный ответ не возвращает старую анкету.
  const first = deferred(), second = deferred();
  const switchState = new State({...makeApi(), read: characterId => characterId === 11 ? first.promise : second.promise}, () => {});
  const selectOne = switchState.select(11);
  const selectTwo = switchState.select(22);
  const current = clone(fixture); current.snapshot.character.id = 22;
  second.resolve(current); await selectTwo;
  first.resolve(clone(fixture)); await selectOne;
  assert.equal(switchState.selected, 22);
  assert.equal(switchState.view.snapshot.character.id, 22);
  assert.equal(switchState.detailLoading, false);

  // Смена пользователя отменяет применение позднего списка.
  const olderList = deferred(), newerList = deferred();
  const lists = new State({...makeApi(), list: userId => userId === 1 ? olderList.promise : newerList.promise}, () => {});
  const oldLoad = lists.loadUser(1);
  const newLoad = lists.loadUser(2);
  newerList.resolve({characters: [], next_before: null}); await newLoad;
  olderList.resolve({characters: [{id: 999, name: 'Другой', status: 'active'}], next_before: null}); await oldLoad;
  assert.equal(lists.characters.length, 0);
  assert.equal(lists.detailLoading, false);
  const staleDetail = deferred();
  const changedOwner = new State({...makeApi(), read: () => staleDetail.promise,
    list: async () => ({characters: [], next_before: null})}, () => {});
  const oldDetail = changedOwner.select(id);
  await changedOwner.loadUser(2);
  staleDetail.resolve(clone(fixture)); await oldDetail;
  assert.equal(changedOwner.view, null);
  assert.equal(changedOwner.detailLoading, false);

  // Потерянный ответ: команда и UUID остаются теми же, двойной клик блокируется.
  const waiting = deferred(), commands = [];
  let attempts = 0;
  const retry = new State({...makeApi(), equip: async (characterId, command) => {
    commands.push({characterId, command: clone(command)});
    if (++attempts === 1) {await waiting.promise; throw {status: 0};}
    return clone(fixture);
  }}, () => {}, () => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
  await retry.select(id);
  const pending = retry.changeEquipment(fixture.snapshot.inventory[0].id, false);
  await retry.changeEquipment(fixture.snapshot.inventory[0].id, false);
  await retry.select(222);
  assert.equal(retry.selected, id, 'Selection remains fixed while equipment write is in flight');
  assert.equal(commands.length, 1);
  waiting.resolve(); await pending;
  assert.ok(retry.retryAvailable && retry.actionsBlocked);
  await retry.retryEquipment();
  assert.equal(commands.length, 2);
  assert.deepEqual(commands[0], commands[1]);
  assert.equal(retry.actionError, '');
  assert.equal(retry.actionsBlocked, false);

  // При конфликте нужна свежая анкета; старые разрешения больше не позволяют команду.
  const conflict = new State({...makeApi(), equip: async () => {throw {status: 409, response: {errors: [{detail: 'Версия изменилась'}]}};}}, () => {});
  await conflict.select(id);
  await conflict.changeEquipment(fixture.snapshot.inventory[0].id, false);
  assert.equal(conflict.actionError, 'Версия изменилась');
  assert.equal(conflict.retryAvailable, false);
  assert.equal(conflict.view.permissions.can_equip, false);
  await conflict.select(id);
  assert.equal(conflict.actionError, '');
  assert.equal(conflict.view.permissions.can_equip, true);

  // Действие на другой странице не применяется к уже закрытой странице.
  const disposedResponse = deferred();
  const disposed = new State({...makeApi(), read: () => disposedResponse.promise}, () => {});
  const removed = disposed.select(id);
  disposed.dispose();
  disposedResponse.resolve(clone(fixture)); await removed;
  assert.equal(disposed.view, null);

  // API сохраняет конкретный ID и направление действия.
  const Api = exported('forum/profile/ProfileApi');
  app.result = clone(fixture);
  const requestCount = app.request;
  const captured = [];
  app.request = async request => {captured.push(request); return {data: app.result};};
  const api = new Api();
  await api.read(id);
  await api.equip(id, commands[0].command);
  assert.ok(captured[0].url.endsWith('/characters/' + id));
  assert.ok(captured[1].url.endsWith('/characters/' + id + '/equipment'));
  assert.equal(captured[1].body.instance_id, commands[0].command.instance_id);
  app.request = requestCount;

  // Вкладка использует штатную UserPage и общий ProfileState.
  const UserProfile = app.routes['user.roleplay'].component;
  const page = new UserProfile();
  page.profile = state;
  page.user = {id: () => 1, slug: () => 'player'};
  expand(page.content());

  console.log('Profile client checks passed: vnode normalization, routes, effects, permissions, switching, exact retry and conflicts.');
})().catch(error => {console.error(error); process.exit(1);});
