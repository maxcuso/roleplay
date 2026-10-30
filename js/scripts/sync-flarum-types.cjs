const fs = require('node:fs');
const path = require('node:path');

// Типы берём из установленной версии ядра, а не храним чужие исходники в Git.
// Пример: npm run types:sync -- /forum/vendor/flarum/core/js/dist-typings
const sourceArgument = process.argv[2];
if (!sourceArgument) {
  throw new Error('Укажите каталог vendor/flarum/core/js/dist-typings установленного форума.');
}
const source = fs.realpathSync(path.resolve(sourceArgument));
const jsRoot = path.resolve(__dirname, '..');
const target = path.resolve(jsRoot, '.flarum-typings');
const sections = ['@types', 'admin', 'common', 'forum'];
const comparablePath = value => process.platform === 'win32' ? value.toLowerCase() : value;

for (const name of sections) {
  if (!fs.statSync(path.join(source, name)).isDirectory()) {
    throw new Error('Неполный каталог типов Flarum: отсутствует ' + name);
  }
}

// Удалять разрешено только фиксированный каталог сгенерированных типов внутри js.
// Ссылку или источник, расположенный внутри него, не трогаем.
if (path.relative(jsRoot, target) !== '.flarum-typings'
    || comparablePath(source) === comparablePath(target)
    || comparablePath(source).startsWith(comparablePath(target) + path.sep)
    || (fs.existsSync(target) && fs.lstatSync(target).isSymbolicLink())) {
  throw new Error('Небезопасный путь каталога типов.');
}
fs.rmSync(target, {recursive: true, force: true});
fs.mkdirSync(target);
for (const name of sections) {
  fs.cpSync(path.join(source, name), path.join(target, name), {recursive: true});
}
console.log('Типы Flarum обновлены из ' + source);
