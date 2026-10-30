param([string]$Directory=$PSScriptRoot)
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Drawing
# Keep the generated illustration at its original scale; rebuild only the field.
Add-Type -TypeDefinition @'
using System;
public static class FlatCityArtwork {
 static double Hue(int r,int g,int b) {
  int hi=Math.Max(r,Math.Max(g,b)),lo=Math.Min(r,Math.Min(g,b));
  if(hi==lo)return 0;
  double d=hi-lo,h=hi==r?60.0*(g-b)/d:hi==g?120+60.0*(b-r)/d:240+60.0*(r-g)/d;
  return h<0?h+360:h;
 }
 static bool BG(byte[] s,int a,double hue) {
  int b=s[a],g=s[a+1],r=s[a+2],hi=Math.Max(r,Math.Max(g,b)),lo=Math.Min(r,Math.Min(g,b));
  if(hi<12)return false;
  double dh=Math.Abs(Hue(r,g,b)-hue);
  return (hi-lo)/(double)hi>0.10 && Math.Min(dh,360-dh)<36;
 }
 static void Neighbors(int p,int w,int h,int[] ns) {
  int x=p%w,y=p/w;
  ns[0]=x>0?p-1:-1;ns[1]=x<w-1?p+1:-1;ns[2]=y>0?p-w:-1;ns[3]=y<h-1?p+w:-1;
 }
 public static int Copy(byte[] s,int w,int h,byte[] dst,int dw,int dh,int ox,int oy,int r,int g,int b) {
  int n=w*h;var dist=new int[n];var q=new int[n];var mask=new bool[n];var outside=new bool[n];var ns=new int[4];
  double hue=Hue(r,g,b);int tail=0,head=0;
  for(int p=0;p<n;p++) {
   dist[p]=-1;int a=p*4,hi=Math.Max(s[a],Math.Max(s[a+1],s[a+2]));
   if(s[a+3]>128 && hi>75 && !BG(s,a,hue)){dist[p]=0;q[tail++]=p;}
  }
  while(head<tail) {
   int p=q[head++];Neighbors(p,w,h,ns);
   foreach(int v in ns)if(v>=0&&dist[v]<0&&s[v*4+3]>128&&!BG(s,v*4,hue)){dist[v]=dist[p]+1;q[tail++]=v;}
  }
  for(int p=0;p<n;p++)mask[p]=dist[p]>=0&&s[p*4+3]>128&&!BG(s,p*4,hue);
  head=tail=0;
  for(int p=0;p<n;p++)if((p<w||p>=n-w||p%w==0||p%w==w-1)&&!mask[p]){outside[p]=true;q[tail++]=p;}
  while(head<tail) {
   int p=q[head++];Neighbors(p,w,h,ns);
   foreach(int v in ns)if(v>=0&&!mask[v]&&!outside[v]){outside[v]=true;q[tail++]=v;}
  }
  for(int p=0;p<n;p++)if(!outside[p]&&s[p*4+3]>128&&!BG(s,p*4,hue))mask[p]=true;
  var seen=new bool[n];
  for(int start=0;start<n;start++)if(mask[start]&&!seen[start]) {
   head=tail=0;q[tail++]=start;seen[start]=true;
   while(head<tail){int p=q[head++];Neighbors(p,w,h,ns);foreach(int v in ns)if(v>=0&&mask[v]&&!seen[v]){seen[v]=true;q[tail++]=v;}}
   if(tail<120)for(int j=0;j<tail;j++)mask[q[j]]=false;
  }
  var final=(bool[])mask.Clone();
  for(int p=0;p<n;p++)if(mask[p]){Neighbors(p,w,h,ns);foreach(int v in ns)if(v>=0&&s[v*4+3]>128)final[v]=true;}
  int count=0;
  for(int p=0;p<n;p++)if(final[p]) {
   int x=p%w+ox,y=p/w+oy;if(x<0||x>=dw||y<0||y>=dh)throw new Exception("Illustration exceeds canvas");
   int a=p*4,z=(y*dw+x)*4;
   if(dst[z+3]<128)throw new Exception("Illustration overlaps exterior");
   Buffer.BlockCopy(s,a,dst,z,4);count++;
  }
  return count;
 }
 public static int InternalHoles(byte[] s,int w,int h) {
  int n=w*h,head=0,tail=0,count=0;var seen=new bool[n];var q=new int[n];var ns=new int[4];
  for(int p=0;p<n;p++)if((p<w||p>=n-w||p%w==0||p%w==w-1)&&s[p*4+3]<128){seen[p]=true;q[tail++]=p;}
  while(head<tail){int p=q[head++];Neighbors(p,w,h,ns);foreach(int v in ns)if(v>=0&&!seen[v]&&s[v*4+3]<128){seen[v]=true;q[tail++]=v;}}
  for(int p=0;p<n;p++)if(s[p*4+3]<128&&!seen[p])count++;
  return count;
 }
}
'@
function Read-Pixels([System.Drawing.Bitmap]$Image) {
 $bits=$Image.LockBits([System.Drawing.Rectangle]::new(0,0,$Image.Width,$Image.Height),[System.Drawing.Imaging.ImageLockMode]::ReadOnly,[System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
 try{$bytes=New-Object byte[] ($bits.Stride*$Image.Height);[System.Runtime.InteropServices.Marshal]::Copy($bits.Scan0,$bytes,0,$bytes.Length);return ,$bytes}
 finally{$Image.UnlockBits($bits)}
}
$entries=Get-Content -LiteralPath (Join-Path $Directory 'revision-v2.json') -Raw | ConvertFrom-Json
$results=@()
foreach($entry in $entries) {
 $key=$entry.key
 $rgb=if($key -eq 'verkhovye'){@(99,42,53)}else{@(38,49,85)}
 $src=[System.Drawing.Bitmap]::new($entry.source)
 if($src.Width -gt 1200 -or $src.Height -gt 1600){throw 'Source needs review: exceeds agreed canvas'}
 Copy-Item -LiteralPath $entry.source -Destination (Join-Path $Directory "originals/$key-v2.png")
 $dst=[System.Drawing.Bitmap]::new(1200,1600,[System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
 $canvas=[System.Drawing.Graphics]::FromImage($dst)
 $canvas.Clear([System.Drawing.Color]::Transparent)
 $canvas.SmoothingMode=[System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
 $brush=[System.Drawing.SolidBrush]::new([System.Drawing.Color]::FromArgb(255,$rgb[0],$rgb[1],$rgb[2]))
 $pen=[System.Drawing.Pen]::new([System.Drawing.Color]::Black,12)
 $path=[System.Drawing.Drawing2D.GraphicsPath]::new()
 $coords=if($key -eq 'verkhovye'){@(@(6,6),@(1193,6),@(1193,1430),@(1030,1593),@(169,1593),@(6,1430))}else{@(@(6,6),@(1193,6),@(1193,1190),@(599.5,1592),@(6,1190))}
 $points=[System.Drawing.PointF[]]@($coords|ForEach-Object {[System.Drawing.PointF]::new($_[0],$_[1])})
 $path.AddPolygon($points)
 $canvas.FillPath($brush,$path);$canvas.DrawPath($pen,$path)
 $canvas.Dispose();$brush.Dispose();$pen.Dispose();$path.Dispose()
 $sourcePixels=Read-Pixels $src
 $pixels=Read-Pixels $dst
 $dx=[int][Math]::Floor((1200-$src.Width)/2)
 $dy=[int][Math]::Floor((1600-$src.Height)/2)
 $copied=[FlatCityArtwork]::Copy($sourcePixels,$src.Width,$src.Height,$pixels,1200,1600,$dx,$dy,$rgb[0],$rgb[1],$rgb[2])
 $holes=[FlatCityArtwork]::InternalHoles($pixels,1200,1600)
 if($holes -ne 0){throw "Internal transparent holes: $holes"}
 $bits=$dst.LockBits([System.Drawing.Rectangle]::new(0,0,1200,1600),[System.Drawing.Imaging.ImageLockMode]::WriteOnly,[System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
 try{[System.Runtime.InteropServices.Marshal]::Copy($pixels,0,$bits.Scan0,$pixels.Length)}finally{$dst.UnlockBits($bits)}
 $output=Join-Path $Directory "$key-v2.png"
 $dst.Save($output,[System.Drawing.Imaging.ImageFormat]::Png)
 $results+=@{file=$output;width=1200;height=1600;internalTransparentPixels=$holes;preservedPixels=$copied;sourceWidth=$src.Width;sourceHeight=$src.Height}
 $dst.Dispose();$src.Dispose()
}
# Rebuild the overview with only the two requested cities replaced.
$items=@(@('questpost','Квестпост'),@('verkhovye-v2','Верховье'),@('zelenolesye','Зеленолесье'),@('arah-v2','Оазис Арах'),@('hillsgaard','Хиллсгард'),@('grim','Грим'))
$preview=[System.Drawing.Bitmap]::new(1290,1220,[System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
$canvas=[System.Drawing.Graphics]::FromImage($preview)
$canvas.Clear([System.Drawing.Color]::FromArgb(225,223,217))
$canvas.InterpolationMode=[System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
$canvas.TextRenderingHint=[System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
$font=[System.Drawing.Font]::new('Segoe UI',25,[System.Drawing.FontStyle]::Bold,[System.Drawing.GraphicsUnit]::Pixel)
$brush=[System.Drawing.SolidBrush]::new([System.Drawing.Color]::FromArgb(38,39,42))
$format=[System.Drawing.StringFormat]::new();$format.Alignment=[System.Drawing.StringAlignment]::Center
for($index=0;$index -lt $items.Count;$index++){
 $col=$index%3;$row=[Math]::Floor($index/3)
 $canvas.DrawString($items[$index][1],$font,$brush,[System.Drawing.RectangleF]::new($col*430,14+$row*610,430,40),$format)
 $image=[System.Drawing.Bitmap]::new((Join-Path $Directory ($items[$index][0]+'.png')))
 $canvas.DrawImage($image,[System.Drawing.Rectangle]::new(20+$col*430,65+$row*610,390,520));$image.Dispose()
}
$preview.Save((Join-Path $Directory 'overview-v2.png'),[System.Drawing.Imaging.ImageFormat]::Png)
$canvas.Dispose();$preview.Dispose();$font.Dispose();$brush.Dispose();$format.Dispose()
$results|ConvertTo-Json -Depth 5

