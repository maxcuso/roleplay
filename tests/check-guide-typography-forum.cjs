/** Неразрывные пробелы: предлоги, союзы, частицы, inline-разметка и справочники. */
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
    const response = await page.request.get(origin + '/api/roleplay/guide');
    const {data} = await response.json();
    data.html += `<section id="typography-sample">
      <p id="prepositions">В городе, на площади, из-за тумана, под мостом и за стеной</p>
      <p id="inline-preposition"><strong>К</strong> <em>мастеру</em> и в <strong>городе</strong></p>
      <p id="indented-preposition">После\n          Катаклизма</p>
      <p id="particles">Если бы не туман, то в городе было бы светло</p>
      <p id="spelling">Ещё одно умение; с врожденными способностями</p>
      <p id="ready-preposition">в\u202fгороде</p>
      <p id="first-block">Предлог в </p><p id="next-block">другом абзаце</p>
      <p id="preposition-link">на <a href="/example?text=в%20городе">страницу</a></p>
      <p id="preserved-path">Путь /в папке и https://example.test/на странице</p>
      <pre id="preserved-code">в городе, на площади</pre>
      <textarea id="preserved-input">в городе, на площади</textarea>
      <div contenteditable="true" id="preserved-editable">в городе, на площади</div>
    </section>`;
    await page.route('**/api/roleplay/guide', route => route.fulfill({json: {data}}));
    await page.goto(origin + '/role', {waitUntil: 'networkidle'});
    await page.locator('[data-tabs="guides"] button').first().waitFor();
    assert.equal(await page.locator('#prepositions').textContent(), 'В\u00a0городе, на\u00a0площади, из-за\u00a0тумана, под\u00a0мостом и\u00a0за\u00a0стеной');
    assert.equal(await page.locator('#inline-preposition').textContent(), 'К\u00a0мастеру и\u00a0в\u00a0городе');
    assert.equal(await page.locator('#inline-preposition strong').first().textContent(), 'К');
    assert.equal(await page.locator('#inline-preposition em').textContent(), 'мастеру');
    assert.equal(await page.locator('#indented-preposition').textContent(), 'После\u00a0Катаклизма');
    assert.equal(await page.locator('#ready-preposition').textContent(), 'в\u00a0городе');
    assert.equal(await page.locator('#first-block').textContent(), 'Предлог в ');
    assert.equal(await page.locator('#preposition-link').textContent(), 'на\u00a0страницу');
    assert.equal(await page.locator('#preposition-link a').getAttribute('href'), '/example?text=в%20городе');
    assert.equal(await page.locator('#preserved-path').textContent(), 'Путь /в папке и\u00a0https://example.test/на странице');
    assert.equal(await page.locator('#preserved-code').textContent(), 'в городе, на площади');
    assert.equal(await page.locator('#preserved-input').inputValue(), 'в городе, на площади');
    assert.equal(await page.locator('#preserved-editable').textContent(), 'в городе, на площади');
    assert.ok((await page.locator('[data-skill="unarmed-combat"] .Skill p').textContent()).includes('с\u00a0оружием'));
    assert.equal(await page.locator('#particles').textContent(), 'Если\u00a0бы\u00a0не\u00a0туман, то\u00a0в\u00a0городе было бы\u00a0светло');
    assert.equal(await page.locator('#spelling').textContent(), 'Ещё одно умение, с\u00a0врождёнными способностями');
    assert.equal(await page.locator('.RpGuide').evaluate(root => root.textContent.includes('\u202f')), false);
    const count = await page.locator('.RpGuide').evaluate(root => (root.textContent.match(/\u00a0/g) || []).length);
    assert.ok(count > 100, 'Typography applies throughout the guide');
    await page.setViewportSize({width: 390, height: 844});
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({nonbreakingSpaces: count, inlineMarkup: true, codeAndFormsUnchanged: true, catalogueText: true, mobileOverflow: false, errors}));
  } finally {await browser.close();}
})().catch(error => {console.error(error); process.exitCode = 1;});
