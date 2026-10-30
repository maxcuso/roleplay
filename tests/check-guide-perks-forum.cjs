const {normalizeGuideText} = require('./guide-test-text.cjs');
/** Проверка перков правил на Docker-форуме. Формы не отправляются, база не меняется. */
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
    page.on('console', message => {
      if (message.type() === 'error' && !message.text().startsWith('Failed to load resource')) errors.push(message.text());
    });
    const response = page.waitForResponse(result => result.url().endsWith('/api/roleplay/guide'));
    await page.goto(origin + '/role#rules-development', {waitUntil: 'networkidle'});
    const {data} = await (await response).json();
    const root = page.locator('.RpGuide');
    await root.locator('[data-skill-perks] .RpCard').first().waitFor();
    assert.equal(await root.locator('[data-skill-perks]').count(), data.skills.length);
    assert.equal(await root.locator('[data-race-perks]').count(), data.races.length);
    let skillPerks = 0, racePerks = 0;
    for (const skill of data.skills) {
      const slot = root.locator('[data-skill-perks="' + skill.slug + '"]');
      assert.equal(await slot.count(), 1, skill.slug);
      const step = root.locator('[data-skill="' + skill.slug + '"]');
      assert.equal(await step.locator('.Skill h4').textContent().then(normalizeGuideText), skill.name.toLocaleUpperCase('ru-RU'));
      assert.deepEqual(await step.locator('.Skill p').allTextContents().then(normalizeGuideText), (skill.description ?? '').replace(/\.$/, '').split(/\r?\n\s*\r?\n/).filter(Boolean));
      assert.deepEqual(await slot.locator('.Badge--level').allTextContents().then(normalizeGuideText), skill.levels.map(row => ['', 'I', 'II', 'III'][row.level]));
      assert.deepEqual(await slot.locator('.RpCard-heading strong').allTextContents().then(normalizeGuideText),
        skill.levels.filter(row => row.perk).map(row => row.perk.name));
      assert.equal(await slot.locator('.EntityExample').count(), 0);
      assert.ok((await slot.locator('.RpCard-footerLabel').allTextContents().then(normalizeGuideText)).every(label => label.includes(skill.name)));
      skillPerks += await slot.locator('.RpCard').count();
    }
    for (const race of data.races) {
      const slot = root.locator('[data-race-perks="' + race.slug + '"]');
      assert.equal(await slot.count(), 1, race.slug);
      assert.deepEqual(await slot.locator('.RpCard-heading strong').allTextContents().then(normalizeGuideText),
        [...race.fixed_perks, ...race.choice_perks].map(perk => perk.name));
      assert.ok((await slot.locator('.RpCard-footerLabel').allTextContents().then(normalizeGuideText)).every(label => label.includes(race.name)));
      racePerks += await slot.locator('.RpCard').count();
    }
    assert.equal(await root.locator('.EntityExample').count(), 0, 'No stale perk copies on the forum');
    const badge = await root.locator('.Skill .Badge').first().evaluate(element => {
      const css = getComputedStyle(element), texture = getComputedStyle(element, '::before');
      return {background: css.backgroundColor, shadow: css.boxShadow, border: css.borderWidth, mask: texture.maskImage};
    });
    assert.equal(badge.background, 'rgba(0, 0, 0, 0)');
    assert.equal(badge.shadow, 'none');
    assert.equal(badge.border, '0px');
    assert.ok(badge.mask.includes('badge-circle.webp'));
    const skillSlot = root.locator('[data-skill-perks="unarmed-combat"]');
    await skillSlot.scrollIntoViewIfNeeded();
    await page.screenshot({path: 'packages/roleplay-new/tests/.runtime/guide-skill-cards-desktop.png'});
    await page.setViewportSize({width: 390, height: 844});
    await skillSlot.scrollIntoViewIfNeeded();
    await page.evaluate(() => new Promise(requestAnimationFrame));
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    assert.equal(await skillSlot.locator('.Step--perk').first().evaluate(element =>
      getComputedStyle(element).gridTemplateColumns.split(' ').length), 2);
    for (const slug of ['unarmed-combat', 'awareness']) {
      const slot = root.locator('[data-skill-perks="' + slug + '"]');
      assert.ok(await slot.evaluate(element => element.parentElement.querySelector('.Skill').getBoundingClientRect().top
        < element.getBoundingClientRect().top), 'On phone: skill first, then perks');
    }
    await page.screenshot({path: 'packages/roleplay-new/tests/.runtime/guide-skill-cards-mobile.png'});
    await root.getByRole('tab', {name: 'Мир', exact: true}).click();
    const raceSlot = root.locator('[data-race-perks="zverolyud"]');
    await raceSlot.scrollIntoViewIfNeeded();
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    assert.equal(await raceSlot.locator('.SectionKicker').textContent().then(normalizeGuideText), 'Перки на выбор — 3');
    await page.screenshot({path: 'packages/roleplay-new/tests/.runtime/guide-race-cards-mobile.png'});
    await page.setViewportSize({width: 1440, height: 1100});
    await raceSlot.scrollIntoViewIfNeeded();
    await page.screenshot({path: 'packages/roleplay-new/tests/.runtime/guide-race-cards-desktop.png'});
    await page.evaluate(() => m.route.set('/'));
    await root.waitFor({state: 'hidden'});
    assert.equal(await page.evaluate(() => document.documentElement.classList.contains('GuidePage')), false);
    await page.locator('a[href="/role"], a[href="' + origin + '/role"]').first().click();
    // Возврат сохраняет открытую вкладку «Мир», поэтому карточки правил есть, но скрыты.
    await root.locator('[data-skill-perks] .RpCard').first().waitFor({state: 'attached'});
    assert.equal(await root.locator('[data-skill-perks] .RpCard').count(), skillPerks);
    assert.equal(await root.locator('[data-race-perks] .RpCard').count(), racePerks);
    // Только подмена ответа в изолированном браузере: отсутствующий перк не восстанавливает старый текст.
    const missing = structuredClone(data);
    missing.skills[0].levels[1].perk = null;
    missing.skills[0].name = 'Переименованный навык';
    missing.races = missing.races.filter(race => race.slug !== 'human');
    await page.route('**/api/roleplay/guide', route => route.fulfill({json: {data: missing}}));
    await page.goto(origin + '/role#rules-development', {waitUntil: 'networkidle'});
    await page.reload({waitUntil: 'networkidle'});
    await root.locator('[data-skill-perks="unarmed-combat"] [role="status"]').waitFor();
    assert.equal(await root.locator('[data-skill-perks="unarmed-combat"] .RpCard').count(), 2);
    assert.ok((await root.locator('[data-skill-perks="unarmed-combat"] .RpCard-footerLabel').allTextContents().then(normalizeGuideText))
      .every(label => label.includes('Переименованный навык')));
    assert.equal(await root.locator('[data-race-perks="human"] .RpCard').count(), 0);
    assert.equal(await root.locator('[data-race="human"]').count(), 0, 'Removed race leaves no static block');
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({skills: data.skills.length, skillPerks, races: data.races.length, racePerks,
      badge, mobileOverflow: false, missingLinks: true, errors}));
  } finally {await browser.close();}
})().catch(error => {console.error(error); process.exit(1);});
