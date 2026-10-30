const {normalizeGuideText} = require('./guide-test-text.cjs');
/** Произвольное число навыков, перенос между характеристиками и порядок колонок.
 * Ответ API меняется только в браузере; каталог форума не редактируется.
 */
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
    const response = await page.request.get(origin + '/api/roleplay/guide');
    assert.equal(response.status(), 200);
    const {data} = await response.json();
    const changed = structuredClone(data);
    // Исчезнувший/выключенный навык не остаётся в статичном HTML; пустая вкладка допустима.
    changed.skills = changed.skills.filter(skill => skill.attribute_slug !== 'charisma' && skill.slug !== 'awareness');
    changed.skills.find(skill => skill.slug === 'unarmed-combat').attribute_slug = 'magic';
    const template = structuredClone(changed.skills[0]);
    for (let index = 0; index < 6; index++) changed.skills.push({...template,
      slug: 'test-new-skill-' + index, name: 'Новый навык ' + index,
      description: 'Описание <b>из справочника</b>.\n\nВторой абзац.', attribute_slug: 'physiology'});
    await page.route('**/api/roleplay/guide', route => route.fulfill({json: {data: changed}}));
    await page.goto(origin + '/role#rules-development', {waitUntil: 'networkidle'});
    await page.locator('[data-skill="test-new-skill-0"] .RpCard').first().waitFor();
    const group = slug => page.locator('[data-attribute-skills="' + slug + '"]');
    for (const slug of ['physiology', 'agility', 'magic', 'charisma']) {
      const skills = changed.skills.filter(skill => skill.attribute_slug === slug);
      assert.deepEqual(await group(slug).locator('[data-skill]').evaluateAll(nodes => nodes.map(node => node.dataset.skill)), skills.map(skill => skill.slug));
      assert.deepEqual(await page.locator('[data-attribute-skill-list="' + slug + '"] .paper-highlight').allTextContents().then(normalizeGuideText), skills.map(skill => skill.name));
      assert.deepEqual(await group(slug).locator('[data-skill]').evaluateAll(nodes => nodes.map(node => node.classList.contains('Step--reverse'))), skills.map((_, index) => index % 2 === 1));
      assert.deepEqual(await group(slug).locator('.Skill [data-attribute-icon]').evaluateAll(nodes => nodes.map(node => node.dataset.attributeIcon)), skills.map(() => slug));
    }
    assert.equal(await page.locator('[data-skill="awareness"]').count(), 0);
    assert.equal(await group('charisma').locator('[role="status"]').textContent().then(normalizeGuideText), 'У этой характеристики пока нет доступных навыков');
    const newSkill = page.locator('[data-skill="test-new-skill-0"]');
    assert.deepEqual(await newSkill.locator('.Skill p').allTextContents().then(normalizeGuideText), ['Описание <b>из справочника</b>.', 'Второй абзац']);
    assert.equal(await newSkill.locator('.Skill b').count(), 0);
    assert.deepEqual(await newSkill.locator('.Badge--level').allTextContents().then(normalizeGuideText), ['I', 'II', 'III']);
    const order = step => step.evaluate(node => ({
      copy: node.querySelector('.Skill').getBoundingClientRect().x,
      perks: node.querySelector('.Grid').getBoundingClientRect().x,
    }));
    for (let index = 0; index < 2; index++) {
      const step = group('physiology').locator('[data-skill]').nth(index);
      const position = await order(step);
      assert.equal(position.copy < position.perks, index % 2 === 0, 'Desktop columns alternate');
    }
    await newSkill.scrollIntoViewIfNeeded();
    await page.screenshot({path: 'packages/roleplay-new/tests/.runtime/guide-dynamic-skills-desktop.png'});
    await page.setViewportSize({width: 390, height: 844});
    await page.evaluate(() => new Promise(requestAnimationFrame));
    for (let index = 0; index < 2; index++) {
      const step = group('physiology').locator('[data-skill]').nth(index);
      assert.equal(await step.evaluate(node => node.querySelector('.Skill').getBoundingClientRect().bottom
        <= node.querySelector('.Grid').getBoundingClientRect().top), true, 'Phone always shows description before perks');
    }
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    await newSkill.scrollIntoViewIfNeeded();
    await page.screenshot({path: 'packages/roleplay-new/tests/.runtime/guide-dynamic-skills-mobile.png'});
    await page.getByRole('tab', {name: 'Харизма', exact: true}).click();
    await group('charisma').locator('[role="status"]').waitFor();
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({addedSkills: 6, movedSkill: true, removedSkill: true, emptyAttribute: true, alternating: true, mobileOrder: true, escapedDescription: true, errors}));
  } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
