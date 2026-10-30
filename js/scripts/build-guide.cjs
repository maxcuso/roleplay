const fs = require('node:fs');
const path = require('node:path');
const postcss = require('postcss');

/** Один источник макета: при сборке выбираем содержимое и изолируем CSS для Flarum.
 * Тексты редактируются в preview/role/index.html, оформление — в style.css рядом.
 * Никакие файлы tests/.runtime и локальные настройки в страницу не попадают.
 */
module.exports = function buildGuide() {
  const root = path.resolve(__dirname, '../..');
  const source = path.join(root, 'preview/role');
  const target = path.join(root, 'resources/guide');
  fs.mkdirSync(target, {recursive: true});

  const html = fs.readFileSync(path.join(source, 'index.html'), 'utf8');
  const main = html.match(/<main>([\s\S]*?)<\/main>/)?.[1];
  const dialog = html.match(/<dialog\b[\s\S]*?<\/dialog>/)?.[0];
  if (!main || !dialog) throw new Error('Не найдены содержимое или диалог страницы /role.');
  // Галерея содержит только вымышленные анкеты стенда; на форум её не публикуем.
  const galleryStart = main.indexOf('<!--04. Галерея');
  const content = galleryStart === -1 ? main : main.slice(0, galleryStart);
  const notice = '<!-- Собрано из preview/role/index.html. Правьте исходник и пересоберите расширение. -->\n';
  fs.writeFileSync(path.join(target, 'content.html'), notice + content + '\n' + dialog + '\n');

  const css = postcss.parse(fs.readFileSync(path.join(source, 'style.css'), 'utf8'));
  // Автономный макет использует свой набор Free-иконок. На форуме классы fas/fad/far
  // должны получать шрифт и символы от установленного Font Awesome, включая Pro.
  // Иначе .Icon перекрывает шрифт темы и превращает, например, fa-mandolin в квадрат.
  css.walkAtRules('font-face', rule => {
    if (rule.nodes.some(node => node.prop === 'font-family' && /Role Icons/.test(node.value))) rule.remove();
  });
  css.walkRules(rule => {
    if (rule.parent.type === 'atrule' && /keyframes$/i.test(rule.parent.name)) return;
    if (rule.selector === '.Icon::before') {rule.remove(); return;}
    if (rule.selector === '.Icon') {
      rule.walkDecls(declaration => {
        if (['font-family', 'font-weight'].includes(declaration.prop)) declaration.remove();
      });
    }
    rule.selectors = rule.selectors.map(selector => {
      if (/^:root/.test(selector)) return selector.replace(/^:root/, '.RpGuide');
      if (selector === 'html') return 'html.GuidePage';
      if (selector === 'body') return '.RpGuide';
      return '.RpGuide ' + selector;
    });
  });
  css.walkDecls(declaration => {
    // Итоговый CSS Flarum лежит в assets/. Относительный путь учитывает
    // также установку форума в подкаталоге, например /forum/role/media/.
    declaration.value = declaration.value.replace(/url\((["']?)([^)"']+)\1\)/g, (match, quote, url) => {
      if (url.startsWith('/role/assets/')) return 'url("../role/media/' + url.slice('/role/assets/'.length) + '/file")';
      if (/^[.]{1,2}\/assets\/fonts\//.test(url)) return 'url("../role/media/fonts/' + path.basename(url) + '/file")';
      if (url.startsWith('/assets/fonts/')) return 'url("..' + url + '")';
      return match;
    });
    // Имена шрифтов тоже изолируем: тема форума может иметь свой Jost.
    declaration.value = declaration.value.replace(/(["'])(Jost|Oracle|Pulp|Role Icons)\1/g, '"Guide $2"');
  });
  // Flarum пропускает стили через Less. Передаём значения как готовый CSS,
  // чтобы min(100%,180px), calc() и дробные значения не вычислялись компилятором.
  css.walkDecls(declaration => {
    const quote = declaration.value.includes('"') ? "'" : '"';
    if (declaration.value.includes(quote)) throw new Error('Нужны однородные кавычки в CSS: ' + declaration.toString());
    declaration.value = '~' + quote + declaration.value + quote;
  });
  fs.writeFileSync(path.join(target, 'style.less'), '/* Собрано из preview/role/style.css. */\n' + css.toString());
};
