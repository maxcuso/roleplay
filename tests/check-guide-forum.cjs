const {normalizeGuideText} = require('./guide-test-text.cjs');
const assert=require('node:assert/strict');
const {chromium}=require(process.env.ROLEPLAY_PLAYWRIGHT || 'C:/Users/KusoT/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const origin=process.env.ROLEPLAY_FORUM_URL || 'http://localhost:8081';
(async()=>{
 const browser=await chromium.launch({headless:true,executablePath:process.env.ROLEPLAY_BROWSER || 'C:/Program Files/Yandex/YandexBrowser/Application/browser.exe'});
 try {
  const page=await browser.newPage({viewport:{width:1440,height:1000},reducedMotion:'reduce'}),errors=[],failed=[];
  page.on('pageerror',e=>errors.push(e.message));
  page.on('console',m=>{if(m.type()==='error'&&!m.text().startsWith('Failed to load resource'))errors.push(m.text())});
  page.on('response',r=>{if(r.status()>=400&&!r.url().endsWith('/favicon.ico'))failed.push(r.status()+' '+r.url())});
  const guideResponse=page.waitForResponse(response=>new URL(response.url()).pathname.endsWith('/api/roleplay/guide'));
  await page.goto(origin+'/role',{waitUntil:'networkidle'});
  const root=page.locator('.RpGuide');
  await root.locator('[data-tabs="guides"] button[aria-selected="true"]').waitFor();
  await page.evaluate(()=>document.fonts.ready);
  const {data}=await (await guideResponse).json();
  assert.ok(data.perks.length<=3);
  for(const perk of data.perks)assert.deepEqual(Object.keys(perk).sort(),['description','id','image','name','rarity']);
  const examples=root.locator('[data-roleplay-block="perk-examples"]');
  assert.equal(await examples.locator('.EntityExample--placeholder').count(),0);
  assert.deepEqual(await examples.locator('.RpCard-heading strong').allTextContents().then(normalizeGuideText),data.perks.map(perk=>perk.name));
  assert.deepEqual(await examples.locator('.RpCard-description').allTextContents().then(normalizeGuideText),data.perks.map(perk=>perk.description?.replace(/\.$/, '') ?? ''));
  assert.equal(await root.locator('[data-tabs="guides"] > button').count(),3);
  assert.equal(await root.locator('.Hero-actions a').innerText().then(normalizeGuideText), 'К правилам');
  assert.equal(await root.locator('.Hero-description').innerText().then(normalizeGuideText), 'Создайте героя. Найдите спутников. Отправляйтесь в приключения, которые меняют вас и мир вокруг');
  assert.ok((await root.locator('.Hero-under').innerText().then(normalizeGuideText)).startsWith('Новый континент после Катаклизма'));
  assert.equal(await root.locator('.Banner > p').evaluateAll(items => items.some(item => /\.$/.test(item.textContent.trim()))), false);
  await root.locator('.Contents a[href="#rules-checks"]').click();
  await page.waitForFunction(()=>document.querySelector('#rules-checks').closest('details').open);
  assert.equal(await root.locator('.Contents a[aria-current="location"]').getAttribute('href'),'#rules-checks');
  const damage=root.locator('#rules-checks').locator('xpath=ancestor::details[1]');
  await damage.locator(':scope > summary').click();
  assert.equal(await damage.evaluate(el=>el.open),false);
  await root.locator('.Contents a[href="#rules-checks"]').click();
  await page.waitForFunction(()=>document.querySelector('#rules-checks').closest('details').open);
  await root.getByRole('tab',{name:'Мир',exact:true}).click();
  await root.locator('.Contents a[href="#world-organizations"]').click();
  assert.equal(await root.locator('#world-organizations').evaluate(el=>el.closest('details').open),true);
  // Проверяем все уникальные изображения, в том числе ленивые, внутри закрытых панелей.
  const images=await root.locator('img[src]').evaluateAll(items=>[...new Set(items.map(el=>el.src))]);
  for(let index=0;index<images.length;index+=4){
   await Promise.all(images.slice(index,index+4).map(async src=>{
    const response=await page.request.get(src);
    assert.equal(response.status(),200,src);
   }));
  }
  for(const path of ['settings.php','../composer.json','../../tests/.runtime/snapshot.json']){
   const response=await page.request.get(origin+'/role/media/'+encodeURIComponent(path)+'/file');
   assert.ok([403,404].includes(response.status()),'Private path must not be served: '+path);
  }
  await root.getByRole('tab',{name:'Персонаж',exact:true}).click();
  await root.locator('.Contents a[href="#character-profile"]').click();
  assert.equal(await root.locator('#character-profile').evaluate(el=>el.closest('details').open),true);
  await root.locator('[data-action="create-character"]').first().click();
  await page.locator('.LogInModal').waitFor();
  // Форму входа не отправляем. Следующие проверки начинаем с чистой страницы.
  await page.reload({waitUntil:'networkidle'});
  await root.locator('[data-tabs="guides"] > button').first().waitFor();
  await page.setViewportSize({width:390,height:844});
  for(const tab of ['Правила игры','Мир','Персонаж']){
   await root.getByRole('tab',{name:tab,exact:true}).click();
   await page.evaluate(()=>new Promise(requestAnimationFrame));
   assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,tab+' overflows');
  }
  await page.screenshot({path:'packages/roleplay-new/tests/.runtime/forum-guide-mobile.png'});
  await page.setViewportSize({width:1440,height:1000});
  await root.getByRole('tab',{name:'Мир',exact:true}).click();
  await root.locator('.Contents a[href="#world-settlements"]').click();
  await page.screenshot({path:'packages/roleplay-new/tests/.runtime/forum-guide-world.png'});
  // SPA-переход проверяет снятие обработчиков и повторную инициализацию.
  await page.evaluate(()=>m.route.set('/'));
  await root.waitFor({state:'hidden'});
  assert.equal(await page.evaluate(()=>document.documentElement.classList.contains('GuidePage')),false);
  await page.locator('a[href$="/role"]').first().click();
  await root.locator('[data-tabs="guides"] > button').first().waitFor();
  assert.equal(await root.locator('[data-tabs="guides"] > button').count(),3);
  await root.getByRole('tab',{name:'Правила игры',exact:true}).click();
  assert.equal(await root.locator('#panel-rules').isVisible(),true);
  console.log(JSON.stringify({images:images.length,spaReturn:true,mobileOverflow:false,errors,failed}));
  assert.deepEqual(errors,[]);
  assert.deepEqual(failed,[]);
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exit(1)});
