const assert = require('node:assert/strict');
const {loadClient} = require('./client-runtime.cjs');
const {app, modules, IndexSidebar, HeaderSecondary} = loadClient('forum');
const Wizard = modules.get('forum/creation/CreationWizardModal').default || modules.get('forum/creation/CreationWizardModal');

let tag = 'role', staff = false, canCreate = true;
app.search.state.params = () => ({tags: tag});
app.forum.attribute = key => ({roleplayCanCreate: canCreate, roleplayCanModerate: staff})[key];
for (tag of ['role', 'characters']) {
  const items = new IndexSidebar().items();
  const button = items.get('newDiscussion');
  assert.equal(button.children[0], 'Создать персонажа');
  assert.equal(button.attrs.href, undefined);
  button.attrs.onclick();
  assert.equal(app.lastModal.ModalClass, Wizard);
  assert.equal(button.attrs.itemClassName, 'App-primaryControl');
  assert.equal(items.has('roleplayDiscussion'), false);
}
staff = true;
const staffItems = new IndexSidebar().items();
assert.equal(staffItems.get('roleplayDiscussion').attrs.itemClassName, undefined);
staffItems.get('roleplayDiscussion').attrs.onclick();
assert.equal(app.discussionOpened, true, 'Staff retains the real discussion action');
staff = false;
canCreate = false;
assert.equal(new IndexSidebar().items().has('newDiscussion'), false);
app.session.user = null;
canCreate = true;
assert.equal(new IndexSidebar().items().has('newDiscussion'), false);
for (tag of ['general', undefined, 'role,characters']) {
  assert.equal(new IndexSidebar().items().get('newDiscussion').children[0], 'Start a Discussion');
}
assert.equal(new HeaderSecondary().items().has('roleplayCreation'), false);
assert.equal(app.routes['roleplay.creation'], undefined, 'No standalone creation page');
console.log('Creation button checks passed: both tags, staff action, unrelated pages and no header injection.');
