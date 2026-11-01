const assert = require('node:assert/strict');
const {loadClient} = require('./client-runtime.cjs');
const {app, modules, IndexSidebar, HeaderSecondary} = loadClient('forum');
const Wizard = modules.get('forum/creation/CreationWizardModal').default || modules.get('forum/creation/CreationWizardModal');

let tag = 'role', staff = false, canCreate = true, opened = 0;
const colors = {role: '#b78aee', characters: '#80b77e'};
app.store.all = () => Object.keys(colors).map(slug => ({attribute: key => ({slug, color: colors[slug]})[key]}));
app.search.state.params = () => ({tags: tag});
app.forum.attribute = key => ({roleplayCanCreate: canCreate, roleplayCanModerate: staff})[key];
Wizard.open = () => {opened++; return Promise.resolve();};
const actions = items => items.get('roleplayActions').children.filter(Boolean);
for (tag of ['role', 'characters']) {
  const items = new IndexSidebar().items();
  const [create, links] = actions(items);
  const [guide, character] = links.children;
  assert.equal(create.children[0], 'Создать персонажа');
  assert.equal(create.attrs.style['--color'], colors[tag]);
  assert.ok(create.attrs.className.includes('Button--tagColored'));
  create.attrs.onclick();
  assert.equal(guide.children[0], 'Путеводитель');
  assert.equal(guide.attrs.href, '/role');
  assert.equal(guide.attrs.className, 'Button');
  assert.ok(character, 'Character navigation remains next to the guide');
  assert.equal(create.attrs.disabled, false);
  assert.equal(items.has('roleplayDiscussion'), false);
  assert.equal(items.has('roleplayGuide'), false, 'No duplicate guide link in the top navigation');
  assert.equal(items.get('newDiscussion').attrs.itemClassName, 'RpSidebarItem--hidden');
  assert.equal(items.toArray()[0], items.get('roleplayActions'));
}
assert.equal(opened, 2);
staff = true;
const staffItems = new IndexSidebar().items();
assert.equal(staffItems.get('roleplayDiscussion').attrs.itemClassName, undefined);
assert.equal(staffItems.get('roleplayDiscussion').children[0], 'Создать тему');
assert.equal(staffItems.toArray()[0], staffItems.get('roleplayDiscussion'));
assert.equal(staffItems.toArray()[1], staffItems.get('roleplayActions'));
staffItems.get('roleplayDiscussion').attrs.onclick();
assert.equal(app.discussionOpened, true, 'Staff retains the real discussion action');
staff = false;
canCreate = false;
assert.equal(actions(new IndexSidebar().items()).length, 1, 'Guide remains when creation is unavailable');
app.session.user = null;
canCreate = true;
staff = true;
const guest = new IndexSidebar().items();
assert.equal(actions(guest).length, 2, 'Visitors see a creation action that opens login');
assert.equal(actions(guest)[0].attrs.disabled, false);
assert.equal(actions(guest)[0].children[0], 'Создать персонажа');
assert.equal(guest.has('roleplayDiscussion'), false);
for (tag of ['general', undefined, 'role,characters']) {
  const items = new IndexSidebar().items();
  assert.equal(items.get('newDiscussion').children[0], 'Start a Discussion');
  assert.equal(items.has('roleplayActions'), false);
}
assert.equal(new HeaderSecondary().items().has('roleplayCreation'), false);
assert.equal(app.routes['roleplay.creation'], undefined);
console.log('Roleplay sidebar: tag colors, staff-only topic first, create/guide order, guests and unrelated pages passed.');
