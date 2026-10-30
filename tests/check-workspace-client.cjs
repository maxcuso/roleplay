const assert = require('node:assert/strict');
const {loadClient} = require('./client-runtime.cjs');
const tick = () => new Promise(resolve => setImmediate(resolve));

(async () => {
  const {modules, m, app, expand, requests} = loadClient('forum');
  const get = name => modules.get(name).default || modules.get(name);
  const Catalog = get('forum/workspace/WorkspaceCatalog');
  app.result = {revision: 7, total: 1, page: 1, per_page: 30, lookups: {},
    rows: [{id: 1, name: 'Проверочный перк', description: 'Описание', rarity: 'common', enabled: true, effects: []}],
    capabilities: {view: true, create: false, edit: false, delete: false}};
  const catalog = new Catalog();
  catalog.oninit(m(Catalog, {catalog: 'perks'}));
  await tick();
  let nodes = expand(catalog.view());
  const buttons = () => nodes.filter(node => node.tag === 'button');
  assert.equal(buttons().filter(node => /Создать|Изменить|Удалить|Выключить/.test(JSON.stringify(node.children))).length, 0,
    'Read-only catalog never exposes write buttons');
  assert.ok(nodes.some(node => node.tag === 'input' && node.attrs.type === 'search'));
  assert.ok(nodes.some(node => node.tag === 'select'), 'Shared filters render without mixed-key Mithril errors');
  catalog.list.data.capabilities = {view: true, create: true, edit: true, delete: false};
  nodes = expand(catalog.view());
  assert.ok(buttons().some(node => JSON.stringify(node.children).includes('Изменить')));
  assert.ok(!buttons().some(node => JSON.stringify(node.children).includes('Удалить')),
    'Delete button requires its own permission');
  catalog.edit(catalog.list.data.rows[0]);
  assert.equal(app.lastModal.ModalClass, get('common/catalog/components/CatalogEditModal'), 'Workspace uses the shared editor');
  catalog.onremove();

  const Page = get('forum/workspace/WorkspacePage');
  const page = new Page();
  page.user = {id: () => 2};
  const before = requests.length;
  expand(page.content());
  assert.equal(requests.length, before, 'Opening another profile does not request workspace data');

  app.result = {rows: [], nextBefore: null};
  page.user = {id: () => 1};
  expand(page.content());
  await tick();
  const Episodes = get('forum/workspace/WorkspaceEpisodes');
  const episodes = new Episodes();
  episodes.oninit();
  await tick();
  assert.ok(requests.at(-1).url.endsWith('/roleplay/workspace/episodes'));
  assert.equal(requests.at(-1).params.type, 'quest');
  expand(episodes.view());
  app.result = {rows: [{id: 11, title: 'История', discussionId: 22, status: 'active', participantCount: 1,
    participants: [{id: 2, slug: 'member', displayName: 'Игрок', character: {id: 77, name: 'Герой'}}], canManage: false}], nextBefore: null};
  await episodes.load();
  const episodeNodes = expand(episodes.view());
  assert.ok(episodeNodes.some(node => node.tag === 'a' && node.attrs.href === '/u/member/roleplay?character=77'),
    'Participant link selects the bound character in the existing profile');
  assert.ok(!episodeNodes.some(node => node.tag === 'button' && JSON.stringify(node.children).includes('Управление')),
    'Episode lead without event-management permission gets no editor button');
  episodes.onremove();
  console.log('Workspace client checks passed: cards, filters, separate permissions, shared editor and own-profile boundary');
})().catch(error => {console.error(error); process.exit(1);});
