const {normalizeGuideText} = require('./guide-test-text.cjs');
/** Реальные расы и произвольное число новых записей. Подмена API не изменяет базу. */
const assert = require('node:assert/strict');
const {chromium} = require(process.env.ROLEPLAY_PLAYWRIGHT ||
  'C:/Users/KusoT/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const origin = process.env.ROLEPLAY_FORUM_URL || 'http://localhost:8081';
(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.ROLEPLAY_BROWSER ||
    'C:/Program Files/Yandex/YandexBrowser/Application/browser.exe'});
  try {
    const page = await browser.newPage({viewport: {width: 1440, height: 1100}, reducedMotion: 'reduce'});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const response = page.waitForResponse(response => response.url().endsWith('/api/roleplay/guide'));
    await page.goto(origin + '/role#world-races', {waitUntil: 'networkidle'});
    const {data} = await (await response).json();
    await page.locator('[data-race]').first().waitFor();
    const raceBlock = slug => page.locator('[data-race="' + slug + '"]');
    assert.deepEqual(await page.locator('[data-race]').evaluateAll(nodes => nodes.map(node => node.dataset.race)), data.races.map(race => race.slug));
    for (const race of data.races) {
      const block = raceBlock(race.slug);
      assert.equal(await block.locator('h4').textContent().then(normalizeGuideText), race.name.toLocaleUpperCase('ru-RU'));
      assert.equal(await block.locator('em').textContent().then(normalizeGuideText), race.quote);
      assert.deepEqual(await block.locator('.Step--race > p').allTextContents().then(normalizeGuideText), (race.description ?? '').replace(/\.$/, '').split(/\r?\n\s*\r?\n/).filter(Boolean));
      assert.equal(await block.locator('[data-race-field="height"] dd').innerText().then(normalizeGuideText), (race.average_height || '—').replace(/\.$/, ''));
      assert.equal(await block.locator('[data-race-field="traits"] dd').innerText().then(normalizeGuideText), (race.distinctive_traits || '—').replace(/\.$/, ''));
      assert.equal(await block.evaluate(node => node.style.getPropertyValue('--role-accent')), race.background_color);
      await block.locator('.Step--race').scrollIntoViewIfNeeded();
      const image = block.locator('.Figure--cutout img');
      await image.evaluate(img => img.decode());
      assert.equal(await image.getAttribute('src'), race.image);
      assert.equal(await block.locator('.RpCard').count(), race.fixed_perks.length + race.choice_perks.length);
    }
    const human = raceBlock('human');
    await human.locator('.Step--race').scrollIntoViewIfNeeded();
    const portrait = await human.locator('.Figure--cutout').evaluate(node => ({border: getComputedStyle(node).borderWidth, shadow: getComputedStyle(node).boxShadow}));
    assert.equal(portrait.border, '4px');
    assert.notEqual(portrait.shadow, 'none');
    await page.screenshot({path: 'packages/roleplay-new/tests/.runtime/guide-races-desktop.png'});
    await page.setViewportSize({width: 390, height: 844});
    await human.locator('.Step--race').scrollIntoViewIfNeeded();
    await page.evaluate(() => new Promise(requestAnimationFrame));
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    assert.equal(await human.locator('.Step--race').evaluate(node => {
      const quote = node.querySelector('em').getBoundingClientRect();
      const image = node.querySelector('figure').getBoundingClientRect();
      return quote.top >= image.bottom;
    }), true, 'Mobile portrait does not cover the quote');
    await page.screenshot({path: 'packages/roleplay-new/tests/.runtime/guide-races-mobile.png'});

    const changed = structuredClone(data);
    changed.races = changed.races.filter(race => race.slug !== 'human');
    for (let index = 0; index < 3; index++) changed.races.push({...structuredClone(data.races[0]),
      slug: 'test-race-' + index, name: 'Новая раса ' + index, quote: '<b>Цитата</b>',
      description: 'Первый абзац.\n\nВторой абзац.', image: index ? data.races[0].image : null,
      min_age: null, max_age: null, average_height: null, distinctive_traits: null, background_color: '#445566',
      attribute_bonuses: {fixed_bonuses: {magic: 2}, attribute_count: 1, attribute_bonus: 3, choice_attributes: ['physiology', 'agility']},
      fixed_perks: [], choice_perks: [], perk_choice_count: 0});
    await page.route('**/api/roleplay/guide', route => route.fulfill({json: {data: changed}}));
    await page.reload({waitUntil: 'networkidle'});
    await raceBlock('test-race-0').waitFor();
    assert.equal(await page.locator('[data-race]').count(), data.races.length + 2);
    assert.equal(await raceBlock('human').count(), 0);
    const fresh = raceBlock('test-race-0');
    assert.equal(await fresh.locator('em b').count(), 0);
    assert.equal(await fresh.locator('em').textContent().then(normalizeGuideText), '<b>Цитата</b>');
    assert.equal(await fresh.locator('.Figure--cutout img').count(), 0);
    assert.equal(await fresh.locator('.Figure--cutout .Icon').count(), 1);
    assert.equal(await fresh.locator('[data-race-field="lifespan"] dd').innerText().then(normalizeGuideText), '—');
    assert.equal(await fresh.locator('[data-race-field="bonuses"] .paper-highlight').count(), 2);
    assert.match(await fresh.locator('[data-race-field="bonuses"]').textContent().then(normalizeGuideText), /\+2 к Магии/);
    assert.match(await fresh.locator('[data-race-field="bonuses"]').textContent().then(normalizeGuideText), /\+3 к одной на выбор: Физиология, Ловкость/);
    assert.equal(await fresh.locator('[role="status"]').textContent().then(normalizeGuideText), 'У этой расы нет доступных расовых перков');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    changed.races = [];
    await page.reload({waitUntil: 'networkidle'});
    await page.locator('[data-races] > [role="status"]').waitFor();
    assert.equal(await page.locator('[data-race]').count(), 0);
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({realRaces: data.races.length, newRaces: 3, missingImage: true, emptyList: true, portraitFrame: true, liveBonuses: true, mobileOverflow: false, errors}));
  } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
