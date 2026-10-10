import { guides, characters } from './content.js';

// Берём интерфейсные иконки из единого реестра расширения, включая Free-замены.
// Элементы создаются через textContent: названия анкет не становятся HTML.
const [icons, localIcons] = await Promise.all([
  fetch('/role/icons.json').then(response => response.json()),
  fetch('/role/icons.local.json').then(response => response.json()),
]);
function paintIcon(element, key) {
  const requested = icons[key] || icons.fallback;
  const replacement = localIcons.enabled && localIcons.replacements[requested];
  element.className = 'RoleIcon ' + (replacement || requested);
}
document.querySelectorAll('[data-icon]').forEach(element => paintIcon(element, element.dataset.icon));
function node(tag, className, text) {
  const element = document.createElement(tag);
  if (className) element.className = className;
  if (text) element.textContent = text;
  return element;
}
function icon(key) {
  const element = node('i');
  element.setAttribute('aria-hidden', 'true');
  paintIcon(element, key);
  return element;
}

// Плитка — вся кнопка вкладки. Содержание живёт здесь же, без перехода на форум.
// До первого выбора панель скрыта, чтобы сохранить компактную главную страницу.
const guideGrid = document.getElementById('guide-cards');
const guidePanels = document.getElementById('guide-panels');
const guideTabs = [];
const panels = [];
function selectGuide(index, focus = false) {
  guideTabs.forEach((tab, current) => {
    const selected = current === index;
    tab.setAttribute('aria-selected', String(selected));
    tab.tabIndex = selected ? 0 : -1;
    panels[current].hidden = !selected;
  });
  if (focus) guideTabs[index].focus();
}
for (const [index, guide] of guides.entries()) {
  const card = node('button', 'RoleGuide RoleGuide--' + guide.tone);
  card.type = 'button';
  card.id = 'guide-tab-' + guide.tone;
  card.setAttribute('role', 'tab');
  card.setAttribute('aria-selected', 'false');
  card.setAttribute('aria-controls', 'guide-panel-' + guide.tone);
  card.tabIndex = index === 0 ? 0 : -1;
  const emblem = node('span', 'RoleGuide-emblem');
  emblem.append(icon(guide.icon));
  card.append(emblem, node('span', 'RoleGuide-title', guide.title), node('span', 'RoleGuide-description', guide.description));

  const panel = node('section', 'RoleGuidePanel');
  panel.id = 'guide-panel-' + guide.tone;
  panel.setAttribute('role', 'tabpanel');
  panel.setAttribute('aria-labelledby', card.id);
  panel.tabIndex = 0;
  panel.hidden = true;
  panel.append(node('h2', '', guide.title), node('p', 'RoleGuidePanel-intro', guide.intro));
  const chapters = node('div', 'RoleGuidePanel-chapters');
  for (const chapter of guide.chapters) {
    const section = node('section');
    section.append(node('h3', '', chapter.title), node('p', '', chapter.text));
    chapters.append(section);
  }
  panel.append(chapters);
  card.addEventListener('click', () => selectGuide(index));
  // Стрелки, Home и End переключают вкладки и сохраняют фокус на выбранной.
  card.addEventListener('keydown', event => {
    const targets = { ArrowRight: (index + 1) % guides.length, ArrowLeft: (index + guides.length - 1) % guides.length, Home: 0, End: guides.length - 1 };
    if (!(event.key in targets)) return;
    event.preventDefault();
    selectGuide(targets[event.key], true);
  });
  guideTabs.push(card);
  panels.push(panel);
  guideGrid.append(card);
  guidePanels.append(panel);
}

const dialog = document.getElementById('role-dialog');
function showDialog({ kicker, title, description, actions = [] }) {
  document.getElementById('dialog-kicker').textContent = kicker;
  document.getElementById('dialog-title').textContent = title;
  document.getElementById('dialog-description').textContent = description;
  const buttons = document.getElementById('dialog-actions');
  buttons.replaceChildren();
  for (const action of actions) {
    const link = node('a', 'RoleButton RoleButton--primary', action.label);
    link.href = action.href;
    buttons.append(link);
  }
  dialog.showModal();
}
document.getElementById('close-dialog').addEventListener('click', () => dialog.close());
dialog.addEventListener('click', event => {
  if (event.target !== dialog) return;
  const bounds = dialog.getBoundingClientRect();
  if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
});
document.getElementById('start-character').addEventListener('click', () => showDialog({
  kicker: 'Создание персонажа', title: 'Здесь откроется мастер',
  description: 'На форуме кнопка откроет существующее окошко создания персонажа. Сейчас можно посмотреть отдельный стенд мастера: он не создаёт настоящие анкеты.',
  actions: [{ label: 'Посмотреть мастер', href: '/' }],
}));

const rail = document.getElementById('character-rail');
for (const character of characters) {
  const card = node('article', 'RoleCharacter');
  const portrait = node('div', 'RoleCharacter-portrait');
  portrait.style.setProperty('--portrait-position', (character.portrait * 100 / 3) + '%');
  const tag = node('span', 'RoleCharacter-tag', character.vocation);
  const body = node('div', 'RoleCharacter-body');
  body.append(node('span', 'RoleCharacter-race', character.race), node('h3', '', character.name), node('p', '', character.description));
  const link = node(character.demo ? 'button' : 'a', 'RoleCharacter-link', 'Читать анкету');
  link.append(icon('landing.arrow'));
  if (character.demo) {
    link.type = 'button';
    link.addEventListener('click', () => showDialog({ kicker: 'Демонстрационная анкета', title: character.name, description: character.hook + ' Это вымышленный персонаж для проверки оформления. После выбора настоящих анкет здесь будет переход в тему игрока.' }));
  } else {
    link.href = character.link;
  }
  portrait.append(tag);
  body.append(link);
  card.append(portrait, body);
  rail.append(card);
}

// Нативная прокрутка работает свайпом и клавиатурой. Стрелки двигают на карточку.
// Нет автопрокрутки: текст остаётся на месте, пока его читают.
const previous = document.getElementById('previous-characters');
const next = document.getElementById('next-characters');
const counter = document.getElementById('character-counter');
function updateSlider() {
  const first = rail.firstElementChild;
  const step = first.getBoundingClientRect().width + parseFloat(getComputedStyle(rail).gap);
  const index = Math.round(rail.scrollLeft / step);
  const visible = Math.max(1, Math.round(rail.clientWidth / step));
  const end = Math.min(characters.length, index + visible);
  counter.textContent = (visible > 1 ? (index + 1) + '–' + end : String(index + 1)) + ' / ' + characters.length;
  previous.disabled = rail.scrollLeft < 2;
  next.disabled = rail.scrollLeft + rail.clientWidth >= rail.scrollWidth - 2;
}
function moveSlider(direction) {
  const first = rail.firstElementChild;
  const step = first.getBoundingClientRect().width + parseFloat(getComputedStyle(rail).gap);
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;
  rail.scrollBy({ left: direction * step, behavior: reducedMotion ? 'instant' : 'smooth' });
}
previous.addEventListener('click', () => moveSlider(-1));
next.addEventListener('click', () => moveSlider(1));
rail.addEventListener('scroll', updateSlider, { passive: true });
rail.addEventListener('keydown', event => {
  if (event.target !== rail || !['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
  event.preventDefault();
  moveSlider(event.key === 'ArrowLeft' ? -1 : 1);
});
new ResizeObserver(updateSlider).observe(rail);
updateSlider();

const themeButton = document.getElementById('theme-toggle');
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

// Сервер стенда сообщает об изменении файлов; форум при этом не перезагружается.
const events = new EventSource('/events');
events.addEventListener('role', () => location.reload());
