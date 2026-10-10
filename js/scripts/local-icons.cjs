const fs = require('node:fs');
const path = require('node:path');

const localIconsPath = path.resolve(__dirname, '../../resources/presentation/icons.local.json');
const iconClasses = /^[a-zA-Z0-9_-]+(?: +[a-zA-Z0-9_-]+)*$/;

/**
 * Файл существует только на машине разработчика. Без него возвращаем исходные иконки.
 * Замены задаются полными классами, а не названием сущности: одна замена работает везде.
 */
function loadLocalIcons() {
  if (!fs.existsSync(localIconsPath)) return {};

  const config = JSON.parse(fs.readFileSync(localIconsPath, 'utf8'));
  if (!config || typeof config !== 'object' || Array.isArray(config) || typeof config.enabled !== 'boolean') {
    throw new Error('В icons.local.json нужно явно задать enabled: true или false.');
  }
  if (!config.enabled) return {};

  const replacements = config.replacements;
  if (!replacements || typeof replacements !== 'object' || Array.isArray(replacements)) {
    throw new Error('В icons.local.json replacements должен быть объектом замен.');
  }
  for (const [original, replacement] of Object.entries(replacements)) {
    if (!iconClasses.test(original) || typeof replacement !== 'string' || !iconClasses.test(replacement)) {
      throw new Error('Неверные классы в локальной замене иконки: ' + original);
    }
  }
  return replacements;
}

/** Только одна подстановка: заменяющая иконка не запускает цепочку других замен. */
function resolveLocalIcon(value) {
  const replacements = loadLocalIcons();
  return Object.hasOwn(replacements, value) ? replacements[value] : value;
}

module.exports = {localIconsPath, loadLocalIcons, resolveLocalIcon};
