/**
 * Sync ready heraldry from content/flags/src into both guide entry points.
 * Run from the workspace: node packages/roleplay-new/content/flags/sync-heraldry.cjs
 */
const fs = require('node:fs');
const path = require('node:path');
const root = __dirname, packageRoot = path.resolve(root, '../..');
const recipes = JSON.parse(fs.readFileSync(path.join(root, 'generated/2026-10-31/recipes.json'), 'utf8').replace(/^\uFEFF/, ''));
const byKey = new Map(recipes.map(item => [item.key, item]));
const cities = [
 ['questpost-v2','Квестпост'],['verkhovye-v3','Верховье'],['zelenolesye-v3','Зеленолесье'],
 ['arah-v3','Оазис Арах'],['hillsgaard-v3','Хиллсгард'],['grim-v5','Грим'],
];
const organizations = [
 ['guild-questpost-v1','Гильдия Квестпоста'],['kamnegriv-v1','Клан Камнегрив'],
 ['gundar-v2','Клан Гундар'],['brennor-v1','Клан Бреннор'],
 ['aelsir-v1','Дом Поющей Осины — род Аэльсир'],
 ['talveyn-v1','Дом Внемлющей Чащи — род Талвэйн'],
 ['saelrin-v1','Дом Зерна и Коры — род Саэлрин'],
 ['dalmir-v1','Дом Плетёных Стен — род Далмир'],
 ['liorel-v1','Дом Лунного Мха — род Лиорэль'],
 ['almeon-v1','Клан Альмеон'],['tkhist-v1','Клан Тхист'],['khadim-v1','Клан Хадим'],
 ['erta-v1','Клан Эр’Та'],['sylvanhart-v1','Клан Сильванхарт'],
 ['grossblood-purple-v2','Клан Гроссблад'],['nod-v1','Клан Нод'],['klinki-v1','Клинки'],
 ['collegium-v4','Коллегия Магии'],['pantheon-v2','Пантеон Основ'],
];
const entries = [...cities.map(([key,name])=>({key,name,kind:'city'})),
 ...organizations.map(([key,name])=>({key,name,kind:'organization'}))].map(item=>{
 const file=item.key+'.png', image=fs.readFileSync(path.join(root,'src',file)), recipe=byKey.get(item.key);
 if(image.toString('hex',0,8)!=='89504e470d0a1a0a')throw Error('Invalid PNG: '+file);
 return {...item,file,width:image.readUInt32BE(16),height:image.readUInt32BE(20),
  ...(recipe?{motif:recipe.motif||(item.kind==='city'?'Городской герб без короны.':''),
   shape:recipe.shape,fields:recipe.fields,layout:recipe.layout}:{})};
});
const byName=new Map(entries.filter(item=>item.kind==='organization').map(item=>[item.name,item]));
function rename(text){
 const replacements=[
  ['обеспечивает Эспада','обеспечивают Клинки'],['изначальная Эспада','изначальные Клинки'],
  ['Эспада менялась','Клинки менялись'],['Эспада состоит','Клинки состоят'],
  ['Эспада посвящена','Клинки посвящены'],['Эспада может быть','Клинок может быть'],
  ['моя дорогая Эспада','мои дорогие Клинки'],
  ['Эспада, партия власти нового мира, основана','Клинки — партия власти Нового Мира, основанная'],
  ['Октава Эспада','восьмому Клинку'],['с Серо Эспада - главе разведки','с Серо, главой разведки'],
  ['Эспадой','Клинками'],['Эспады','Клинков'],['Эспаде','Клинкам'],['Эспаду','Клинки'],['Эспада','Клинки'],
  ['эспадами','клинками'],['эспады','клинки'],['эспада','клинок'],
 ];
 for(const [old,value] of replacements)text=text.replaceAll(old,value);
 return text.replaceAll('Клинки складывалась','Клинки складывались')
  .replaceAll('Клинки складывались вокруг Айзека; её состав','Клинки складывались вокруг Айзека; их состав')
  .replaceAll('Клинки складывались вокруг Айзека, её состав','Клинки складывались вокруг Айзека, их состав');
}
for(const document of ['resources/guide/content.html','preview/role/index.html','content/world.md','content/tabs/world.md']){
 const file=path.join(packageRoot,document);
 let text=rename(fs.readFileSync(file,'utf8'));
 if(document.endsWith('.html')){
  text=text.replaceAll('/city-crests/questpost.png','/city-crests/questpost-v2.png')
   .replaceAll('/city-crests/grim-v3.png','/city-crests/grim-v4.png')
   .replaceAll('/city-crests/grim-v4.png','/city-crests/grim-v5.png')
   .replaceAll('/organization-crests/gundar-v1.png','/organization-crests/gundar-v2.png')
   .replaceAll('/organization-crests/espada-v1.png','/organization-crests/klinki-v1.png');
  text=text.replace(/<figure class="Figure Figure--crest Figure--placeholder" aria-label="Место для герба: ([^"]+)">[\s\S]*?<\/figure>/g,
   (original,name)=>{
    const item=byName.get(name);if(!item)throw Error('Unknown crest placeholder: '+name);
    return '<figure class="Figure Figure--crest">\n                          <img src="/role/assets/organization-crests/'+item.file+
     '" alt="Герб: '+name+'" width="1200" height="1600" loading="lazy" decoding="async">\n                        </figure>';
   });
  if(/Место для герба|Эспад[а-яё]*/i.test(text))throw Error('Unfinished heraldry in '+document);
 }
 fs.writeFileSync(file,text);
}
// Existing PHP asset delivery continues to use the same safe preview asset root.
for(const item of entries){
 const folder=item.kind==='city'?'city-crests':'organization-crests';
 const destination=path.join(packageRoot,'preview/role/assets',folder);
 fs.mkdirSync(destination,{recursive:true});
 fs.copyFileSync(path.join(root,'src',item.file),path.join(destination,item.file));
}
const serverPath=path.join(packageRoot,'preview/server.cjs');
let server=fs.readFileSync(serverPath,'utf8');
server=server.replace(/const cityCrestNames = \[[\s\S]*?\];/,'const cityCrestNames = '+JSON.stringify(cities.map(([key])=>key+'.png'),null,2)+';')
 .replace(/const organizationCrestNames = \[[\s\S]*?\];/,'const organizationCrestNames = '+JSON.stringify(organizations.map(([key])=>key+'.png'),null,2)+';')
 .replace('// Только готовые гербы организаций; для остальных в HTML оставлены заглушки.',
  '// Готовые гербы всех организаций страницы; исходники — content/flags/src.');
fs.writeFileSync(serverPath,server);
fs.writeFileSync(path.join(root,'manifest.json'),JSON.stringify({updated:'2026-10-31',entries},null,2)+'\n');
const rows=entries.map(item=>'| '+item.name+' | [PNG](src/'+item.file+') | '+(item.motif||'Ранее утверждённый герб.')+' |').join('\n');
const section='<!-- heraldry:active:start -->\n## Актуальные гербы\n\n'+
 'reference/ — референсы стиля; src/ — итоговые изображения. Актуальный список — manifest.json.\n\n'+
 'Новые файлы: 1200 × 1600 px, прозрачность только вне контура. У Квестпоста и Грима убраны короны. Клинки используют выбранный классический череп из src/espada-classic-v1.png без изменения масштаба рисунка.\n\n'+
 '| Город / организация | Файл | Символика |\n| --- | --- | --- |\n'+rows+'\n\n'+
 '[Общий просмотр](overview-2026-10-31.png). Новые гербы созданы встроенным imagegen. Полные промпты, исходники и проверки находятся в generated/2026-10-31/. Предыдущие версии сохранены. Описания мотивов относятся к рисункам и не добавляют исторических событий в лор.\n\n'+
 'После замены изображения в src/ запустите node packages/roleplay-new/content/flags/sync-heraldry.cjs для обновления копий страницы. При изменении списка файлов перезапустите preview-сервер.\n<!-- heraldry:active:end -->';
const readmePath=path.join(root,'README.md'),readme=fs.existsSync(readmePath)?fs.readFileSync(readmePath,'utf8'):'# Гербы\n';
fs.writeFileSync(readmePath,/<!-- heraldry:active:start -->[\s\S]*?<!-- heraldry:active:end -->/.test(readme)
 ?readme.replace(/<!-- heraldry:active:start -->[\s\S]*?<!-- heraldry:active:end -->/,section)
 :readme.trimEnd()+'\n\n'+section+'\n');
console.log(JSON.stringify({cities:cities.length,organizations:organizations.length,assets:entries.length,placeholders:0}));
