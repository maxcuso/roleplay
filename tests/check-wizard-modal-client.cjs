const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {loadClient} = require('./client-runtime.cjs');
const clone = value => JSON.parse(JSON.stringify(value));
const tick = () => new Promise(resolve => setImmediate(resolve));

(async () => {
  const {modules, ModalManager, expand, m} = loadClient('forum');
  const get = name => modules.get(name).default || modules.get(name);
  const State = get('forum/creation/CreationState'), Wizard = get('forum/creation/CreationWizardModal');
  let saved = JSON.parse(fs.readFileSync(path.join(__dirname, '.runtime/creation-view.json'), 'utf8'));
  let failed = false, writes = 0;
  const api = {
    read: async () => clone(saved),
    save: async body => {
      if (failed) throw new Error('Offline');
      writes++;
      saved.draft = {...saved.draft, revision: body.revision + 1, payload: clone(body.payload), step: body.step};
      return clone(saved);
    },
  };
  const modal = new Wizard(); modal.attrs = {};
  modal.wizard = new State(api, () => {});
  await modal.wizard.load();
  modal.wizard.change(() => {modal.wizard.step = 'attributes'; modal.wizard.view.draft.payload.first_name = 'Черновик окна';});
  const item = {attrs: {beforeRoleplayWizardClose: () => modal.beforeClose()}};
  const manager = new ModalManager(); manager.attrs = {state: {modal: item}};
  manager.animateHide();
  await tick();
  assert.equal(manager.hidden, true, 'Close waits for successful draft save');
  assert.equal(saved.draft.payload.first_name, 'Черновик окна');
  await modal.wizard.load();
  assert.equal(modal.wizard.step, 'attributes', 'Reopening resumes saved step');
  const buttons = expand(modal.content()).filter(node => node.tag === 'button' && node.attrs.title);
  assert.equal(buttons.filter(node => node.attrs.className === 'is-upcoming').length, 5);
  assert.equal(buttons.filter(node => node.attrs['aria-current'] === 'step').length, 1);

  failed = true; manager.hidden = false;
  modal.wizard.change(() => {modal.wizard.view.draft.payload.first_name = 'Несохранённый ввод';});
  manager.animateHide(); await tick();
  assert.equal(manager.hidden, false, 'Network failure keeps modal open');
  assert.equal(modal.wizard.view.draft.payload.first_name, 'Несохранённый ввод');
  assert.equal(modal.wizard.dirty, true);
  failed = false; manager.animateHide(); await tick();
  assert.equal(manager.hidden, true);

  modal.wizard.creating = true;
  assert.equal(await modal.beforeClose(), false, 'Cannot close while final operation is in flight');
  modal.wizard.creating = false;
  modal.characterId = 17;
  modal.wizard.change(() => {modal.wizard.view.draft.payload.first_name = 'Редактор';});
  const before = writes;
  assert.equal(await modal.beforeClose(), true);
  assert.equal(writes, before, 'Closing editor does not apply changes to character');
  modal.wizard.dispose();

  // Запоздалое сохранение не закрывает другое окно, открытое за время запроса.
  let resolve;
  manager.hidden = false;
  manager.attrs.state.modal = {attrs: {beforeRoleplayWizardClose: () => new Promise(done => {resolve = done;})}};
  manager.animateHide(); manager.attrs.state.modal = {attrs: {}}; resolve(true); await tick();
  assert.equal(manager.hidden, false);
  manager.animateHide(); assert.equal(manager.hidden, true, 'Other Flarum modals retain normal closing');
  console.log('Wizard modal checks passed: save on close, resume, failed save, future steps and editor isolation.');
})().catch(error => {console.error(error); process.exitCode = 1;});
