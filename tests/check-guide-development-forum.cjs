const {normalizeGuideText} = require('./guide-test-text.cjs');
/** Реальная страница + изменённый ответ API только в браузере. Базу не меняет. */
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
    await page.goto(origin + '/role#rules-development', {waitUntil: 'networkidle'});
    const {data} = await (await response).json();
    const slot = slug => page.locator('[data-attribute-development="' + slug + '"]');
    await slot('physiology').locator('li .paper-highlight').first().waitFor();
    for (const level of [2, 3]) {
      assert.equal(await page.locator('[data-skill-minimum="' + level + '"]').textContent().then(normalizeGuideText), String(data.advancement.skill_minimums[level]));
    }
    const costs = page.locator('[data-experience-costs]');
    const expectedCosts = data.advancement.experience_costs.map((range, index, ranges) => {
      const last = Math.min(data.advancement.maximum_level, (ranges[index + 1]?.minimum ?? data.advancement.maximum_level + 1) - 1);
      return range.minimum <= last ? [range.minimum === last ? String(last) : range.minimum + '–' + last, range.cost.toLocaleString('ru-RU')] : null;
    }).filter(Boolean);
    assert.deepEqual(await costs.locator('tr').evaluateAll(rows => rows.map(row => [...row.cells].map(cell => cell.textContent))), expectedCosts);
    assert.equal(await page.getByText('Здесь будут действующие цены из настроек админки.').count(), 0);
    assert.deepEqual(await page.locator('[data-skill-perks="unarmed-combat"] .Badge--level').allTextContents().then(normalizeGuideText), ['I', 'II', 'III']);
    let rules = 0;
    for (const [slug, attribute] of Object.entries(data.development)) {
      const expectedLevels = [...new Set([...(attribute.initial_description ? [1] : []), ...attribute.rules.map(rule => rule.level)])].sort((a, b) => a - b);
      assert.deepEqual(await slot(slug).locator('li > span').allTextContents().then(normalizeGuideText), expectedLevels.map(String));
      assert.ok(attribute.rules.every(rule => rule.description));
      assert.equal(await slot(slug).locator('.paper-highlight').count(), attribute.rules.filter(rule => rule.type.includes('damage')).length);
      rules += attribute.rules.length;
    }
    const row = (slug, level) => slot(slug).locator('li').filter({has: page.locator(':scope > span', {hasText: new RegExp('^' + level + '$')})});
    assert.equal(await row('physiology', 1).innerText().then(normalizeGuideText), '1\nБазовый запас выносливости — 10 ед. Базовый урон в ближнем бою — Незначительный');
    assert.match(await row('physiology', 22).innerText().then(normalizeGuideText), /двойной Средний/);
    assert.match(await row('magic', 5).innerText().then(normalizeGuideText), /Вы владеете магическим оружием\. Базовый магический урон — Малый/);
    assert.match(await row('magic', 7).innerText().then(normalizeGuideText), /Доступ к заклинаниям II круга\. Получите 1 уровень/);
    await slot('physiology').scrollIntoViewIfNeeded();
    await page.screenshot({path: 'packages/roleplay-new/tests/.runtime/guide-development-desktop.png'});
    await page.setViewportSize({width: 390, height: 844});
    await slot('physiology').scrollIntoViewIfNeeded();
    await page.evaluate(() => new Promise(requestAnimationFrame));
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    await page.screenshot({path: 'packages/roleplay-new/tests/.runtime/guide-development-mobile.png'});

    // Изменение порога, значения урона и формулировки должно сразу менять страницу.
    const changed = structuredClone(data);
    changed.advancement.skill_minimums[2] = 9;
    changed.advancement.skill_minimums[3] = 18;
    changed.advancement.experience_costs = [{minimum: 2, cost: 123}, {minimum: 8, cost: 456}];
    changed.advancement.maximum_level = 23;
    const physiology = changed.development.physiology;
    physiology.rules.find(rule => rule.type === 'skill').level = 6;
    const damage = physiology.rules.find(rule => rule.level === 2);
    damage.value = 81;
    damage.description = '<em>Текст из базы</em> — {damage}';
    physiology.initial_stats.stamina_max = 42;
    await page.route('**/api/roleplay/guide', route => route.fulfill({json: {data: changed}}));
    await page.reload({waitUntil: 'networkidle'});
    await slot('physiology').locator('.paper-highlight').first().waitFor();
    assert.equal(await page.locator('[data-skill-minimum="2"]').textContent().then(normalizeGuideText), '9');
    assert.equal(await page.locator('[data-skill-minimum="3"]').textContent().then(normalizeGuideText), '18');
    assert.deepEqual(await costs.locator('tr').evaluateAll(rows => rows.map(row => [...row.cells].map(cell => cell.textContent))), [['2–7', '123'], ['8–23', '456']]);
    assert.equal(await row('physiology', 4).count(), 0);
    assert.match(await row('physiology', 6).innerText().then(normalizeGuideText), /Получите 1 уровень/);
    assert.match(await row('physiology', 1).innerText().then(normalizeGuideText), /42 ед\./);
    assert.equal(await row('physiology', 2).locator('.paper-highlight').innerText().then(normalizeGuideText), 'Смертельный');
    assert.match(await row('physiology', 2).innerText().then(normalizeGuideText), /<em>Текст из базы<\/em>/);
    assert.equal(await row('physiology', 2).locator('em').count(), 0, 'Database descriptions are text, not trusted markup');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({attributes: 4, rules, liveThresholds: true, liveDamage: true, escapedText: true, mobileOverflow: false, errors}));
  } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
