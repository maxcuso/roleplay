/** Локальная оболочка: общая логика страницы находится в resources/guide/page.js. */
import { guides, characters, attributes } from './content.js';
import { mountGuide } from '/role/runtime.js';

const [icons, localIcons] = await Promise.all([
    fetch('/role/icons.json').then(response => response.json()),
    fetch('/role/icons.local.json').then(response => response.json()),
]);
mountGuide(document.body, { icons, localIcons, guides, characters, attributes, storageKey: 'role-preview-guide' });

const action = name => document.querySelector('[data-action="' + name + '"]');
function paintIcon(element, key) {
    const requested = icons[key] || icons.fallback;
    element.className = 'Icon ' + (localIcons.enabled && localIcons.replacements[requested] || requested);
}
// ============================================================================
// 06. Светлая и тёмная темы
// Цвета обеих тем задаются переменными :root в начале style.css.
// ============================================================================
const themeButton = action('theme');

function setTheme(theme) {
    document.documentElement.dataset.theme = theme;
    const label = theme === 'dark' ? 'Включить светлую тему' : 'Включить тёмную тему';
    themeButton.setAttribute('aria-label', label);
    themeButton.title = label;
    paintIcon(themeButton.firstElementChild, theme === 'dark' ? 'landing.themeLight' : 'landing.themeDark');
}
setTheme(localStorage.getItem('role-preview-theme') === 'dark' ? 'dark' : 'light');
themeButton.addEventListener('click', () => {
    const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    localStorage.setItem('role-preview-theme', theme);
    setTheme(theme);
});
// ============================================================================
// 07. Автообновление локального макета
// Этот блок нужен только стенду и не участвует в оформлении страницы.
// ============================================================================
// Сервер стенда сообщает об изменении файлов; форум при этом не перезагружается.
const events = new EventSource('/events');
events.addEventListener('role', () => location.reload());

