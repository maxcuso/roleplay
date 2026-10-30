const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const ts = require('../js/node_modules/typescript');

// Flarum 2 запускает initializers до app.forum: проверка владения должна
// работать на bootstrap-данных и не ломать маршруты форума или админки.
const bootstrapModule = {exports: {}};
const bootstrapApp = {data: {resources: [{type: 'forums', id: '1', attributes: {roleplayEventsAvailable: true}}]}};
const bootstrapJs = ts.transpileModule(fs.readFileSync(path.resolve(__dirname, '../js/src/common/eventsAvailable.ts'), 'utf8'), {
  compilerOptions: {module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020},
}).outputText;
vm.runInNewContext('(function(require,module,exports){' + bootstrapJs + '\n})', {})(() => ({default: bootstrapApp}), bootstrapModule, bootstrapModule.exports);
assert.equal(bootstrapModule.exports.eventsAvailable(), true, 'Integrated module boots before app.forum exists');
bootstrapApp.data.resources[0].attributes.roleplayEventsAvailable = false;
assert.equal(bootstrapModule.exports.eventsAvailable(), false, 'Legacy owner does not activate another event frontend');

const sourceRoot = path.resolve(__dirname, '../js/src/forum/events');
const modules = new Map();
const calls = [], modals = [], warnings = [];
let serverEvent;
class Component { oninit(vnode) { this.attrs = vnode.attrs; } }
const app = {
  forum: {attribute: key => key === 'apiUrl' ? '/api' : attributes[key]},
  data: {settings: {}},
  modal: {show: (component, attrs) => modals.push({component, attrs}), close() {}},
  alerts: {show: (_attrs, message) => warnings.push(message)},
  request: async options => {
    calls.push(options);
    if (options.method === 'GET') return {data: serverEvent};
    return {data: {}};
  },
};
const attributes = {canCreateQuestEvents: true, questEventsRoleplayAvailable: true, questEventsQuestsEnabled: true};
const m = () => {}; m.redraw = () => {};
function load(file) {
  file = path.resolve(file);
  if (modules.has(file)) return modules.get(file).exports;
  const module = {exports: {}}; modules.set(file, module);
  const js = ts.transpileModule(fs.readFileSync(file, 'utf8'), {
    compilerOptions: {module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020},
  }).outputText;
  const requireLocal = name => {
    if (name.endsWith('/app')) return {default: app};
    if (name.includes('flarum/')) return {default: Component};
    return load(path.resolve(path.dirname(file), name + '.js'));
  };
  vm.runInNewContext('(function(require,module,exports){' + js + '\n})', {m, setTimeout, clearTimeout})(requireLocal, module, module.exports);
  return module.exports;
}
(async () => {
  const Form = load(path.join(sourceRoot, 'components/EventForm.js')).default;
  const form = new Form();
  assert.equal(form.questsEnabled(), true);
  attributes.canCreateQuestEvents = false;
  assert.equal(form.questsEnabled(), false, 'Ordinary event creators cannot select roleplay quests');
  attributes.canCreateQuestEvents = true;
  attributes.questEventsRoleplayAvailable = false;
  assert.equal(form.questsEnabled(), false, 'Quest choice requires the connected Roleplay provider');
  attributes.questEventsRoleplayAvailable = true;
  attributes.questEventsQuestsEnabled = false;
  assert.equal(form.questsEnabled(), false, 'Existing disable setting is respected');
  const {changeParticipation} = load(path.join(sourceRoot, 'utils/eventCardState.js'));
  const event = choices => ({title: 'Quest', registrationOptions: {field: 'characterId', title: 'Выбор персонажа', choices}});
  const state = {id: 8, data: event([]), busy: false, loading: false};
  assert.equal(await changeParticipation(state), false);
  assert.equal(calls.length, 0, 'No character cannot submit registration');
  state.data = serverEvent = event([{id: 11, label: 'One'}]);
  await changeParticipation(state);
  assert.equal(calls[0].method, 'POST');
  assert.equal(calls[0].body.characterId, 11, 'Single character is submitted directly');
  assert.equal(modals.length, 0);
  calls.length = 0;
  state.data = serverEvent = event([{id: 11, label: 'One'}, {id: 12, label: 'Two'}]);
  await changeParticipation(state);
  assert.equal(calls.length, 0, 'Opening selection does not reserve a slot');
  assert.equal(modals.length, 1);
  await modals[0].attrs.onselect({characterId: 12});
  assert.equal(calls[0].body.characterId, 12, 'Selected character is sent through the original registration endpoint');
  calls.length = 0;
  await changeParticipation(state, true);
  assert.equal(calls[0].method, 'DELETE');
  assert.equal(calls[0].body, undefined, 'Leaving does not ask for a character again');
  assert.equal(modals.length, 1);
  console.log('Quest client checks passed: availability, no/single/multiple characters and leaving.');
})().catch(error => {console.error(error); process.exitCode = 1;});
