const fs = require('node:fs');
const path = require('node:path');
const {resolveLocalIcon} = require('./local-icons.cjs');

/**
 * Flarum читает значок расширения из Composer.
 * При сборке копируем только это значение из общего файла, сохраняя остальные настройки.
 */
module.exports = function syncExtensionIcon() {
  const root = path.resolve(__dirname, '../..');
  const icons = JSON.parse(fs.readFileSync(path.join(root, 'resources/presentation/icons.json'), 'utf8'));
  const name = resolveLocalIcon(icons.extension);
  if (typeof name !== 'string' || !/^[a-zA-Z0-9_-]+(?: +[a-zA-Z0-9_-]+)*$/.test(name)) {
    throw new Error('В файле иконок неверно задан ключ extension.');
  }

  const filename = path.join(root, 'composer.json');
  const composer = JSON.parse(fs.readFileSync(filename, 'utf8'));
  const icon = composer.extra['flarum-extension'].icon;
  if (icon.name === name) return false;

  icon.name = name;
  fs.writeFileSync(filename, JSON.stringify(composer, null, 2) + '\n', 'utf8');
  return true;
};
