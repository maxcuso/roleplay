const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {loadClient} = require('./client-runtime.cjs');

// Задерживаем настоящий транспорт мастера: окно не должно создаваться раньше ответа.
(async () => {
  const {modules, app, m} = loadClient('forum');
  const exported = modules.get('forum/creation/CreationWizardModal');
  const Wizard = exported.default || exported;
  const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, '.runtime/creation-view.json'), 'utf8'));
  const response = () => ({data: JSON.parse(JSON.stringify(fixture))});
  const alerts = [];
  app.alerts = {show: (_attrs, message) => alerts.push(message)};
  app.modal.modal = null;
  let route = '/role';
  m.route.get = () => route;
  let finish, requests = 0;
  app.request = () => {requests++; return new Promise(resolve => {finish = resolve;});};
  const first = Wizard.open();
  assert.equal(Wizard.open(), first, 'Repeated clicks share a single pending opening');
  assert.equal(requests, 1);
  assert.equal(app.lastModal, undefined, 'No loading modal before the response');
  finish(response());
  await first;
  assert.equal(app.lastModal.ModalClass, Wizard);
  assert.equal(app.lastModal.attrs.wizard.ready, true, 'Modal receives fully loaded state');
  const opened = new Wizard();
  opened.oninit({attrs: {...app.lastModal.attrs, state: {modal: {attrs: {}}}}});
  assert.equal(requests, 1, 'Mounting does not fetch the draft a second time');
  opened.wizard.dispose();

  app.lastModal = undefined;
  app.request = async () => {throw {response: {errors: [{detail: 'Нет соединения'}]}};};
  await Wizard.open();
  assert.equal(app.lastModal, undefined, 'Failure never opens an empty window');
  assert.deepEqual(alerts, ['Нет соединения']);
  app.request = async () => response();
  await Wizard.open();
  assert.ok(app.lastModal.attrs.wizard.ready, 'A failed opening can be retried');
  app.lastModal.attrs.wizard.dispose();

  app.lastModal = undefined;
  app.request = () => new Promise(resolve => {finish = resolve;});
  const stale = Wizard.open();
  route = '/another-page';
  finish(response());
  await stale;
  assert.equal(app.lastModal, undefined, 'A late response does not interrupt navigation');

  let editUrl;
  const profile = {selected: 42};
  app.request = async request => {editUrl = request.url; return {...response(), data: {...response().data, character_revision: 3}};};
  await Wizard.open(profile);
  assert.ok(editUrl.endsWith('/roleplay/characters/42/mechanics'));
  assert.equal(app.lastModal.attrs.characterId, 42);
  assert.equal(app.lastModal.attrs.profile, profile);
  assert.ok(app.lastModal.attrs.wizard.ready, 'Editing also opens with data already loaded');
  app.lastModal.attrs.wizard.dispose();
  console.log('Wizard opening: delayed response, duplicate clicks, one fetch, failure/retry, navigation and editing passed.');
})().catch(error => {console.error(error); process.exitCode = 1;});
