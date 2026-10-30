/** Обычный неразрывный пробел U+00A0 связывает служебное слово со следующим.
 * Работаем с текстовыми узлами: ссылки, выделения и исходные данные не меняются.
 * Соседние inline-элементы считаются одним текстом, разные абзацы — разными.
 */
export function applyGuideTypography(root) {
    const ignored = 'script,style,svg,math,pre,code,textarea,input,select,[contenteditable],[data-no-typography]';
    const blocks = 'p,h1,h2,h3,h4,h5,h6,li,dt,dd,blockquote,figcaption,button,th,td,div,section,aside,summary,header,footer,nav';
    const prepositions = 'из-за|из-под|без|безо|близ|в|во|вдоль|вместо|вне|внутри|возле|вокруг|вопреки|для|до|за|из|изо|к|ко|кроме|между|мимо|на|над|надо|о|об|обо|около|от|ото|перед|передо|по|под|подо|после|при|про|против|ради|с|со|сверх|сквозь|среди|у|через|благодаря|согласно|навстречу|насчёт|посредством';
    const conjunctionsAndParticles = 'и|а|но|да|или|либо|ни|не|бы|же|ли|то|как|чтобы|чтоб|если|ведь|даже|лишь|пусть|вот';
    // Lookbehind не съедает границу: цепочка «и в городе» связывается целиком.
    const pattern = new RegExp('(?<![\\p{L}\\p{N}_/\\\\-])(' + prepositions + '|' + conjunctionsAndParticles + ')([ \\t\\r\\n\\u00a0]+)(?=[\\p{L}\\p{N}«„“"(])', 'giu');
    let run = [], block = null;

    function formatRun() {
        const text = run.map(node => node.data).join('');
        const spaces = [...text.matchAll(pattern)].map(match => {
            const start = match.index + match[1].length;
            return {start, end: start + match[2].length};
        });
        let offset = 0;
        for (const node of run) {
            const end = offset + node.data.length;
            let cursor = 0, result = '';
            for (const space of spaces) {
                if (space.end <= offset || space.start >= end) continue;
                const start = Math.max(space.start - offset, 0);
                result += node.data.slice(cursor, start);
                if (space.start >= offset) result += '\u00a0';
                cursor = Math.min(space.end - offset, node.data.length);
            }
            result += node.data.slice(cursor);
            if (result !== node.data) node.data = result;
            offset = end;
        }
        run = [];
    }

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
        const node = walker.currentNode;
        if (node.parentElement.closest(ignored)) {formatRun(); block = null; continue;}
        // Оформление каталожного текста меняется только на странице правил.
        node.data = node.data.replace(/\u202f/g, '\u00a0').replace(/;/g, ',')
            .replace(/врожденн/giu, word => word.replace(/[еЕ]/u, letter => letter === 'Е' ? 'Ё' : 'ё'));
        const nextBlock = node.parentElement.closest(blocks) || root;
        if (nextBlock !== block) formatRun();
        block = nextBlock;
        run.push(node);
    }
    formatRun();
}

