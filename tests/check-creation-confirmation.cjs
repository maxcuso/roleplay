const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {loadClient} = require('./client-runtime.cjs');

(async () => {
  const {modules, app} = loadClient('forum');
  const get = name => {const value = modules.get(name); return value.default || value;};
  const Wizard = get('forum/creation/CreationWizardModal');
  const Confirmation = get('forum/creation/CreationConfirmationModal');
  const Form = get('forum/creation/CreationWizardForm');
  const State = get('forum/creation/CreationState');
  const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, '.runtime/creation-view.json'), 'utf8'));
  const clone = value => JSON.parse(JSON.stringify(value));
  let creations = 0, saves = 0, persisted;
  async function setup(characterId = null, profile) {
    const state = new State({
      read: async () => clone(fixture),
      save: async body => {
        saves++; persisted = clone(body.payload);
        return {...clone(fixture), draft: {...clone(fixture.draft), revision: body.revision + 1, payload: persisted}};
      },
      create: async () => {creations++; return {character_id: characterId ?? 501};},
    }, () => {});
    await state.load();
    state.step = 'review';
    const master = {componentClass: Wizard, attrs: {wizard: state}};
    const manager = {modal: master, closes: 0, close() {
      this.closes++;
      this.modal = this.modal?.componentClass === Confirmation ? master : null;
    }};
    const modal = new Wizard();
    modal.oninit({attrs: {state: manager, wizard: state, characterId, profile}});
    let hidden = 0;
    modal.hide = () => {hidden++;};
    const request = () => modal.content().children.find(node => node.tag === Form).attrs.create();
    return {state, manager, request, hidden: () => hidden};
  }

  const draft = await setup();
  await draft.request();
  assert.equal(creations, 0, 'First click never creates a character/topic');
  assert.equal(app.lastModal.ModalClass, Confirmation);
  assert.equal(app.lastModal.stack, true);
  app.lastModal.attrs.closed(); // крестик возвращает в мастер
  assert.equal(draft.state.createdId, null);
  await draft.request();
  draft.manager.modal = {componentClass: Confirmation, attrs: app.lastModal.attrs};
  draft.state.change(() => {draft.state.view.draft.payload.first_name = 'Сохранить черновик';});
  await app.lastModal.attrs.leaveDraft();
  assert.equal(creations, 0);
  assert.ok(saves > 0 && persisted.first_name === 'Сохранить черновик');
  assert.equal(draft.manager.closes, 2, 'Leave draft closes both linked windows');
  draft.state.dispose();

  const confirmed = await setup();
  await confirmed.request();
  confirmed.manager.modal = {componentClass: Confirmation, attrs: app.lastModal.attrs};
  await app.lastModal.attrs.confirm();
  assert.equal(creations, 1, 'Only explicit confirmation submits creation');
  assert.equal(confirmed.manager.modal, null);
  assert.equal(confirmed.manager.closes, 2);
  confirmed.state.dispose();

  app.lastModal = undefined;
  let refreshed;
  const editing = await setup(42, {selected: 42, select: async id => {refreshed = id;}});
  await editing.request();
  assert.equal(app.lastModal, undefined, 'Editing does not show a warning about a new topic');
  assert.equal(refreshed, 42);
  assert.equal(editing.hidden(), 1);
  editing.state.dispose();
  console.log('Creation confirmation: explicit consent, cancel, saved draft, both windows closed and editing passed.');
})().catch(error => {console.error(error); process.exitCode = 1;});
