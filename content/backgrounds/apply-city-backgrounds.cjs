/**
 * Installs the four user-selected city backgrounds in both guide documents.
 * Downloads and original URLs are recorded in content/backgrounds/city-backgrounds.json.
 */
const fs=require('node:fs'),path=require('node:path');
const root=path.resolve(__dirname,'../..');
const {images}=JSON.parse(fs.readFileSync(path.join(__dirname,'city-backgrounds.json'),'utf8'));
if(images.length!==4||images.some(x=>x.error))throw Error('City background downloads incomplete');
const details={
 verkhovye:{settlement:'verkhovye',alt:'Верховье: каменные ворота города'},
 arah:{settlement:'oazis-arakh',alt:'Оазис Арах: рынок'},
 hillsgaard:{settlement:'hillsgaard',alt:'Хиллсгард: городская площадь'},
 grim:{settlement:'grim',alt:'Грим: жилой район'},
};
const bySettlement=new Map(images.map(x=>[details[x.key].settlement,{...x,...details[x.key]}]));
const assetDir=path.join(root,'preview/role/assets/city-backgrounds');
fs.mkdirSync(assetDir,{recursive:true});
for(const image of images)fs.copyFileSync(path.join(__dirname,image.file),path.join(assetDir,image.file));
for(const relative of ['resources/guide/content.html','preview/role/index.html']){
 const file=path.join(root,relative);
 const changed=new Set();
 const text=fs.readFileSync(file,'utf8').replace(/(<article\b[^>]*\bdata-settlement="([^"]+)"[^>]*>)([\s\S]*?)(<\/article>)/g,
  (whole,opening,settlement,body,closing)=>{
   const image=bySettlement.get(settlement);if(!image)return whole;
   const figure='<figure class="Figure City-image">\n'+
    '                      <img src="/role/assets/city-backgrounds/'+image.file+'" alt="'+image.alt+
    '" width="'+image.width+'" height="'+image.height+'" loading="lazy" decoding="async">\n'+
    '                    </figure>';
   const updated=body.replace(/<figure class="Figure(?: Figure--placeholder)? City-image">[\s\S]*?<\/figure>/,figure);
   if(updated===body&&!body.includes('/city-backgrounds/'+image.file))throw Error('City image slot missing: '+settlement);
   changed.add(settlement);return opening+updated+closing;
  });
 if(changed.size!==4)throw Error('Not all city backgrounds were installed in '+relative);
 fs.writeFileSync(file,text);
}
const serverPath=path.join(root,'preview/server.cjs');
const names=['questpost.png','zelenolesye.webp',...images.map(x=>x.file)];
let server=fs.readFileSync(serverPath,'utf8');
const declaration='const cityBackgroundNames = '+JSON.stringify(names,null,2)+';';
if(server.includes('const cityBackgroundNames = ')){
 server=server.replace(/const cityBackgroundNames = \[[\s\S]*?\];/,declaration);
}else{
 server=server.replace('const bannerIconNames = ',declaration+'\nconst bannerIconNames = ');
}
server=server.replace(/  \['\/role\/assets\/city-backgrounds\/[^\r\n]+\r?\n/g,'');
if(!server.includes('...cityBackgroundNames.map(')){
 const line="  ...cityCrestNames.map(name => ['/role/assets/city-crests/' + name, path.join(__dirname, 'role/assets/city-crests', name)]),";
 if(!server.includes(line))throw Error('City asset route anchor missing');
 server=server.replace(line,line+"\n  ...cityBackgroundNames.map(name => ['/role/assets/city-backgrounds/' + name, path.join(__dirname, 'role/assets/city-backgrounds', name)]),");
}
fs.writeFileSync(serverPath,server);
console.log(JSON.stringify({installed:images.map(x=>x.name),localCopies:images.length}));
