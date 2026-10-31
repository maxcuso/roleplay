param([string]$Directory=$PSScriptRoot)
$ErrorActionPreference='Stop'
Add-Type -AssemblyName System.Drawing
$root=[System.IO.Path]::GetFullPath((Join-Path $Directory '../..'))
$manifest=Get-Content -LiteralPath (Join-Path $root 'manifest.json') -Raw|ConvertFrom-Json
$columns=5;$cellWidth=300;$cellHeight=450;$headerHeight=50
$rows=[int][Math]::Ceiling($manifest.entries.Count/$columns)
$sheet=[System.Drawing.Bitmap]::new($columns*$cellWidth,$rows*$cellHeight+$headerHeight)
$g=[System.Drawing.Graphics]::FromImage($sheet)
$g.Clear([System.Drawing.Color]::FromArgb(229,227,220))
$g.InterpolationMode=[System.Drawing.Drawing2D.InterpolationMode]::HighQualityBicubic
$g.TextRenderingHint=[System.Drawing.Text.TextRenderingHint]::AntiAliasGridFit
$font=[System.Drawing.Font]::new('Segoe UI',18,[System.Drawing.FontStyle]::Bold,[System.Drawing.GraphicsUnit]::Pixel)
$title=[System.Drawing.Font]::new('Segoe UI',24,[System.Drawing.FontStyle]::Bold,[System.Drawing.GraphicsUnit]::Pixel)
$format=[System.Drawing.StringFormat]::new();$format.Alignment=[System.Drawing.StringAlignment]::Center
$g.DrawString('Города и организации',$title,[System.Drawing.Brushes]::Black,[System.Drawing.RectangleF]::new(0,8,$sheet.Width,40),$format)
for($i=0;$i -lt $manifest.entries.Count;$i++){
 $item=$manifest.entries[$i];$x=($i%$columns)*$cellWidth;$y=[Math]::Floor($i/$columns)*$cellHeight+$headerHeight
 $label=$item.name.Replace(' — род ',[Environment]::NewLine)
 $g.DrawString($label,$font,[System.Drawing.Brushes]::Black,[System.Drawing.RectangleF]::new($x+8,$y+5,$cellWidth-16,66),$format)
 $image=[System.Drawing.Bitmap]::new((Join-Path $root ('src/'+$item.file)))
 $g.DrawImage($image,[System.Drawing.Rectangle]::new($x+15,$y+75,270,360))
 $image.Dispose()
}
$output=Join-Path $root 'overview-2026-10-31.png'
$sheet.Save($output,[System.Drawing.Imaging.ImageFormat]::Png)
$g.Dispose();$sheet.Dispose();$font.Dispose();$title.Dispose();$format.Dispose()
Write-Output $output
