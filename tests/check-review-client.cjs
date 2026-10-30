const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {loadClient} = require('./client-runtime.cjs');
const clone = value => JSON.parse(JSON.stringify(value));
const tick = () => new Promise(resolve => setImmediate(resolve));
const label = node => JSON.stringify(node.children);
(async () => {
  const {modules, m, app, expand} = loadClient('forum');
  const exported = name => modules.get(name).default || modules.get(name);
  const State = exported('forum/profile/ProfileState');
  const Panel = exported('forum/profile/ProfilePanel');
  const Decision = exported('forum/profile/ReviewDecisionModal');
  const Identity = exported('forum/profile/IdentityCorrectionModal');
  const Review = exported('forum/profile/ReviewPanel');
  const History = exported('forum/profile/ReviewHistoryModal');
  const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, '.runtime/review-view.json'), 'utf8'));
  const id = fixture.snapshot.character.id;
  const commands = [];
  const api = {
    list: async () => ({characters: [], next_before: null}),
    read: async () => clone(fixture),
    equip: async () => clone(fixture),
    review: async (id, body) => {commands.push({kind: 'review', id, body: clone(body)}); return clone(fixture);},
    correct: async (id, body) => {commands.push({kind: 'identity', id, body: clone(body)}); return clone(fixture);},
  };
  const state = new State(api, () => {}, () => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
  await state.select(id);
  let nodes = expand(m(Panel, {state}));
  assert.ok(nodes.some(node => node.children === 'НА ПРОВЕРКЕ'), 'Profile shows pending mark');
  const approves = nodes.filter(node => node.tag === 'button' && label(node).includes('Одобрить'));
  assert.equal(approves.length, 1, 'Only mechanics has a profile approval button');
  approves[0].attrs.onclick();
  assert.equal(app.lastModal.ModalClass, Decision);
  assert.equal(app.lastModal.attrs.profile, state);
  assert.equal(app.lastModal.attrs.part, 'mechanics');
  // Flarum uses attrs.state for ModalManagerState; application state must use another name.
  const decision = new Decision();
  decision.oninit(m(Decision, {...app.lastModal.attrs, state: {modalManager: true}}));
  nodes = expand(decision.view());
  const form = nodes.find(node => node.tag === 'form');
  assert.ok(form, 'Confirmation has a real submit form');
  form.attrs.onsubmit({preventDefault() {}});
  await tick();
  assert.equal(commands[0].body.part, 'mechanics');
  assert.equal(commands[0].body.action, 'approve');
  assert.ok(decision.hidden, 'Success closes modal');

  const returnModal = new Decision();
  returnModal.oninit(m(Decision, {profile: state, part: 'mechanics', action: 'request_changes', state: {}}));
  nodes = expand(returnModal.view());
  assert.ok(nodes.find(node => node.tag === 'textarea').attrs.required);
  assert.ok(nodes.find(node => node.tag === 'button' && node.attrs.type === 'submit').attrs.disabled,
    'Empty remarks cannot be submitted');
  returnModal.comment = 'Дополни биографию.';
  nodes = expand(returnModal.view());
  nodes.find(node => node.tag === 'form').attrs.onsubmit({preventDefault() {}});
  await tick();
  assert.equal(commands[1].body.comment, 'Дополни биографию.');
  assert.equal(commands[1].body.topic_hash, fixture.review.topic_hash);

  state.view.review.permissions = {can_moderate: false, can_edit_identity: true, can_submit_mechanics: true, can_submit_literature: false};
  state.view.review.mechanics_status = 'changes_requested';
  nodes = expand(m(Panel, {state}));
  assert.ok(!nodes.some(node => node.tag === 'button' && label(node).includes('Одобрить')), 'Owner has no moderator buttons');
  const identityButton = nodes.find(node => node.tag === 'button' && label(node).includes('Исправить имя'));
  identityButton.attrs.onclick();
  assert.equal(app.lastModal.ModalClass, Identity);
  const identity = new Identity();
  identity.oninit(m(Identity, {...app.lastModal.attrs, state: {}}));
  assert.equal(identity.age, String(fixture.snapshot.character.age));
  nodes = expand(identity.view());
  assert.equal(nodes.filter(node => node.tag === 'input').length, 3, 'Identity form exposes only name, surname, age');
  identity.firstName = 'Новое имя'; identity.age = '22';
  expand(identity.view()).find(node => node.tag === 'form').attrs.onsubmit({preventDefault() {}});
  await tick();
  assert.equal(commands[2].kind, 'identity');
  assert.equal(commands[2].body.age, 22);
  assert.ok(!('race_id' in commands[2].body));

  // Connection loss on moderation uses the same body/UUID on retry, just like equipment.
  const repeats = [];
  const retryState = new State({...api, review: async (id, body) => {
    repeats.push(clone(body));
    if (repeats.length === 1) throw {status: 0};
    return clone(fixture);
  }}, () => {}, () => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
  await retryState.select(id);
  assert.equal(await retryState.changeReview('mechanics', 'approve'), false);
  await retryState.changeReview('mechanics', 'approve');
  assert.equal(repeats.length, 1, 'Another action is blocked while exact retry awaits');
  assert.equal(await retryState.retryOperation(), true);
  assert.deepEqual(repeats[0], repeats[1]);
  const conflict = new State({...api, review: async () => {throw {status: 409};}}, () => {});
  await conflict.select(id);
  await conflict.changeReview('mechanics', 'approve');
  assert.equal(conflict.view.review.permissions.can_moderate, false, 'Stale moderator permissions are revoked until refresh');

  const ownerState = new State(api, () => {});
  await ownerState.select(id);
  ownerState.view.review.literature_status = 'draft';
  ownerState.view.review.permissions = {can_edit_mechanics: true, can_moderate: false,
    can_edit_identity: false, can_submit_mechanics: false, can_submit_literature: true};
  nodes = expand(m(Review, {state: ownerState}));
  assert.ok(nodes.some(node => node.children === 'В работе'));
  assert.equal(nodes.some(node => node.tag === 'details'), false, 'History does not expand the panel');
  const editButton = nodes.find(node => node.tag === 'button' && label(node).includes('Редактировать'));
  assert.ok(editButton.attrs.className.includes('RpReview-editButton'));
  assert.ok(nodes.some(node => node.attrs?.href?.endsWith('/d/' + fixture.snapshot.character.discussion_id)));
  nodes.find(node => node.tag === 'button' && node.attrs['aria-label'] === 'История проверки').attrs.onclick();
  assert.equal(app.lastModal.ModalClass, History);
  const history = new History(); history.attrs = app.lastModal.attrs;
  assert.ok(expand(history.content()).length);
  const submitLiterature = nodes.find(node => node.tag === 'button' && label(node).includes('На проверку'));
  submitLiterature.attrs.onclick(); await tick();
  assert.equal(commands.at(-1).body.part, 'literature');
  assert.equal(commands.at(-1).body.action, 'submit');
  ownerState.view.review.permissions.can_submit_literature = false;
  ownerState.view.review.permissions.can_withdraw_literature = true;
  ownerState.view.review.literature_status = 'review';
  nodes = expand(m(Review, {state: ownerState}));
  assert.ok(nodes.some(node => node.children === 'Анкета отправлена на проверку'));
  assert.ok(!nodes.some(node => node.tag === 'button' && label(node).includes('На проверку')));
  nodes.find(node => node.tag === 'button' && label(node).includes('Отозвать')).attrs.onclick(); await tick();
  assert.equal(commands.at(-1).body.part, 'literature');
  assert.equal(commands.at(-1).body.action, 'withdraw');
  assert.equal(await ownerState.changeReview('literature', 'approve'), false, 'UI cannot manually approve literature');

  const Api = exported('forum/profile/ProfileApi');
  const captured = [];
  app.request = async request => {captured.push(request); return {data: clone(fixture)};};
  const transport = new Api();
  await transport.review(id, commands[0].body);
  await transport.correct(id, commands[2].body);
  assert.ok(captured[0].url.endsWith('/review') && captured[0].method === 'POST');
  assert.ok(captured[1].url.endsWith('/identity') && captured[1].method === 'PUT');
  console.log('Review client checks passed: both parts, real forms, modal state, remarks, corrections, permissions and exact retry.');
})().catch(error => {console.error(error); process.exit(1);});
