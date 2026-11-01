const assert = require('node:assert/strict');
const {loadClient} = require('./client-runtime.cjs');
(async () => {
  const {app, modules, IndexSidebar, expand, m} = loadClient('forum');
  const get = key => {const row = modules.get(key); return row.default || row;};
  const Wizard = get('forum/creation/CreationWizardModal');
  const ProfilePanel = get('forum/profile/ProfilePanel');
  const CharacterButton = get('forum/creation/CharacterButton');
  const flags = {roleplayCanCreate: true, roleplayCanView: true, roleplayCreationRequiresLogin: false};
  app.forum.attribute = key => flags[key];
  app.search.state.params = () => ({tags: 'role'});
  const user = (confirmed, guest = false) => ({id: () => 77, slug: () => 'test',
    attribute: key => key === 'arenaGuest' ? guest : undefined, isEmailConfirmed: () => confirmed});
  let requests = 0;
  app.request = async () => {requests++; throw new Error('Restricted account must never request wizard data');};
  const createButton = () => new IndexSidebar().items().get('roleplayActions').children.find(row => row?.attrs?.onclick);
  const profileCreate = () => {
    const profile = new ProfilePanel();
    profile.oninit({attrs: {own: true, picker: true,
      state: {view: null, selected: null, characters: [], loading: false, nextBefore: null}}});
    return expand(profile.view()).find(row => row.tag === 'button' && row.children?.some(child => child.children === 'Нельзя создать'));
  };
  for (const account of [null, user(true, true), user(false, true)]) {
    app.session.user = account;
    app.lastModal = undefined;
    const alerts = app.alerts.calls.length;
    await Wizard.open();
    assert.ok(app.lastModal, 'Visitors get login');
    assert.notEqual(app.lastModal.ModalClass, Wizard);
    assert.equal(app.lastModal.attrs?.wizard, undefined);
    assert.equal(app.alerts.calls.length, alerts, 'Guest login takes precedence over email warning');
    assert.equal(createButton().attrs.disabled, false);
    const navigation = new CharacterButton();
    await navigation.open();
    assert.equal(requests, 0, 'Guest character navigation does not read profiles or drafts');
  }
  app.session.user = user(true);
  flags.roleplayCreationRequiresLogin = true;
  await Wizard.open();
  assert.notEqual(app.lastModal.ModalClass, Wizard, 'Server guest flag works without Arena user field');
  flags.roleplayCreationRequiresLogin = false;
  app.session.user = user(false);
  for (const advertisedPermission of [true, false]) {
    flags.roleplayCanCreate = advertisedPermission;
    app.lastModal = undefined;
    const button = createButton();
    assert.equal(button.children[0], 'Нельзя создать');
    assert.equal(button.attrs.disabled, true);
    assert.equal(profileCreate().attrs.disabled, true);
    await Wizard.open(); // Same entry point as the landing page.
    assert.equal(app.lastModal, undefined);
    const alert = app.alerts.calls.at(-1);
    assert.equal(alert.children, 'Сначала подтвердите почту');
    assert.equal(alert.attrs.type, 'warning');
  }
  app.session.user = user(true);
  flags.roleplayCanCreate = true;
  assert.equal(createButton().attrs.disabled, false);
  assert.equal(createButton().children[0], 'Создать персонажа');
  assert.equal(requests, 0);
  console.log('Creation access UI: anonymous/Arena login, disabled unconfirmed buttons in sidebar/profile, landing email alert and confirmed-button state passed.');
})().catch(error => {console.error(error); process.exitCode = 1;});
