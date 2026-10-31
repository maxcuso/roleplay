const fs=require('node:fs'),path=require('node:path');
const root='C:/Forum/Flarum/packages/roleplay-new/content/flags';
const recipesPath=path.join(root,'generated/2026-10-31/recipes.json');
const recipes=JSON.parse(fs.readFileSync(recipesPath,'utf8').replace(/^\uFEFF/,''));
const oldGrim=recipes.find(x=>x.key==='grim-v4'),oldGundar=recipes.find(x=>x.key==='gundar-v1');
if(!recipes.some(x=>x.key==='grim-v5'))recipes.push({...oldGrim,key:'grim-v5',
 source:'C:/Users/KusoT/.codex/generated_images/01a149af-703a-7770-91da-8fdb295c9ba6/exec-92fc9db6-1595-4533-8a57-6f06b6276edb.png',
 refs:[path.join(root,'src/grim-v4.png')],motif:'Якорь с золотым канатом и обе волны подняты выше; без короны.',
 prompt:'Built-in imagegen edit: translate the anchor, rope and both wave bands upward together by about 180 px on the 1200x1600 flag, preserving colors, scale and swallowtail silhouette. No crown.'});
if(!recipes.some(x=>x.key==='gundar-v2'))recipes.push({...oldGundar,key:'gundar-v2',
 source:path.join(root,'generated/2026-10-31/gundar-v1-original.png'),shape:'beveled',
 prompt:'Correct the original pointed flag contour to the same flat bottom with two beveled lower corners as the other Upperlands clans; retain generated hammer and anvil artwork without rescaling.'});
fs.writeFileSync(recipesPath,JSON.stringify(recipes,null,2)+'\n');
console.log(JSON.stringify({recipes:recipes.length}));
