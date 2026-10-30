<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Presentation;

use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Независимый от Arena обработчик: проверка, квадратная обрезка по центру, WebP. */
final class CharacterAvatar
{
    public const MAX_BYTES = 2097152;
    public const SIZE = 512;

    /** Только собственные обработанные файлы, без внешних URL и произвольных путей. */
    public static function url(?string $filename): ?string
    {
        if (!$filename || !preg_match('/^avatar-[1-9][0-9]*-[0-9a-f]{32}\.webp$/D', $filename)) return null;
        return resolve(\Flarum\Http\UrlGenerator::class)->to('forum')->path('assets/roleplay/avatars/'.$filename);
    }

    public static function normalize(mixed $filename, ?int $ownerId = null): ?string
    {
        if ($filename === null || $filename === '') return null;
        if (!is_string($filename) || !preg_match('/^avatar-([1-9][0-9]*)-[0-9a-f]{32}\.webp$/D', $filename, $matches)
            || ($ownerId !== null && (int) $matches[1] !== $ownerId)) {
            FieldValues::invalid('Для загрузки аватара войдите в аккаунт владельца персонажа');
        }
        return $filename;
    }

    public function encode(string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) FieldValues::invalid('Выберите изображение размером не более 2 МБ');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            FieldValues::invalid('Выберите изображение в формате JPG, PNG или WebP');
        }
        // Проверяем размеры до декодирования, чтобы большой растр не исчерпал память PHP.
        $size = @getimagesizefromstring($bytes);
        if (!$size || $size[0] > 4096 || $size[1] > 4096) FieldValues::invalid('Выберите изображение размером не более 4096 × 4096 пикселей');
        $source = @imagecreatefromstring($bytes);
        if (!$source) FieldValues::invalid('Не удалось прочитать изображение. Проверьте файл или выберите другое изображение');
        $target = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $edge = min($size[0], $size[1]);
        imagecopyresampled($target, $source, 0, 0, intdiv($size[0] - $edge, 2), intdiv($size[1] - $edge, 2),
            self::SIZE, self::SIZE, $edge, $edge);
        ob_start();
        try {
            $ok = imagewebp($target, null, 90);
            $result = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($source);
            imagedestroy($target);
        }
        if (!$ok || !is_string($result)) FieldValues::invalid('Не удалось обработать изображение. Попробуйте обновить страницу');
        return $result;
    }
}
