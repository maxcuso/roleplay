/**
 * Локальный визуальный стенд. Запуск из js: npm run preview.
 * Webpack следит за общими компонентами; CSS меняется без перезагрузки формы.
 * Сервер слушает только 127.0.0.1 и отдаёт только перечисленные файлы.
 * Каталог tests/.runtime целиком никогда не публикуется: там могут лежать отчёты и копии базы.
 */
const fs = require('node:fs');
const path = require('node:path');
const http = require('node:http');
const {spawnSync} = require('node:child_process');
const jsRoot = path.resolve(__dirname, '../js');
process.chdir(jsRoot);
const webpack = require(path.join(jsRoot, 'node_modules/webpack'));
const packageRoot = path.resolve(__dirname, '..');
const runtime = path.join(packageRoot, 'tests/.runtime');
const assets = path.join(runtime, 'preview-assets');
const port = Number(process.env.ROLEPLAY_PREVIEW_PORT || 4173);
const clients = new Set();
const announce = type => {
  for (const response of clients) response.write('event: ' + type + '\ndata: {}\n\n');
};

// Шрифты и версия Mithril берутся из локальной тестовой установки Flarum.
// Персонажи, аккаунты и база данных не читаются.
function prepareAssets() {
  fs.mkdirSync(assets, {recursive: true});
  const docker = process.env.ROLEPLAY_PREVIEW_DOCKER ||
    path.join(process.env.LOCALAPPDATA || '', 'Programs/DockerDesktop/resources/bin/docker.exe');
  const copy = (remote, target) => {
    if (fs.existsSync(target)) return;
    const result = spawnSync(docker, ['cp', 'questpost-roleplay-test-forum-1:' + remote, target], {encoding: 'utf8', windowsHide: true});
    if (result.status !== 0) throw new Error('Не удалось подготовить стенд: ' + (result.stderr || result.error));
  };
  copy('/var/www/html/public/assets/admin.js.map', path.join(runtime, 'core-admin.js.map'));
  for (const name of ['fa-solid-900.woff2', 'fa-regular-400.woff2', 'fa-brands-400.woff2']) {
    copy('/var/www/html/public/assets/fonts/' + name, path.join(assets, name));
  }
  const cssPath = path.join(assets, 'fontawesome.css');
  if (!fs.existsSync(cssPath)) {
    const source = path.join(assets, '_forum.css');
    copy('/var/www/html/public/assets/forum.css', source);
    const css = fs.readFileSync(source, 'utf8');
    // Только таблица символов иконок. Стили самого расширения используются напрямую.
    const rules = css.match(/(?:\.fa-[a-z0-9-]+(?:::?before)?\s*,?\s*)+\{[^}]*\}/g) || [];
    if (!rules.length) throw new Error('Не найдены символы Font Awesome в стилях форума.');
    const notice = css.match(/\/\*![\s\S]*?Font Awesome[\s\S]*?\*\//)?.[0] ||
      '/* Font Awesome Free — https://fontawesome.com/license/free */';
    fs.writeFileSync(cssPath, notice + '\n' + rules.join('\n'));
  }
}
prepareAssets();

// Шрифты страницы читаются только из этой папки и добавляются в список маршрутов.
// После добавления нового файла шрифта перезапустите сервер.
const roleFontDirectory = path.join(__dirname, 'role/assets/fonts');
const roleFontNames = fs.readdirSync(roleFontDirectory, {withFileTypes: true})
  .filter(entry => entry.isFile() && /\.(?:ttf|otf|woff2?)$/i.test(entry.name))
  .map(entry => entry.name);

// Выдаём только шесть утверждённых гербов, без исходников и служебных файлов.
const cityCrestNames = [
  "questpost-v2.png",
  "verkhovye-v3.png",
  "zelenolesye-v3.png",
  "arah-v3.png",
  "hillsgaard-v3.png",
  "grim-v5.png"
];
const raceImageNames = ['Human.png', 'Elf.png', 'HalfElf.png', 'Dwarf.png',
  'Halfling.png', 'HalfOrc.png', 'Tabaxi.png', 'Tiefling.png'];
// Готовые гербы всех организаций страницы; исходники — content/flags/src.
const organizationCrestNames = [
  "guild-questpost-v1.png",
  "kamnegriv-v1.png",
  "gundar-v2.png",
  "brennor-v1.png",
  "aelsir-v1.png",
  "talveyn-v1.png",
  "saelrin-v1.png",
  "dalmir-v1.png",
  "liorel-v1.png",
  "almeon-v1.png",
  "tkhist-v1.png",
  "khadim-v1.png",
  "erta-v1.png",
  "sylvanhart-v1.png",
  "grossblood-purple-v2.png",
  "nod-v1.png",
  "klinki-v1.png",
  "collegium-v4.png",
  "pantheon-v2.png"
];
const cityBackgroundNames = [
  "questpost.png",
  "zelenolesye.webp",
  "verkhovye.webp",
  "arah.webp",
  "hillsgaard.webp",
  "grim.webp"
];
const bannerIconNames = ['create.png', 'badge.png', 'race.png', 'castle.png', 'map.png',
  'condition.png', 'fist.png', 'cause-falls.png', 'no-struggle.png', 'dice.png', 'up-arrow.svg'];

const routes = new Map([
  ['/', path.join(__dirname, 'index.html')],
  ['/index.html', path.join(__dirname, 'index.html')],
  ['/stage.html', path.join(__dirname, 'stage.html')],
  // Макет главной ролевой живёт рядом со стендом мастера, на согласованном /role.
  ['/role', path.join(__dirname, 'role/index.html')],
  ['/role/', path.join(__dirname, 'role/index.html')],
  // Основной текст уже находится в role/index.html; парсер и md-файлы не выдаются.
  ...['style.css', 'page.js', 'content.js'].map(name => ['/role/' + name, path.join(__dirname, 'role', name)]),
  ['/role/runtime.js', path.join(packageRoot, 'resources/guide/page.js')],
  ['/role/icons.json', path.join(packageRoot, 'resources/presentation/icons.json')],
  ['/role/icons.local.json', path.join(packageRoot, 'resources/presentation/icons.local.json')],
  ...['bridge-hero.png', 'hero-background.jpg', 'locations.jpg', 'character-portraits.png', 'badge-circle.webp'].map(name => ['/role/assets/' + name, path.join(__dirname, 'role/assets', name)]),
  ...cityCrestNames.map(name => ['/role/assets/city-crests/' + name, path.join(__dirname, 'role/assets/city-crests', name)]),
  ...cityBackgroundNames.map(name => ['/role/assets/city-backgrounds/' + name, path.join(__dirname, 'role/assets/city-backgrounds', name)]),
  ...raceImageNames.map(name => ['/role/assets/races/' + name, path.join(__dirname, 'role/assets/races', name)]),
  ...organizationCrestNames.map(name => ['/role/assets/organization-crests/' + name, path.join(__dirname, 'role/assets/organization-crests', name)]),
  ...bannerIconNames.map(name => ['/role/assets/banner-icons/' + name, path.join(__dirname, 'role/assets/banner-icons', name)]),
  // В CSS ./assets/fonts ведёт в /role/assets/fonts, а ../assets/fonts — в /assets/fonts.
  // Оба адреса отдаём из одной папки; это сохраняет текущие пути пользователя.
  ...roleFontNames.flatMap(name => [
    ['/role/assets/fonts/' + name, path.join(roleFontDirectory, name)],
    ['/assets/fonts/' + name, path.join(roleFontDirectory, name)],
    ['/role/media/fonts/' + name + '/file', path.join(roleFontDirectory, name)],
  ]),
  ['/role/assets/jost-bold.ttf', path.join(roleFontDirectory, 'jost-bold.ttf')],
  ['/preview.css', path.join(__dirname, 'preview.css')],
  ['/base.css', path.join(__dirname, 'base.css')],
  ['/preview.js', path.join(runtime, 'preview-dist/preview.js')],
  ['/preview.js.map', path.join(runtime, 'preview-dist/preview.js.map')],
  ['/styles/forum.css', path.join(packageRoot, 'resources/forum.css')],
  ['/styles/creation.css', path.join(packageRoot, 'resources/creation.css')],
  ['/role/media/locations.jpg/file', path.join(__dirname, 'role/assets/locations.jpg')],
  ['/styles/cards.css', path.join(packageRoot, 'resources/cards.css')],
  ['/assets/nature.svg', path.join(__dirname, 'assets/nature.svg')],
  ['/assets/fontawesome.css', path.join(assets, 'fontawesome.css')],
  ...['fa-solid-900.woff2', 'fa-regular-400.woff2', 'fa-brands-400.woff2']
    .map(name => ['/assets/fonts/' + name, path.join(assets, name)]),
]);
const mime = {'.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8', '.map': 'application/json', '.woff2': 'font/woff2', '.woff': 'font/woff', '.otf': 'font/otf',
  '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.ttf': 'font/ttf', '.json': 'application/json; charset=utf-8'};
const server = http.createServer((request, response) => {
  const url = new URL(request.url, 'http://127.0.0.1:' + port);
  if (url.pathname === '/events') {
    response.writeHead(200, {'Content-Type': 'text/event-stream', 'Cache-Control': 'no-cache', Connection: 'keep-alive'});
    response.write(': connected\n\n');
    clients.add(response);
    request.on('close', () => clients.delete(response));
    return;
  }
  const file = routes.get(url.pathname);
  if (!file) {response.writeHead(404); response.end('Not found'); return;}
  if (!fs.existsSync(file)) {response.writeHead(503); response.end('Preview is compiling. Refresh shortly.'); return;}
  response.writeHead(200, {'Content-Type': mime[path.extname(file)] || 'application/octet-stream',
    'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff'});
  fs.createReadStream(file).pipe(response);
});
server.on('error', error => {console.error(error.message); process.exitCode = 1; watcher.close(() => {});});
let firstBuild = true;
const watcher = webpack(require(path.join(jsRoot, 'webpack.preview.cjs'))).watch({aggregateTimeout: 150}, (error, stats) => {
  if (error || stats.hasErrors()) {
    console.error(error || stats.toString({all: false, errors: true}));
    return;
  }
  console.log('Предпросмотр собран.');
  if (firstBuild) {
    firstBuild = false;
    server.listen(port, '127.0.0.1', () => console.log('Открыть http://localhost:' + port));
  } else announce('bundle');
});
const watches = [];
for (const name of ['index.html', 'style.css', 'page.js', 'content.js']) {
  watches.push(fs.watch(path.join(__dirname, 'role', name), () => announce('role')));
}
watches.push(fs.watch(path.join(packageRoot, 'resources/guide/page.js'), () => announce('role')));
for (const file of [path.join(packageRoot, 'resources/forum.css'), path.join(packageRoot, 'resources/cards.css'),
  path.join(packageRoot, 'resources/creation.css'),
  path.join(__dirname, 'base.css'), path.join(__dirname, 'preview.css')]) {
  watches.push(fs.watch(file, () => announce('styles')));
}
const heartbeat = setInterval(() => {for (const response of clients) response.write(': alive\n\n');}, 25000);
function stop() {
  clearInterval(heartbeat);
  watches.forEach(watch => watch.close());
  clients.forEach(response => response.end());
  server.close();
  watcher.close(() => process.exit(0));
}
process.on('SIGINT', stop);
process.on('SIGTERM', stop);
