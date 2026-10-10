/** Изолированный браузер, без входа в форум и без доступа к пользовательскому профилю браузера. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {chromium} = require(process.env.ROLEPLAY_PLAYWRIGHT ||
  'C:/Users/KusoT/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
(async () => {
  const browser = await chromium.launch({executablePath: 'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe', headless: true});
  try {
    const page = await browser.newPage({viewport: {width: 1150, height: 1000}});
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const responses = [];
    page.on('response', response => {if (response.status() >= 400) responses.push(response.url() + ' ' + response.status());});
    await page.goto('http://127.0.0.1:4173');
    const frame = page.frameLocator('#wizard-frame');
    await frame.getByRole('heading', {name: 'Создание персонажа'}).waitFor();
    for (const name of ['Общие сведения', 'Характеристики', 'Навыки и владения', 'Перки', 'Заклинания', 'Снаряжение', 'Итог']) {
      await page.getByRole('button', {name: new RegExp('\\d\\. ' + name)}).click();
      await frame.locator('nav button[aria-current="step"]').filter({has: frame.locator('i')}).waitFor();
      await page.waitForTimeout(200);
      assert.equal(errors.length, 0, name + ': browser errors');
      console.log('Browser screen OK: ' + name);
    }
    await page.getByRole('button', {name: '2. Характеристики', exact: true}).click();
    // У заполненного примера свободных очков нет. Сначала освободим одно.
    await frame.getByRole('button', {name: 'Уменьшить: Магия', exact: true}).click();
    await page.waitForTimeout(80); // дождаться redraw после обработчика клика
    await frame.getByText('Сохранено', {exact: true}).waitFor();
    await frame.getByRole('button', {name: 'Увеличить: Физиология', exact: true}).click();
    await page.waitForTimeout(80); // дождаться redraw после обработчика клика
    await frame.getByText('Сохранено', {exact: true}).waitFor();
    assert.equal(await frame.locator('.RpWizard-value').first().innerText(), '3');
    await page.getByRole('button', {name: '1. Общие сведения', exact: true}).click();
    await page.getByRole('button', {name: '2. Характеристики', exact: true}).click();
    assert.equal(await frame.locator('.RpWizard-value').first().innerText(), '3', 'Saved attribute survives screen changes');
    await page.getByRole('button', {name: '4. Перки', exact: true}).click();
    await frame.locator('.RpChoice:not(.is-selected):not(.is-disabled)').first().click();
    await page.waitForTimeout(80); // дождаться redraw после обработчика клика
    await frame.getByText('Сохранено', {exact: true}).waitFor();
    assert.equal(await frame.locator('.RpChoice.is-selected').count(), 2);
    assert.equal(await frame.locator('.RpChoice.is-disabled').count(), 2);
    await frame.locator('.RpChoice.is-selected').first().click();
    await page.waitForTimeout(80); // дождаться redraw после обработчика клика
    await frame.getByText('Сохранено', {exact: true}).waitFor();
    assert.equal(await frame.locator('.RpChoice.is-disabled').count(), 0);
    for (const width of ['708', '320']) {
      await page.getByLabel('Ширина').selectOption(width);
      await page.waitForTimeout(150);
      const overflow = await page.frames()[1].evaluate(() => document.documentElement.scrollWidth > innerWidth);
      assert.equal(overflow, false, 'No page overflow at ' + width + 'px');
      await page.screenshot({path: path.join(__dirname, '.runtime/preview-' + width + '.png'), fullPage: true});
    }
    for (const scenario of ['empty', 'long', 'limit']) {
      await page.getByLabel('Пример').selectOption(scenario);
      await page.waitForTimeout(80); // дождаться redraw после обработчика клика
    await frame.getByText('Сохранено', {exact: true}).waitFor();
      await page.waitForTimeout(100);
      assert.equal(errors.length, 0, 'Scenario ' + scenario);
    }
    // Изменение только служебного CSS стенда проверяет живое обновление без потери выбора.
    const cssFile = path.join(__dirname, '../preview/base.css');
    const originalCss = fs.readFileSync(cssFile, 'utf8');
    const stage = page.frames().find(frame => frame.url().includes('/stage.html'));
    const oldLink = await stage.locator('link[href*="/base.css"]').getAttribute('href');
    const selectedBefore = await frame.locator('.RpChoice.is-selected').count();
    try {
      fs.writeFileSync(cssFile, originalCss + '\n/* Проверка обновления без перезагрузки формы. */\n');
      await stage.waitForFunction(old => document.querySelector('link[href*="/base.css"]').getAttribute('href') !== old, oldLink);
      assert.equal(await frame.locator('.RpChoice.is-selected').count(), selectedBefore, 'CSS update preserves selection');
    } finally {fs.writeFileSync(cssFile, originalCss);}
    assert.deepEqual(responses, [], 'All preview resources load');
    assert.deepEqual(errors, []);
    assert.equal((await page.request.get('http://127.0.0.1:4173/tests/.runtime/creation-view.json')).status(), 404);
    console.log('Preview: seven screens, persistence, limits, mobile and allowlist OK.');
  } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});