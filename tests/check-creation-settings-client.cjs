const assert = require('node:assert/strict');
const {loadClient} = require('./client-runtime.cjs');
const tick = () => new Promise(resolve => setImmediate(resolve));
(async () => {
  const {modules, m, app, expand} = loadClient('admin');
  const Panel = modules.get('admin/settings/CreationSettingsPanel').default || modules.get('admin/settings/CreationSettingsPanel');
  const data = {revision: 6, configuration: {enabled: true, max_characters: 1, starting_gold: 10, starting_experience: 0,
    attribute_distribution: [3, 2, 1, 1], starting_spell_count: 2, perk_sources: {}, application: {},
    loadout: [{key: 'group-a', name: 'Снаряжение', min: 1, max: 1, automatic: false,
      options: [{key: 'kit', name: 'Набор', items: [{item_id: 1, quantity: 2}]}]}]},
    lookups: {sources: [{id: 1, name: 'Раса', enabled: true}], items: [{id: 1, name: 'Меч'}]}};
  app.result = data;
  const panel = new Panel();
  panel.oninit(m(Panel));
  await tick();
  const nodes = expand(panel.view());
  assert.ok(nodes.some(node => node.tag === 'fieldset'), 'Loadout group has editor');
  assert.ok(nodes.some(node => node.tag === 'button' && JSON.stringify(node.children).includes('Добавить группу')));
  assert.ok(nodes.some(node => node.tag === 'input' && node.attrs.max === 1000000), 'Item count validated separately from group limit');
  const forms = nodes.filter(node => node.tag === 'form');
  assert.equal(forms.length, 1, 'No nested submit forms');
  const quantity = nodes.find(node => node.tag === 'input' && node.attrs['aria-label'] === 'Количество');
  quantity.attrs.oninput({target: {value: '3'}});
  assert.equal(panel.settings.data.configuration.loadout[0].options[0].items[0].quantity, 3, 'Compact count updates the same configuration');
  panel.onremove();

  const exported = name => modules.get(name).default || modules.get(name);
  const Icons = exported('admin/settings/AttributeIconsPanel');
  const Api = exported('admin/settings/AttributeIconsApi');
  const iconsData = {revision: 8, rows: [1, 2, 3, 4].map(id => ({id, name: 'Характеристика ' + id, icon: 'fas fa-star'}))};
  app.result = iconsData;
  const icons = new Icons(); icons.oninit(m(Icons)); await tick();
  const iconNodes = expand(icons.view());
  assert.equal(iconNodes.filter(node => node.tag === 'input').length, 4, 'All four icons share one form');
  assert.equal(iconNodes.filter(node => node.tag === 'button' && node.attrs.type === 'submit').length, 1, 'Single save button');
  iconNodes.find(node => node.tag === 'input').attrs.oninput({target: {value: 'fas fa-bolt'}});
  assert.equal(icons.settings.data.rows[0].icon, 'fas fa-bolt');
  const requests = [];
  app.request = async request => {requests.push(request); return {data: iconsData};};
  await new Api().save(icons.settings.data);
  assert.equal(requests[0].body.icons.length, 4, 'One request saves every icon');
  assert.deepEqual(Object.keys(requests[0].body.icons[0]), ['id', 'icon'], 'Names and game rules cannot be overwritten');
  icons.onremove();
  console.log('Creation settings UI: complete starting configuration, loadout editors and keyed selectors passed.');
})().catch(error => {console.error(error); process.exit(1);});
