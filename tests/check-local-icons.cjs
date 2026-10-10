const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const {spawnSync} = require('node:child_process');

const root = path.resolve(__dirname, '..');
const js = path.join(root, 'js');
const filename = path.join(root, 'resources/presentation/icons.local.json');
const originalLocal = fs.existsSync(filename) ? fs.readFileSync(filename) : null;
const iconsPath = path.join(root, 'resources/presentation/icons.json');
const originalIcons = fs.readFileSync(iconsPath);
const docker = process.argv[2] || 'docker';

// Эти замены проверяют служебную иконку, иконку сущности и метаданные пакета.
// Вторая замена в цепочке специально не должна примениться к первой.
const replacements = {
  'fas fa-swords': 'fas fa-fist-raised',
  'fas fa-fist-raised': 'fas fa-hand-paper',
  'fas fa-eye': 'fas fa-user',
  'fas fa-scroll': 'fas fa-book',
};

function run(command, args, options = {}) {
  const result = spawnSync(command, args, {cwd: js, encoding: 'utf8', ...options});
  if (result.error || result.status !== 0) {
    throw new Error(String(result.error || result.stderr || result.stdout));
  }
  return result.stdout;
}

function build() {
  run(process.execPath, [path.join(js, 'node_modules/webpack-cli/bin/cli.js'), '--mode', 'production']);
}

function compiledIcons() {
  return require('./client-runtime.cjs').loadClient().modules.get('common/uiIcons');
}
try {
  for (const mode of ['enabled', 'disabled', 'absent']) {
    if (mode === 'absent') fs.unlinkSync(filename);
    else fs.writeFileSync(filename, JSON.stringify({enabled: mode === 'enabled', replacements}));
    build();

    const local = mode === 'enabled';
    const ui = compiledIcons();
    const php = JSON.parse(run(docker, ['compose', 'exec', '-T', 'forum', 'php',
      '/var/www/html/packages/roleplay-new/tests/check-local-icons.php'], {
      cwd: path.resolve(root, '../..'),
    }));
    const expected = {
      entity: local ? 'fas fa-fist-raised' : 'fas fa-swords',
      unknown: 'fas fa-custom',
      fallback: 'fas fa-question-circle',
      permission: local ? 'fas fa-user' : 'fas fa-eye',
      extension: local ? 'fas fa-book' : 'fas fa-scroll',
    };
    assert.deepEqual(php, expected);
    assert.equal(ui.catalogueIcon('fas fa-swords'), expected.entity);
    assert.equal(ui.catalogueIcon('fas fa-custom'), expected.unknown);
    assert.equal(ui.catalogueIcon(null), expected.fallback);
    assert.equal(ui.uiIcon('permissions.view'), expected.permission);
    assert.equal(ui.uiIcon('extension'), expected.extension);
    const composer = JSON.parse(fs.readFileSync(path.join(root, 'composer.json'), 'utf8'));
    assert.equal(composer.extra['flarum-extension'].icon.name, expected.extension);
    console.log('JS, PHP and Composer (' + mode + '): OK');
  }
  assert.deepEqual(fs.readFileSync(iconsPath), originalIcons);
} finally {
  // Даже при ошибке возвращаем настройки разработчика и сборку в исходный локальный режим.
  if (originalLocal !== null) fs.writeFileSync(filename, originalLocal);
  else if (fs.existsSync(filename)) fs.unlinkSync(filename);
  build();
}