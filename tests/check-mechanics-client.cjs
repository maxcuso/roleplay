const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {loadClient} = require('./client-runtime.cjs');
const clone = value => JSON.parse(JSON.stringify(value));
const fixture = name => JSON.parse(fs.readFileSync(path.join(__dirname, '.runtime', name), 'utf8'));
const tick = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  const {modules, m, app, expand} = loadClient('forum');
  const get = name => modules.get(name).default || modules.get(name);
  const edit = fixture('mechanics-view.json');
  const commands = [];
  let writes = 0, fail = true;
  app.request = async request => {
    commands.push(clone(request));
    if (request.method === 'GET') return {data: clone(edit)};
    if (request.method === 'POST') {
      assert.equal(request.body.character_revision, edit.character_revision);
      return {data: {...clone(edit), draft: {...clone(edit.draft),
        revision: request.body.revision + 1, payload: clone(request.body.payload), step: request.body.step}}};
    }
    if (fail) {fail = false; throw {status: 0};}
    writes++;
    return {data: {character_id: edit.draft.id}};
  };
  const State = get('forum/creation/CreationState');
  const Api = get('forum/profile/MechanicsEditApi');
  const wizard = new State(new Api(edit.draft.id), () => {}, () => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
  await wizard.load();
  wizard.change(() => {wizard.view.draft.payload.first_name = 'Новое имя';});
  await wizard.flush();
  assert.equal(writes, 0, 'Editor steps only validate');
  const count = commands.length;
  await wizard.create();
  assert.equal(wizard.createdId, null);
  assert.ok(wizard.error.includes('Повтори действие'));
  await wizard.create();
  assert.equal(wizard.createdId, edit.draft.id);
  assert.equal(writes, 1);
  const saved = commands.slice(count);
  assert.deepEqual(saved[0].body, saved[1].body, 'Retry has same UUID, payload and real revision');
  assert.equal(saved[0].method, 'PUT');
  assert.equal(saved[0].body.payload.first_name, 'Новое имя');
  assert.equal(saved[0].body.revision, edit.character_revision);
  assert.ok(!('draft_revision' in saved[0].body));
  wizard.dispose();
  const ProfileState = get('forum/profile/ProfileState');
  const Panel = get('forum/profile/ReviewPanel');
  const Modal = get('forum/creation/CreationWizardModal');
  const profileView = fixture('review-view.json');
  profileView.snapshot.character.id = edit.draft.id;
  profileView.review.permissions.can_edit_mechanics = true;
  const profile = new ProfileState({read: async () => clone(profileView)}, () => {});
  await profile.select(edit.draft.id);
  const button = expand(m(Panel, {state: profile})).find(node => node.tag === 'button'
    && JSON.stringify(node.children).includes('Редактировать'));
  assert.ok(button);
  button.attrs.onclick();
  assert.equal(app.lastModal.ModalClass, Modal);
  const modal = new Modal();
  modal.oninit(m(Modal, {...app.lastModal.attrs, state: {modalManager: true}}));
  await tick();
  assert.equal(modal.wizard.view.draft.id, edit.draft.id);
  for (const step of ['basics', 'attributes', 'training', 'perks', 'spells', 'loadout', 'review']) {
    modal.wizard.step = step;
    assert.ok(expand(modal.view()).some(node => node.tag === 'nav'), step);
  }
  const submit = expand(modal.view()).find(node => node.tag === 'button'
    && JSON.stringify(node.children).includes('Сохранить изменения'));
  assert.ok(submit && !submit.attrs.disabled);
  submit.attrs.onclick();
  await tick();
  assert.ok(modal.hidden);
  modal.onremove(m(Modal, {}));
  const SpellCard = get('common/cards/SpellCard');
  const spell = edit.preview.review.snapshot.spells[0];
  const nodes = expand(m(SpellCard, {spell: spell.definition, path: spell.path, mode: 'expanded'}));
  assert.equal(nodes.find(node => node.attrs?.className === 'RpCard-iconArea').attrs.title, spell.path.name);
  console.log('Full editor: steps, preview, final save, retry, lifecycle and spell tooltip passed.');
})().catch(error => {console.error(error); process.exit(1);});
