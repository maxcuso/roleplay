import {applyGuideTypography} from './typography.js';
export {applyGuideTypography} from './typography.js';

/**
 * Поведение /role, общее для форума и локального макета.
 * Все поиски ограничены root. При уходе со страницы обработчики и наблюдатели снимаются.
 * Flarum передаёт реестр значков, характеристики из базы и действие создания персонажа.
 */
export function mountGuide(root, {
    icons, localIcons = { enabled: false, replacements: {} },
    guides, attributes = [], characters = [], createCharacter,
    storageKey = 'role-guide-tab',
}) {
    let disposed = false;
    const events = new AbortController();
    const cleanups = [];
    const findId = id => id ? root.querySelector('#' + CSS.escape(id)) : null;
    const listen = (element, type, handler, options = {}) => {
        element.addEventListener(type, handler, typeof options === 'boolean'
            ? { capture: options, signal: events.signal }
            : { ...options, signal: events.signal });
    };

    function paintIcon(element, key) {
        const requested = icons[key] || icons.fallback;
        const replacement = localIcons.enabled && localIcons.replacements[requested];
        element.className = 'Icon ' + (replacement || requested);
    }
    root.querySelectorAll('[data-icon]').forEach(element => paintIcon(element, element.dataset.icon));

    // Разные повороты текстуры Badge. Углы стабильны при перезагрузке страницы.
    // Свой угол можно задать прямо в HTML: style="--badge-rotation: 45deg".
    root.querySelectorAll('.Badge:not(.Badge--small)').forEach((badge, index) => {
        if (!badge.style.getPropertyValue('--badge-rotation')) {
            const angle = ((index + 1) * 137.508) % 360;
            badge.style.setProperty('--badge-rotation', angle.toFixed(2) + 'deg');
        }
    });

    function node(tag, className, text) {
        const element = document.createElement(tag);
        if (className)
            element.className = className;
        if (text)
            element.textContent = text;
        return element;
    }

    function icon(key) {
        const element = node('i');
        element.setAttribute('aria-hidden', 'true');
        paintIcon(element, key);
        return element;
    }
    // ============================================================================
    // 02. Общие вкладки: главные разделы, характеристики и организации
    // HTML: .Tabs[data-tabs] → button[aria-controls] → .TabPanel.
    // Идентификаторы нужны только для доступной связи кнопок и панелей.
    // ============================================================================
    const part = name => root.querySelector('[data-part="' + name + '"]');
    const action = name => root.querySelector('[data-action="' + name + '"]');
    const guideTabs = root.querySelector('[data-tabs="guides"]');
    const guideColors = { rules: 'violet', lore: 'teal', application: 'blue' };
    let contentsFrame = 0;

    // Кнопки главных разделов берут подписи из content.js; содержание уже в HTML.
    for (const guide of guides) {
        const button = node('button');
        button.type = 'button';
        button.id = 'tab-' + guide.tone;
        button.setAttribute('role', 'tab');
        button.setAttribute('aria-selected', 'false');
        button.setAttribute('aria-controls', 'panel-' + guide.tone);
        button.tabIndex = -1;
        button.style.setProperty('--role-accent', 'var(--role-' + guideColors[guide.tone] + ')');
        button.append(icon(guide.icon), node('span', '', guide.title));
        guideTabs.append(button);
    }
    guideTabs.firstElementChild.tabIndex = 0;

    function selectTab(tab, focus = false) {
        const group = tab.closest('.Tabs');
        for (const button of group.querySelectorAll(':scope > button')) {
            const selected = button === tab;
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;
            findId(button.getAttribute('aria-controls')).hidden = !selected;
        }
        if (focus) tab.focus({ preventScroll: group === guideTabs });
        if (group === guideTabs) {
            sessionStorage.setItem(storageKey, String([...group.children].indexOf(tab)));
        }
        scheduleContents();
    }

    // Абсолютный адрес сохраняет /role даже при наличии <base> у форума.
    // Состояние истории принадлежит роутеру Flarum: не затираем его.
    function replaceAnchor(hash) {
        const url = new URL(location.href);
        url.hash = hash;
        history.replaceState(history.state, '', url.href);
    }

    function activateTab(tab, focus = false) {
        selectTab(tab, focus);
        if (tab.closest('.Tabs') !== guideTabs) return;
        replaceAnchor('#guides');
        // Прокручиваем к обычному контейнеру: закреплённая панель вкладок
        // уже находится у края экрана даже при чтении середины раздела.
        findId('guides').scrollIntoView({
            block: 'start',
            behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth',
        });
    }

    root.querySelectorAll('.Tabs[data-tabs]').forEach(group => {
        const buttons = [...group.querySelectorAll(':scope > button')];
        buttons.forEach((button, index) => {
            listen(button, 'click', () => {
                activateTab(button);
            });
            listen(button, 'keydown', event => {
                const targets = {
                    ArrowRight: (index + 1) % buttons.length,
                    ArrowLeft: (index + buttons.length - 1) % buttons.length,
                    Home: 0,
                    End: buttons.length - 1,
                };
                if (!(event.key in targets)) return;
                event.preventDefault();
                activateTab(buttons[targets[event.key]], true);
            });
        });
    });

    // Баннеры-спойлеры: втянуть язычок, сменить сторону и выдвинуть обратно.
    // Без JavaScript и при уменьшенной анимации остаётся обычное поведение details.
    const reducedBannerMotion = matchMedia('(prefers-reduced-motion: reduce)');
    root.querySelectorAll('details.Banner--spoiler').forEach(spoiler => {
        const summary = spoiler.querySelector(':scope > summary');
        let moving = false;
        listen(summary, 'click', async event => {
            if (reducedBannerMotion.matches) return;
            event.preventDefault();
            if (moving) return;
            moving = true;
            const wasOpen = spoiler.open;
            try {
                spoiler.dataset.tabMotion = 'hide';
                const duration = parseFloat(getComputedStyle(spoiler).getPropertyValue('--banner-tab-duration'));
                await new Promise(resolve => setTimeout(resolve, duration));
                // Переход по якорю мог уже раскрыть блок во время анимации.
                if (disposed || spoiler.open !== wasOpen) return;
                spoiler.dataset.tabMotion = 'switch';
                spoiler.open = !wasOpen;
                // Два кадра фиксируют скрытое положение на новой стороне баннера.
                await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            } finally {
                delete spoiler.dataset.tabMotion;
                moving = false;
            }
        });
    });

    // Оглавление использует обычные ссылки. Панели и спойлеры раскрываются по якорю.
    // Подсветка следует за чтением, не изменяя адрес и историю при прокрутке.
    function updateContents() {
        contentsFrame = 0;
        const panel = root.querySelector('.TabPanel[data-document]:not([hidden])');
        const links = [...(panel?.querySelectorAll(':scope > .Contents a') || [])];
        const visible = links.map(link => ({ link, target: findId(link.hash.slice(1)) }))
            .filter(item => item.target?.getClientRects().length);
        // Переход учитывает и отступ заголовка, и scroll-padding страницы.
        const targetMargin = visible[0] ? parseFloat(getComputedStyle(visible[0].target).scrollMarginTop) || 0 : 0;
        const pagePadding = parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop) || 0;
        const readingLine = targetMargin + pagePadding + 8;
        let current = visible[0]?.link;
        for (const item of visible) {
            // У баннера ориентир — край всей плашки: заголовок находится ниже
            // из-за внутренних отступов и вертикального выравнивания.
            const start = item.target.closest('summary.Banner') || item.target;
            if (start.getBoundingClientRect().top <= readingLine) current = item.link;
        }
        if (visible.length && scrollY + innerHeight >= document.documentElement.scrollHeight - 2) current = visible.at(-1).link;
        root.querySelectorAll('.Contents a[aria-current]').forEach(link => link.removeAttribute('aria-current'));
        current?.setAttribute('aria-current', 'location');
    }

    function scheduleContents() {
        if (!disposed && !contentsFrame) contentsFrame = requestAnimationFrame(updateContents);
    }

    listen(window, 'scroll', scheduleContents, { passive: true });
    listen(window, 'resize', scheduleContents);
    listen(root, 'toggle', scheduleContents, true);
    document.fonts.ready.then(scheduleContents);

    function restoreTabs() {
        const target = findId(location.hash.slice(1));
        const documentPanel = target?.closest('.TabPanel[data-document]');
        const saved = Number(sessionStorage.getItem(storageKey));
        const mainButton = documentPanel
            ? [...guideTabs.children].find(button => button.getAttribute('aria-controls') === documentPanel.id)
            : sessionStorage.getItem(storageKey) !== null ? guideTabs.children[saved] || guideTabs.firstElementChild : guideTabs.firstElementChild;
        if (mainButton) selectTab(mainButton);
        let panel = target?.closest('.TabPanel');
        while (panel) {
            const button = root.querySelector('.Tabs > button[aria-controls="' + panel.id + '"]');
            if (button) selectTab(button);
            panel = panel.parentElement.closest('.TabPanel');
        }
        let spoiler = target?.closest('details.Banner--spoiler');
        while (spoiler) {
            spoiler.open = true;
            spoiler = spoiler.parentElement.closest('details.Banner--spoiler');
        }
        scheduleContents();
        // После раскрытия восстанавливаем положение якоря в новом макете страницы.
        if (target) requestAnimationFrame(() => {
        if (!disposed) target.scrollIntoView({ block: 'start' });
    });
    }
    listen(window, 'hashchange', restoreTabs);
    // Перехватываем локальные якоря до обработчиков ссылок форума.
    // Первый и повторный клик одинаково раскрывают раздел и прокручивают к нему,
    // не запуская навигацию Flarum или загрузку нового документа.
    listen(root, 'click', event => {
        if (event.defaultPrevented || event.button !== 0
            || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target instanceof Element ? event.target.closest('a[href^="#"]') : null;
        if (!link || !root.contains(link) || link.hasAttribute('download')
            || (link.target && link.target !== '_self')) return;
        const hash = link.getAttribute('href');
        if (!findId(hash.slice(1))) return;
        event.preventDefault();
        event.stopPropagation();
        replaceAnchor(hash);
        restoreTabs();
    }, true);
    restoreTabs();

    // Иконки характеристик: единые данные для сетки, кнопок и заголовков.
    // На форуме передайте catalog.definitions.attributes вместо данных макета.
    function paintAttributeIcons(definitions) {
        root.querySelectorAll('[data-attribute-icon]').forEach(element => {
            const definition = definitions.find(row => row.slug === element.dataset.attributeIcon);
            const requested = definition?.icon || icons.fallback;
            const replacement = localIcons.enabled && localIcons.replacements[requested];
            element.className = 'Icon ' + (replacement || requested);
        });
    }
    paintAttributeIcons(attributes);
    // ============================================================================
    // 03. Демонстрационное окошко и кнопка создания
    // ============================================================================
    const dialog = root.querySelector('.Dialog');

    function showDialog({ kicker, title, description, actions = [] }) {
        part('dialog-kicker').textContent = kicker;
        findId('dialog-title').textContent = title;
        part('dialog-description').textContent = description;
        const buttons = part('dialog-actions');
        buttons.replaceChildren();
        for (const action of actions) {
            const link = node('a', 'Button Button--primary', action.label);
            link.href = action.href;
            buttons.append(link);
        }
        dialog.showModal();
        applyGuideTypography(dialog);
    }
    listen(action('close-dialog'), 'click', () => dialog.close());
    listen(dialog, 'click', event => {
        if (event.target !== dialog)
            return;
        const bounds = dialog.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)
            dialog.close();
    });
    // Кнопка есть и в главном баннере, и в первом шаге руководства.
    root.querySelectorAll('[data-action="create-character"]').forEach(button => {
        listen(button, 'click', createCharacter || (() => showDialog({
            kicker: 'Создание персонажа', title: 'Здесь откроется мастер',
            description: 'На форуме кнопка открывает мастер создания персонажа. На стенде можно посмотреть его оформление.',
            actions: [{ label: 'Посмотреть мастер', href: '/' }],
        })));
    });

    // Заглушка карты. Когда будет готов адрес, кнопку в HTML можно заменить ссылкой.
    listen(action('world-map'), 'click', () => showDialog({
        kicker: 'Новый Мир', title: 'Карта Тальмиара',
        description: 'Интерактивная карта в разработке. Здесь появятся города, дороги и места приключений.',
    }));
    // ============================================================================
    // 04. Карточки персонажей
    // Чтобы добавить персонажа, измените массив characters в content.js.
    // ============================================================================
    const rail = part('character-rail');
    if (rail) {
        for (const character of characters) {
            const card = node('article', 'Character');
            const portrait = node('div', 'Character-portrait');
            portrait.style.setProperty('--portrait-position', (character.portrait * 100 / 3) + '%');
            const tag = node('span', 'Character-tag', character.vocation);
            const body = node('div', 'Character-body');
            body.append(node('span', 'Character-race', character.race), node('h3', '', character.name), node('p', '', character.description));
            const link = node(character.demo ? 'button' : 'a', 'Character-link', 'Читать анкету');
            link.append(icon('landing.arrow'));
            if (character.demo) {
                link.type = 'button';
                listen(link, 'click', () => showDialog({
                    kicker: 'Демонстрационная анкета',
                    title: character.name,
                    description: character.hook + ' Это вымышленный персонаж для проверки оформления. После выбора настоящих анкет здесь будет переход в тему игрока.'
                }));
            }
            else {
                link.href = character.link;
            }
            portrait.append(tag);
            body.append(link);
            card.append(portrait, body);
            rail.append(card);
        }
        // ============================================================================
        // 05. Прокрутка галереи
        // ============================================================================
        // Нативная прокрутка работает свайпом и клавиатурой. Стрелки двигают на карточку.
        // Нет автопрокрутки: текст остаётся на месте, пока его читают.
        const previous = action('previous-characters');
        const next = action('next-characters');
        const counter = part('character-counter');

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
        listen(previous, 'click', () => moveSlider(-1));
        listen(next, 'click', () => moveSlider(1));
        listen(rail, 'scroll', updateSlider, { passive: true });
        listen(rail, 'keydown', event => {
            if (event.target !== rail || !['ArrowLeft', 'ArrowRight'].includes(event.key))
                return;
            event.preventDefault();
            moveSlider(event.key === 'ArrowLeft' ? -1 : 1);
        });
        const observer = new ResizeObserver(updateSlider);
        observer.observe(rail);
        cleanups.push(() => observer.disconnect());
        updateSlider();
    }

    // Карточки справочников к этому моменту уже вставлены; оформление едино для всего текста.
    applyGuideTypography(root);

    return () => {
        disposed = true;
        events.abort();
        cancelAnimationFrame(contentsFrame);
        cleanups.forEach(cleanup => cleanup());
        if (dialog.open) dialog.close();
    };
}
