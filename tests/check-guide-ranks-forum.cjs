const {normalizeGuideText} = require('./guide-test-text.cjs');
/** Ранги правил используют реальные определения. Изменённый ответ — только в браузере. */
const assert = require('node:assert/strict');
const {chromium} = require(process.env.ROLEPLAY_PLAYWRIGHT ||
  'C:/Users/KusoT/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const origin = process.env.ROLEPLAY_FORUM_URL || 'http://localhost:8081';
const numerals = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];
(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.ROLEPLAY_BROWSER ||
    'C:/Program Files/Yandex/YandexBrowser/Application/browser.exe'});
  try {
    const page = await browser.newPage({viewport: {width: 1440, height: 1100}, reducedMotion: 'reduce'});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const response = page.waitForResponse(response => response.url().endsWith('/api/roleplay/guide'));
    await page.goto(origin + '/role#world-organizations', {waitUntil: 'networkidle'});
    const {data} = await (await response).json();
    const table = page.locator('[data-guild-ranks]');
    await table.locator('tr').first().waitFor();
    const rows = () => table.locator('tr').evaluateAll(rows => rows.map(row => [...row.cells].map(cell => cell.textContent)));
    assert.deepEqual(await rows(), data.ranks.map(rank => [numerals[rank.level], rank.letter, rank.name]));
    assert.deepEqual(data.ranks.map(rank => rank.level), data.ranks.map(rank => rank.level).sort((a, b) => a - b));
    assert.equal(await table.locator('bdi[dir="rtl"]').count(), data.ranks.length);
    assert.equal(await table.evaluate(node => node.closest('.Note').classList.contains('Note--compact')), true);
    await page.setViewportSize({width: 390, height: 844});
    await table.scrollIntoViewIfNeeded();
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    const changed = structuredClone(data);
    changed.ranks = [{slug: 'changed-rank', level: 4, letter: 'ת', name: '<b>Новое название</b>', description: 'Описание из базы.'}];
    await page.route('**/api/roleplay/guide', route => route.fulfill({json: {data: changed}}));
    await page.reload({waitUntil: 'networkidle'});
    await table.locator('bdi').waitFor();
    assert.deepEqual(await rows(), [['IV', 'ת', '<b>Новое название</b>']]);
    assert.equal(await table.locator('td b').count(), 0);
    assert.equal(await table.locator('tr').getAttribute('title'), 'Описание из базы');
    changed.ranks = [];
    await page.reload({waitUntil: 'networkidle'});
    await table.locator('td[colspan="3"]').waitFor();
    assert.equal(await table.innerText().then(normalizeGuideText), 'В справочнике пока нет доступных рангов');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({realRanks: data.ranks.length, liveFields: true, variableCount: true, emptyList: true, mobileOverflow: false, errors}));
  } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
