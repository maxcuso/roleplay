const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {loadClient} = require('./client-runtime.cjs');
const clone = value => JSON.parse(JSON.stringify(value));

(async () => {
  const {modules} = loadClient('forum');
  const module = modules.get('forum/creation/CreationState'), State = module.default || module;
  const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, '.runtime/creation-view.json'), 'utf8'));
  const warning = require('../resources/presentation/messages.json').find(row => row.id === 'E032').text;
  let resolveSave, delayed = true;
  const response = body => ({...clone(fixture), draft: {...clone(fixture.draft),
    revision: body.revision + 1, step: body.step, payload: clone(body.payload)}});
  const api = {
    read: async () => clone(fixture),
    save: body => delayed ? new Promise(resolve => {resolveSave = () => {delayed = false; resolve(response(body));};}) : Promise.resolve(response(body)),
  };
  const state = new State(api, () => {});
  await state.load();
  state.step = 'basics';
  state.view.preview.step_validity.basics = false;
  state.view.preview.errors.basics = [warning];
  state.attempted = true;
  state.change(() => {state.view.draft.payload.first_name = 'Исправленное имя';});
  assert.equal(state.attempted, false, 'Editing hides the old warning');
  const moving = state.goTo('attributes');
  assert.equal(state.attempted, false, 'Waiting for validation must not redisplay the stale warning');
  resolveSave();
  await moving;
  assert.equal(state.step, 'attributes');
  assert.equal(state.attempted, false, 'Successful transition starts a fresh step');
  state.dispose();

  const invalid = clone(fixture);
  invalid.draft.step = 'basics'; invalid.preview.step_validity.basics = false;
  invalid.preview.valid = false; invalid.preview.errors.basics = [warning];
  const bad = new State({read: async () => clone(invalid), save: async body => ({...clone(invalid), draft: {...clone(invalid.draft), revision: body.revision + 1}})}, () => {});
  await bad.load();
  assert.equal(bad.attempted, false, 'Initial invalid fields remain quiet');
  await bad.goTo('attributes');
  assert.equal(bad.step, 'basics');
  assert.equal(bad.attempted, true, 'An attempted transition reveals current validation');
  bad.dispose();
  console.log('Wizard feedback: quiet initial state, clearing on edit, no stale warning during validation, blocked invalid transition passed');
})().catch(error => {console.error(error); process.exitCode = 1;});
