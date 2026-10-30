param([string]$Directory=$PSScriptRoot)
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Drawing
# Literal copy/paste of the existing Questpost crown: no artwork regeneration.
Add-Type -TypeDefinition @'
using System;
public static class SharedCityCrown {
 static bool Foreground(byte[] p,int i,int[] bg) {
  int z=i*4;
  return p[z+3]>128 && Math.Abs(p[z+2]-bg[0])+Math.Abs(p[z+1]-bg[1])+Math.Abs(p[z]-bg[2])>2;
 }
 public static bool[] CrownMask(byte[] p,int w,int h,int[] bg) {
  int seed=-1;
  for(int y=30;y<500&&seed<0;y++)for(int x=250;x<950;x++){
   int z=(y*w+x)*4,r=p[z+2],g=p[z+1],b=p[z];
   if(r>140&&g>90&&b<170&&r>b*1.2&&Foreground(p,y*w+x,bg)){seed=y*w+x;break;}
  }
  if(seed<0)throw new Exception("Crown not found");
  var mask=new bool[w*h];var q=new int[w*h];int head=0,tail=0;q[tail++]=seed;mask[seed]=true;
  while(head<tail){
   int i=q[head++],x=i%w,y=i/w;
   int[] ns={x>0?i-1:-1,x<w-1?i+1:-1,y>0?i-w:-1,y<h-1?i+w:-1};
   foreach(int v in ns)if(v>=0&&!mask[v]&&Foreground(p,v,bg)){mask[v]=true;q[tail++]=v;}
  }
  int[] box=Bounds(mask,w,h);
  if(box[2]-box[0]<200||box[3]>460)throw new Exception("Unexpected crown component; manual inspection required");
  return mask;
 }
 public static int[] Bounds(bool[] mask,int w,int h){
  int x0=w,y0=h,x1=0,y1=0;
  for(int i=0;i<mask.Length;i++)if(mask[i]){int x=i%w,y=i/w;x0=Math.Min(x0,x);x1=Math.Max(x1,x);y0=Math.Min(y0,y);y1=Math.Max(y1,y);}
  return new int[]{x0,y0,x1,y1};
 }
 public static byte[] Extract(byte[] source,bool[] mask,int w,int[] box,int[] bg){
  int cw=box[2]-box[0]+1,ch=box[3]-box[1]+1;
  var cutout=new byte[cw*ch*4];
  for(int y=0;y<ch;y++)for(int x=0;x<cw;x++){
   int i=(y+box[1])*w+x+box[0];if(!mask[i])continue;
   int a=i*4,z=(y*cw+x)*4;
   Buffer.BlockCopy(source,a,cutout,z,4);
   // Remove the source field's tint from antialias pixels on black outlines.
   double rr=source[a+2]/(double)bg[0],gg=source[a+1]/(double)bg[1],bb=source[a]/(double)bg[2];
   if(Math.Max(rr,Math.Max(gg,bb))-Math.Min(rr,Math.Min(gg,bb))<0.13 && Math.Max(rr,Math.Max(gg,bb))<1.03){
    double t=(rr+gg+bb)/3;
    cutout[z]=cutout[z+1]=cutout[z+2]=0;
    cutout[z+3]=(byte)Math.Round(255*Math.Max(0,Math.Min(1,1-t)));
   }
  }
  return cutout;
 }
 public static int Replace(byte[] dst,bool[] oldMask,int w,int h,int[] bg,byte[] crown,int cw,int ch,int px,int py){
  var clear=(bool[])oldMask.Clone();
  for(int i=0;i<oldMask.Length;i++)if(oldMask[i]){
   int x=i%w,y=i/w;
   int[] ns={x>0?i-1:-1,x<w-1?i+1:-1,y>0?i-w:-1,y<h-1?i+w:-1};
   foreach(int v in ns)if(v>=0)clear[v]=true;
  }
  for(int i=0;i<clear.Length;i++)if(clear[i]){int z=i*4;dst[z]=(byte)bg[2];dst[z+1]=(byte)bg[1];dst[z+2]=(byte)bg[0];dst[z+3]=255;}
  int count=0;
  for(int y=0;y<ch;y++)for(int x=0;x<cw;x++){
   int z=(y*cw+x)*4;if(crown[z+3]==0)continue;
   int i=(y+py)*w+x+px,a=i*4;
   if(x+px<0||x+px>=w||y+py<0||y+py>=h)throw new Exception("Crown outside canvas");
   // Every new crown pixel must fall onto plain field, never onto the city charge.
   if(Foreground(dst,i,bg))throw new Exception("Crown overlaps city artwork");
   double alpha=crown[z+3]/255.0;
   for(int c=0;c<3;c++)dst[a+c]=(byte)Math.Round(crown[z+c]*alpha+dst[a+c]*(1-alpha));
   dst[a+3]=255;count++;
  }
  return count;
 }
 public static int ChangedOutside(byte[] before,byte[] after,bool[] oldMask,int w,int px,int py,int cw,int ch){
  int bad=0;
  for(int i=0;i<oldMask.Length;i++){
   int x=i%w,y=i/w;
   bool allowed=(x>=px&&x<px+cw&&y>=py&&y<py+ch)||oldMask[i];
   if(!allowed){
    int[] ns={x>0?i-1:-1,x<w-1?i+1:-1,y>0?i-w:-1,y<oldMask.Length/w-1?i+w:-1};
    foreach(int v in ns)if(v>=0&&oldMask[v])allowed=true;
   }
   if(!allowed)for(int c=0;c<4;c++)if(before[i*4+c]!=after[i*4+c]){bad++;break;}
  }
  return bad;
 }
}
'@
function Read-Pixels([System.Drawing.Bitmap]$Image){
 $bits=$Image.LockBits([System.Drawing.Rectangle]::new(0,0,$Image.Width,$Image.Height),[System.Drawing.Imaging.ImageLockMode]::ReadOnly,[System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
 try{$bytes=New-Object byte[] ($bits.Stride*$Image.Height);[System.Runtime.InteropServices.Marshal]::Copy($bits.Scan0,$bytes,0,$bytes.Length);return ,$bytes}finally{$Image.UnlockBits($bits)}
}
function Save-Pixels([byte[]]$Pixels,[int]$Width,[int]$Height,[string]$Path){
 $bitmap=[System.Drawing.Bitmap]::new($Width,$Height,[System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
 $bits=$bitmap.LockBits([System.Drawing.Rectangle]::new(0,0,$Width,$Height),[System.Drawing.Imaging.ImageLockMode]::WriteOnly,[System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
 try{[System.Runtime.InteropServices.Marshal]::Copy($Pixels,0,$bits.Scan0,$Pixels.Length)}finally{$bitmap.UnlockBits($bits)}
 $bitmap.Save($Path,[System.Drawing.Imaging.ImageFormat]::Png);$bitmap.Dispose()
}
$manifest=Get-Content -LiteralPath (Join-Path $Directory 'manifest.json') -Raw|ConvertFrom-Json
$quest=$manifest.cities|Where-Object key -eq 'questpost'
$source=[System.Drawing.Bitmap]::new((Join-Path $Directory $quest.file))
$sourcePixels=Read-Pixels $source
$mask=[SharedCityCrown]::CrownMask($sourcePixels,1200,1600,[int[]]$quest.fieldRGB)
$bounds=[SharedCityCrown]::Bounds($mask,1200,1600)
$cw=$bounds[2]-$bounds[0]+1;$ch=$bounds[3]-$bounds[1]+1
$crown=[SharedCityCrown]::Extract($sourcePixels,$mask,1200,$bounds,[int[]]$quest.fieldRGB)
Save-Pixels $crown $cw $ch (Join-Path $Directory 'shared-crown.png')
$source.Dispose()
$records=@()
$inputs=@{verkhovye='verkhovye-v2.png';zelenolesye='zelenolesye.png';arah='arah-v2.png';hillsgaard='hillsgaard.png';grim='grim.png'}
foreach($city in $manifest.cities|Where-Object key -ne 'questpost'){
 $city.file=$inputs[$city.key]
 $image=[System.Drawing.Bitmap]::new((Join-Path $Directory $city.file))
 $before=Read-Pixels $image
 $after=[byte[]]$before.Clone()
 $oldMask=[SharedCityCrown]::CrownMask($before,1200,1600,[int[]]$city.fieldRGB)
 $oldBounds=[SharedCityCrown]::Bounds($oldMask,1200,1600)
 $px=[int][Math]::Floor((1200-$cw)/2)
 $py=$oldBounds[3]-$ch+1
 $copied=[SharedCityCrown]::Replace($after,$oldMask,1200,1600,[int[]]$city.fieldRGB,$crown,$cw,$ch,$px,$py)
 $bad=[SharedCityCrown]::ChangedOutside($before,$after,$oldMask,1200,$px,$py,$cw,$ch)
 if($bad -ne 0){throw "Changed pixels outside crown regions: $bad"}
 $output=$city.key+'-v3.png'
 Save-Pixels $after 1200 1600 (Join-Path $Directory $output)
 $records+=@{key=$city.key;source=$city.file;file=$output;oldCrownBounds=$oldBounds;newCrown=@{x=$px;y=$py;width=$cw;height=$ch};changedOutsideCrown=0}
 $image.Dispose()
}
@{source='questpost.png';sourceBounds=$bounds;crownWidth=$cw;crownHeight=$ch;cities=$records}|ConvertTo-Json -Depth 8|Set-Content -LiteralPath (Join-Path $Directory 'revision-v3.json') -Encoding utf8
$records|ConvertTo-Json -Depth 5

