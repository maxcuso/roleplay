const fs=require('node:fs'),path=require('node:path');
const root='C:/Forum/Flarum/packages/roleplay-new';
const folder=path.join(root,'content/backgrounds');
const jobs=[
 {key:'verkhovye',name:'Верховье',url:'https://questpost.ru/assets/loki/Verh/Verh-vrata.webp'},
 {key:'arah',name:'Оазис Арах',url:'https://questpost.ru/assets/loki/Oazis/Oazis-rinok.webp'},
 {key:'hillsgaard',name:'Хиллсгард',url:'https://questpost.ru/assets/loki/Hills/Hills-ploshad.webp',fallback:'https://i.pinimg.com/originals/01/e6/ae/01e6ae5734f157064b25119b86689541.jpg'},
 {key:'grim',name:'Грим',url:'https://questpost.ru/assets/loki/Grim/Grim-jiloy-rayon.webp'}
];
function format(b){
 if(b.toString('ascii',0,4)==='RIFF'&&b.toString('ascii',8,12)==='WEBP')return 'webp';
 if(b[0]===0xff&&b[1]===0xd8)return 'jpg';
 if(b.toString('hex',0,8)==='89504e470d0a1a0a')return 'png';
 throw Error('Response is not a supported image');
}
function dimensions(b,type){
 if(type==='png')return [b.readUInt32BE(16),b.readUInt32BE(20)];
 if(type==='webp'){
  const chunk=b.toString('ascii',12,16);
  if(chunk==='VP8X')return [1+b.readUIntLE(24,3),1+b.readUIntLE(27,3)];
  if(chunk==='VP8 ')return [b.readUInt16LE(26)&16383,b.readUInt16LE(28)&16383];
  if(chunk==='VP8L'){const n=b.readUInt32LE(21);return [(n&16383)+1,((n>>>14)&16383)+1];}
 }
 if(type==='jpg')for(let i=2;i<b.length-9;){
  if(b[i]!==255){i++;continue;}const m=b[i+1];if(m===255){i++;continue;}
  if([192,193,194,195,197,198,199,201,202,203,205,206,207].includes(m))return [b.readUInt16BE(i+7),b.readUInt16BE(i+5)];
  i+=2+b.readUInt16BE(i+2);
 }
 throw Error('Cannot read image dimensions');
}
(async()=>{
 const results=await Promise.all(jobs.map(async job=>{
  let last;
  for(const url of [job.url,job.fallback].filter(Boolean))try{
   const response=await fetch(url,{signal:AbortSignal.timeout(25000)});
   if(!response.ok)throw Error('HTTP '+response.status);
   const bytes=Buffer.from(await response.arrayBuffer()),type=format(bytes),[width,height]=dimensions(bytes,type);
   const file=job.key+'.'+type;
   fs.writeFileSync(path.join(folder,file),bytes);
   return {...job,source:url,file,width,height,bytes:bytes.length};
  }catch(e){last=e;}
  return {...job,error:String(last)};
 }));
 fs.writeFileSync(path.join(folder,'city-backgrounds.json'),JSON.stringify({images:results},null,2)+'\n');
 console.log(JSON.stringify(results));
 if(results.some(x=>x.error))process.exitCode=1;
})().catch(e=>{console.error(e);process.exitCode=1});
