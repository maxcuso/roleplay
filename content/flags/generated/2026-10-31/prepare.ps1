param([string]$Directory=$PSScriptRoot,[string[]]$Keys=@())
$ErrorActionPreference='Stop'
# Canvas preparation only: generated artwork is copied without scaling.
$flagRoot=[System.IO.Path]::GetFullPath((Join-Path $Directory '../..'))
$helper=Get-Content -LiteralPath (Join-Path $flagRoot 'generated/cities-v1/normalize-v2.ps1') -Raw
$boundary=$helper.IndexOf('$entries=')
if($boundary -lt 0){throw 'Artwork helper unavailable'}
$setup=$helper.Substring(0,$boundary)
$setup=[regex]::Replace($setup,'\Aparam\([^\r\n]*\)\r?\n','')
$extra=@'
public static class FlatCityArtwork {
 public static double ExtraHue=double.NaN;
 public static bool LightField=false;
 public static double MainHue=0;
 public static void Prepare(byte[] s,int w,int h){
  int n=w*h,head=0,tail=0;var q=new int[n];var seen=new bool[n];var distance=new int[n];
  for(int i=0;i<n;i++){distance[i]=-1;int x=i%w,y=i/w;if(x==0||x==w-1||y==0||y==h-1){seen[i]=true;q[tail++]=i;}}
  while(head<tail){int p=q[head++],x=p%w,y=p/w;int[] ns={x>0?p-1:-1,x<w-1?p+1:-1,y>0?p-w:-1,y<h-1?p+w:-1};
   foreach(int v in ns)if(v>=0&&!seen[v]&&s[v*4+3]<128){seen[v]=true;q[tail++]=v;}}
  head=tail=0;
  for(int i=0;i<n;i++)if(seen[i]){distance[i]=0;q[tail++]=i;}
  while(head<tail){int p=q[head++];if(distance[p]>=14)continue;int x=p%w,y=p/w;int[] ns={x>0?p-1:-1,x<w-1?p+1:-1,y>0?p-w:-1,y<h-1?p+w:-1};
   foreach(int v in ns)if(v>=0&&distance[v]<0){distance[v]=distance[p]+1;q[tail++]=v;}}
  for(int y=0;y<h;y++)for(int x=0;x<w;x++){
   int i=(y*w+x)*4,hi=Math.Max(s[i],Math.Max(s[i+1],s[i+2]));
   if(hi>=100)continue;
   if(distance[y*w+x]>=0||x<24||x>=w-24||y<24||y>=h-24){s[i+3]=0;continue;}
   // A background dividing line is not part of the foreground charge.
   if(!double.IsNaN(ExtraHue)&&x>=8&&x<w-8&&y>=8&&y<h-8){
    int[] ns={y*w+x-8,y*w+x+8,(y-8)*w+x,(y+8)*w+x};
    bool field=true;foreach(int v in ns)if(s[v*4+3]<128||!BG(s,v*4,MainHue)){field=false;break;}
    if(field)s[i+3]=0;
   }
  }
 }
 public static void Alpha(byte[] shape,byte[] image){for(int i=3;i<shape.Length;i+=4)image[i]=shape[i];}
'@
$setup=$setup.Replace('public static class FlatCityArtwork {',$extra)
$old='return (hi-lo)/(double)hi>0.10 && Math.Min(dh,360-dh)<36;'
$new=@'
if(LightField && hi>90 && (hi-lo)/(double)hi<0.30)return true;
  double de=Math.Abs(Hue(r,g,b)-ExtraHue);
  return (hi-lo)/(double)hi>0.10 && (Math.Min(dh,360-dh)<36 || (!double.IsNaN(ExtraHue)&&Math.Min(de,360-de)<36));
'@
if(!$setup.Contains($old)){throw 'Unexpected artwork helper version'}
$setup=$setup.Replace($old,$new)
$old='if(tail<120)for(int j=0;j<tail;j++)mask[q[j]]=false;'
$new=@'
int minx=w,maxx=0,miny=h;
   for(int j=0;j<tail;j++){int x=q[j]%w,y=q[j]/w;minx=Math.Min(minx,x);maxx=Math.Max(maxx,x);miny=Math.Min(miny,y);}
   if(tail<120 || (maxx-minx>w*0.90&&miny>h*0.70))for(int j=0;j<tail;j++)mask[q[j]]=false;
'@
$setup=$setup.Replace($old,$new)
. ([scriptblock]::Create($setup))
$recipes=Get-Content -LiteralPath (Join-Path $Directory 'recipes.json') -Raw|ConvertFrom-Json
$results=@()
foreach($entry in $recipes){
 if($Keys.Count -gt 0 -and $entry.key -notin $Keys){continue}
 $source=[System.Drawing.Bitmap]::new($entry.source)
 if($source.Width -gt 1200 -or $source.Height -gt 1600){throw "Review oversized source: $($entry.key)"}
 $rawPath=Join-Path $Directory ($entry.key+'-original.png')
 Copy-Item -LiteralPath $entry.source -Destination $rawPath
 $dst=[System.Drawing.Bitmap]::new(1200,1600,[System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
 $g=[System.Drawing.Graphics]::FromImage($dst)
 $g.Clear([System.Drawing.Color]::Transparent)
 $g.SmoothingMode=[System.Drawing.Drawing2D.SmoothingMode]::AntiAlias
 $shape=[System.Drawing.Drawing2D.GraphicsPath]::new()
 if($entry.shape -eq 'rounded'){
  $shape.StartFigure()
  $shape.AddLine(6,6,1193,6);$shape.AddLine(1193,6,1193,1190)
  $shape.AddBezier(1193,1190,1193,1430,940,1592,599.5,1592)
  $shape.AddBezier(599.5,1592,259,1592,6,1430,6,1190)
  $shape.AddLine(6,1190,6,6);$shape.CloseFigure()
 }else{
  $coordinates=switch($entry.shape){
   'beveled' {@(@(6,6),@(1193,6),@(1193,1430),@(1030,1593),@(169,1593),@(6,1430))}
   'long-point' {@(@(6,6),@(1193,6),@(1193,1190),@(599.5,1592),@(6,1190))}
   'swallowtail' {@(@(6,6),@(1193,6),@(1193,1590),@(599.5,1280),@(6,1590))}
   default {@(@(6,6),@(1193,6),@(1193,1400),@(599.5,1592),@(6,1400))}
  }
  $points=[System.Drawing.PointF[]]@($coordinates|ForEach-Object {[System.Drawing.PointF]::new($_[0],$_[1])})
  $shape.AddPolygon($points)
 }
 $rgb=$entry.fields[0]
 $brush=[System.Drawing.SolidBrush]::new([System.Drawing.Color]::FromArgb(255,$rgb[0],$rgb[1],$rgb[2]))
 $g.FillPath($brush,$shape);$brush.Dispose()
 if($entry.fields.Count -gt 1){
  $rgb2=$entry.fields[1]
  $brush=[System.Drawing.SolidBrush]::new([System.Drawing.Color]::FromArgb(255,$rgb2[0],$rgb2[1],$rgb2[2]))
  $g.SetClip($shape)
  switch($entry.layout){
   'vertical' {$g.FillRectangle($brush,600,0,600,1600)}
   'horizontal' {$g.FillRectangle($brush,0,800,1200,800)}
   'diagonal' {$tri=[System.Drawing.PointF[]]@([System.Drawing.PointF]::new(1200,0),[System.Drawing.PointF]::new(1200,1600),[System.Drawing.PointF]::new(0,1600));$g.FillPolygon($brush,$tri)}
  }
  $g.ResetClip();$brush.Dispose()
  [FlatCityArtwork]::ExtraHue=[System.Drawing.Color]::FromArgb($rgb2[0],$rgb2[1],$rgb2[2]).GetHue()
 }else{[FlatCityArtwork]::ExtraHue=[double]::NaN}
 [FlatCityArtwork]::LightField=[bool]$entry.lightField
 $pen=[System.Drawing.Pen]::new([System.Drawing.Color]::Black,12)
 $g.DrawPath($pen,$shape)
 $g.Dispose();$pen.Dispose();$shape.Dispose()
 $s=Read-Pixels $source
 [FlatCityArtwork]::MainHue=[System.Drawing.Color]::FromArgb($rgb[0],$rgb[1],$rgb[2]).GetHue()
 [FlatCityArtwork]::Prepare($s,$source.Width,$source.Height)
 $d=Read-Pixels $dst
 $shapePixels=[byte[]]$d.Clone()
 $dx=[int][Math]::Floor((1200-$source.Width)/2)
 $dy=[int][Math]::Floor((1600-$source.Height)/2)+[int]$entry.shiftY
 $copied=[FlatCityArtwork]::Copy($s,$source.Width,$source.Height,$d,1200,1600,$dx,$dy,$rgb[0],$rgb[1],$rgb[2])
 if($copied -lt 10000){throw "Missing emblem: $($entry.key)"}
 [FlatCityArtwork]::Alpha($shapePixels,$d)
 if([FlatCityArtwork]::InternalHoles($d,1200,1600) -ne 0){throw "Alpha holes: $($entry.key)"}
 $bits=$dst.LockBits([System.Drawing.Rectangle]::new(0,0,1200,1600),[System.Drawing.Imaging.ImageLockMode]::WriteOnly,[System.Drawing.Imaging.PixelFormat]::Format32bppArgb)
 try{[System.Runtime.InteropServices.Marshal]::Copy($d,0,$bits.Scan0,$d.Length)}finally{$dst.UnlockBits($bits)}
 $target=Join-Path $flagRoot ('src/'+$entry.key+'.png')
 $dst.Save($target,[System.Drawing.Imaging.ImageFormat]::Png)
 $results+=@{key=$entry.key;name=$entry.name;file=$entry.key+'.png';width=1200;height=1600;preservedArtworkPixels=$copied;internalTransparentPixels=0}
 $source.Dispose();$dst.Dispose()
}
$results|ConvertTo-Json -Depth 5|Set-Content -LiteralPath (Join-Path $Directory 'verification.json') -Encoding utf8
$results|ForEach-Object { "$($_.key): $($_.preservedArtworkPixels) pixels, 1200x1600" }
