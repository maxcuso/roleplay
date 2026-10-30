<?php
// Выполняется из check-base-catalogs.php. Растры создаются в памяти, файлы форума не меняются.
use Maxcuso\Roleplay\Presentation\CharacterAvatar;
use Maxcuso\Roleplay\Creation\CreationPayload;

$avatarEncoder = new CharacterAvatar();
check((new CreationPayload())->normalize([])['avatar'] === null, 'Old drafts without avatars remain compatible');
$avatarName = 'avatar-1-'.str_repeat('a', 32).'.webp';
check((new CreationPayload())->normalize(['avatar' => $avatarName], 1)['avatar'] === $avatarName, 'Owned opaque avatar token is accepted');
foreach (['../../outside.webp', 'https://example.com/'.$avatarName, 'avatar-2-'.str_repeat('a', 32).'.webp', '<svg/>'] as $invalidAvatar) {
    rejected(fn () => (new CreationPayload())->normalize(['avatar' => $invalidAvatar], 1), 'invalid_creation_payload');
}
$image = imagecreatetruecolor(900, 600);
imagefill($image, 0, 0, imagecolorallocate($image, 255, 0, 0));
imagefilledrectangle($image, 150, 0, 749, 599, imagecolorallocate($image, 0, 255, 0));
ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
$webp = $avatarEncoder->encode($png);
$size = getimagesizefromstring($webp);
check($size[0] === 512 && $size[1] === 512 && $size['mime'] === 'image/webp', 'Upload is reencoded to square 512px WebP');
$cropped = imagecreatefromstring($webp);
$corner = imagecolorsforindex($cropped, imagecolorat($cropped, 0, 0));
imagedestroy($cropped);
check($corner['green'] > 200 && $corner['red'] < 30, 'Square crop takes the centre of the source');
foreach (['not an image', '<svg xmlns="http://www.w3.org/2000/svg"/>', str_repeat('a', CharacterAvatar::MAX_BYTES + 1)] as $invalidImage) {
    try { $avatarEncoder->encode($invalidImage); throw new RuntimeException('Unsafe image accepted'); }
    catch (\Maxcuso\Roleplay\Api\ApiProblem) { check(true, 'Invalid image is refused before publication'); }
}
$large = imagecreatetruecolor(4097, 1);
ob_start(); imagepng($large); $largePng = ob_get_clean(); imagedestroy($large);
try { $avatarEncoder->encode($largePng); throw new RuntimeException('Oversized raster accepted'); }
catch (\Maxcuso\Roleplay\Api\ApiProblem) { check(true, 'Oversized dimensions are refused before decoding'); }
