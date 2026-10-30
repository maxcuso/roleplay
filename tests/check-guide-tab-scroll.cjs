/** Проверяем переход из глубины раздела, мышь/клавиатуру и мобильную ширину. */
const assert = require('node:assert/strict');
const {chromium} = require(process.env.ROLEPLAY_PLAYWRIGHT ||
  'C:/Users/KusoT/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const origin = process.env.ROLEPLAY_FORUM_URL || 'http://localhost:8081';
(async () => {
  const browser = await chromium.launch({headless: true, executablePath: process.env.ROLEPLAY_BROWSER ||
    'C:/Program Files/Yandex/YandexBrowser/Application/browser.exe'});
  try {
    const page = await browser.newPage({viewport: {width: 1440, height: 1000}, reducedMotion: 'reduce'});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const mainTab = name => page.locator('[data-tabs="guides"]').getByRole('tab', {name, exact: true});
    const atStart = async () => {
      await page.waitForFunction(() => {
        const top = document.querySelector('#guides').getBoundingClientRect().top;
        const padding = parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop) || 0;
        return Math.abs(top - padding) <= 2;
      });
      assert.equal(await page.evaluate(() => location.hash), '#guides');
    };
    await page.goto(origin + '/role#world-organizations', {waitUntil: 'networkidle'});
    await page.locator('#world-organizations').scrollIntoViewIfNeeded();
    assert.ok(await page.evaluate(() => document.querySelector('#guides').getBoundingClientRect().top < -1000));
    await mainTab('Правила игры').click();
    await atStart();
    assert.equal(await page.locator('#panel-rules').isVisible(), true);
    await page.locator('#rules-combat').scrollIntoViewIfNeeded();
    await mainTab('Правила игры').focus();
    await page.keyboard.press('ArrowRight');
    await atStart();
    assert.equal(await page.locator('#panel-lore').isVisible(), true);
    await page.setViewportSize({width: 390, height: 844});
    await page.locator('#world-organizations').scrollIntoViewIfNeeded();
    await mainTab('Персонаж').click();
    await atStart();
    assert.equal(await page.locator('#panel-application').isVisible(), true);
    await mainTab('Правила игры').click();
    await page.locator('#tab-agility').click();
    assert.ok(await page.evaluate(() => document.querySelector('#guides').getBoundingClientRect().top < -100), 'Nested tabs do not return to the top');
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({desktopClick: true, keyboard: true, mobileClick: true, nestedTabsUnchanged: true, errors}));
  } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
