// Никакого приложения Flarum внутри iframe: только тема, типографика и высота документа.
const updateRoot = document.querySelector('.RpUpdate');
const updateMode = document.documentElement.dataset.themeMode;
const updateMedia = matchMedia('(prefers-color-scheme: dark)');
const updateRoadmap = updateRoot.querySelector('.Timeline--roadmap');
let updateRouteFrame = 0;

function scheduleUpdateRoute() {
    if (!updateRoadmap || updateRouteFrame) return;
    updateRouteFrame = requestAnimationFrame(() => {
        updateRouteFrame = 0;
        drawUpdateRoute();
    });
}

function updateTheme() {
    let dark = updateMedia.matches;
    if (updateMode === 'auto') {
        try {
            // Та же тема, что у темы форума. При отдельном открытии — настройка устройства.
            if (window.parent !== window) {
                const parentHtml = window.parent.document.documentElement;
                dark = parentHtml.dataset.theme?.startsWith('dark')
                    || window.parent.document.body.classList.contains('dark')
                    || getComputedStyle(parentHtml).colorScheme.startsWith('dark');
            }
        } catch { /* При другом origin остаётся тема устройства. */ }
    } else dark = updateMode === 'dark';
    updateRoot.dataset.theme = dark ? 'dark' : 'light';
    document.documentElement.dataset.theme = updateRoot.dataset.theme;
    scheduleUpdateRoute();
}
updateTheme();
updateMedia.addEventListener('change', updateTheme);
try {
    if (window.parent !== window) {
        const observer = new MutationObserver(updateTheme);
        observer.observe(window.parent.document.documentElement, {attributes: true, attributeFilter: ['class', 'style', 'data-theme']});
        observer.observe(window.parent.document.body, {attributes: true, attributeFilter: ['class']});
    }
} catch { /* Вложенный документ работает и без доступа к родительскому DOM. */ }

applyGuideTypography(updateRoot);
updateRoot.querySelectorAll('.Badge').forEach((badge, index) => {
    badge.style.setProperty('--badge-rotation', `${((index + 1) * 137.508) % 360}deg`);
});

// На своём форуме растягиваем только собственный iframe; вложенная прокрутка не нужна.
// В sandbox без same-origin остаётся обычная прокрутка внутри заданной высоты iframe.
function updateFrameHeight() {
    try {
        if (window.frameElement) {
            const height = Math.ceil(updateRoot.getBoundingClientRect().height);
            if (window.frameElement.style.height !== height + 'px') window.frameElement.style.height = height + 'px';
        }
    } catch { /* Ограничения sandbox не мешают чтению страницы. */ }
}
new ResizeObserver(updateFrameHeight).observe(updateRoot);
updateFrameHeight();

// Фоновая дорожка не меняет семантику списка. Малое смещение кружков позволяет
// провести плавную кривую рядом с текстом, без углов и отдельных прямых участков.
// При правке текста, дат, ширины или темы пересчитываются только фоновые линии.
function drawUpdateRoute() {
    const bounds = updateRoadmap.getBoundingClientRect();
    if (!bounds.width || !bounds.height) return;
    const points = [...updateRoadmap.querySelectorAll(':scope > li')].map(item => {
        const circle = item.querySelector('.Badge').getBoundingClientRect();
        return {
            x: circle.left + circle.width / 2 - bounds.left,
            y: circle.top + circle.height / 2 - bounds.top,
        };
    });
    if (points.length < 2) { updateRoadmap.style.backgroundImage = 'none'; return; }
    let path = `M ${points[0].x} ${points[0].y}`;
    for (let index = 1; index < points.length; index++) {
        const previous = points[index - 1], next = points[index];
        const bend = (next.y - previous.y) * .45;
        path += ` C ${previous.x} ${previous.y + bend} ${next.x} ${next.y - bend} ${next.x} ${next.y}`;
    }
    const color = getComputedStyle(updateRoadmap).getPropertyValue('--role-accent').trim();
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${bounds.width} ${bounds.height}" preserveAspectRatio="none"><path d="${path}" fill="none" stroke="${color}" stroke-opacity=".55" stroke-width="2" stroke-dasharray="4 7" stroke-linecap="round"/></svg>`;
    updateRoadmap.style.backgroundImage = `url("data:image/svg+xml,${encodeURIComponent(svg)}")`;
}
if (updateRoadmap) {
    const routeObserver = new ResizeObserver(scheduleUpdateRoute);
    routeObserver.observe(updateRoadmap);
    updateRoadmap.querySelectorAll(':scope > li').forEach(item => routeObserver.observe(item));
    document.fonts.ready.then(scheduleUpdateRoute);
    scheduleUpdateRoute();
}
