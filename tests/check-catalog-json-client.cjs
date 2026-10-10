const assert = require('node:assert/strict');
const {loadClient} = require('./client-runtime.cjs');

(async () => {
  const {modules, m, app, requests, pageClass, expand} = loadClient();
  const get = name => modules.get(name).default || modules.get(name);
  const State = get('admin/catalog/CatalogTransferState');
  const Modal = get('admin/catalog/components/CatalogTransferModal');
  const Section = get('admin/catalog/components/CatalogSection');
  const page = new pageClass(); page.oninit(m(pageClass, {}));
  page.selected = 'perks';
  const definition = page.content().children.find(node => node.tag === Section).attrs.definition;
  const state = new State();
  const file = {name: 'perks.json', size: 100, text: async () => '\uFEFF' + JSON.stringify({format: 'roleplay-catalogs', version: 1, catalogs: {perks: [{key: 'nature', name: 'Природа'}]}})};
  await state.readFile(file);
  assert.ok(state.document, 'Windows JSON file accepted');
  assert.equal(await state.apply(), false, 'Apply requires a server preview');
  const report = {revision: 7, applied: false, new: 1, updated: 0, skipped: 0, errors: [], warnings: [],
    rows: [{catalog: 'perks', key: 'nature', name: 'Природа', action: 'create'}]};
  app.result = report;
  await state.check(7);
  assert.equal(requests.at(-1).body.apply, false);
  state.setMode('update');
  assert.equal(state.report, undefined, 'Changing mode invalidates preview');
  assert.equal(await state.apply(), false);
  await state.check(8);
  app.result = {...report, revision: 8, applied: true};
  assert.equal(await state.apply(), true);
  assert.equal(requests.at(-1).body.revision, 7, 'Apply must use the preview revision, not a newer local value');
  assert.equal(requests.at(-1).body.mode, 'update');
  await state.readFile({...file, text: async () => '{broken'});
  assert.equal(state.document, undefined);
  assert.match(state.error, /JSON/);
  state.document = JSON.parse(await file.text().then(text => text.slice(1)));
  state.report = {...report, errors: ['missing-type']};
  assert.equal(await state.apply(), false, 'Invalid package cannot be applied');

  const list = {search: 'Природа', filters: {enabled: '1'}, data: {revision: 7}, load: async () => {}, error: ''};
  for (const kind of ['import', 'export']) {
    const modal = new Modal(); modal.oninit(m(Modal, {kind, definition, list}));
    if (kind === 'import') {modal.transfer.document = state.document; modal.transfer.report = report;}
    const nodes = expand(modal.view());
    assert.ok(nodes.some(node => node.tag === 'button'), 'Modal renders with actual Mithril');
    if (kind === 'import') assert.ok(nodes.some(node => node.tag === 'input' && node.attrs.type === 'file'));
    else assert.equal(nodes.filter(node => node.tag === 'button' && node.attrs['aria-pressed'] !== undefined).length, 3);
  }
  const section = new Section(); section.oninit(m(Section, {definition}));
  await Promise.resolve();
  section.list.data = {rows: [], total: 0, page: 1, per_page: 30, revision: 7, lookups: {perks: []}};
  section.list.loading = false;
  let nodes = expand(section.view());
  nodes.find(node => node.tag === 'button' && node.attrs['aria-label'] === 'Импорт JSON').attrs.onclick();
  assert.equal(app.lastModal.ModalClass, Modal, 'Toolbar opens import modal');
  assert.equal(app.lastModal.attrs.kind, 'import');
  section.attrs.definition = {...definition, key: 'characters', create: false};
  nodes = expand(section.view());
  assert.ok(!nodes.some(node => node.tag === 'button' && node.attrs['aria-label'] === 'Импорт JSON'), 'Characters are excluded');
  section.onremove();
  console.log('Catalog JSON client: preview/apply boundary, mode reset, file errors and modal wiring passed.');
})().catch(error => {console.error(error); process.exitCode = 1;});
