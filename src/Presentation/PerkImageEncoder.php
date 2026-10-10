<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Presentation;

use enshrined\svgSanitize\Sanitizer;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Загружаемая SVG очищается, растровое изображение перекодируется. Исходный файл не публикуется. */
final class PerkImageEncoder
{
    public const MAX_BYTES = 5242880;

    public function encode(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) FieldValues::invalid('Выбери изображение не более 5 МБ.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (in_array($mime, ['image/png', 'image/webp'], true)) return $this->raster($bytes, $mime);
        if (preg_match('/<!DOCTYPE|<!ENTITY|<\?(?!xml\s)/i', $bytes)) FieldValues::invalid('Недопустимое содержимое SVG.');
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument();
            $valid = $doc->loadXML($bytes, LIBXML_NONET) && $doc->doctype === null
                && $doc->documentElement?->localName === 'svg'
                && $doc->documentElement?->namespaceURI === 'http://www.w3.org/2000/svg';
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$valid) FieldValues::invalid('Допустимы SVG, PNG и WebP.');
        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);
        $clean = $sanitizer->sanitize($bytes);
        if (!$clean) FieldValues::invalid('Не удалось обработать SVG.');
        // Некоторые SVG используют href без пространства имён xlink.
        // Библиотека очищает код; дополнительно исключаем внешние ссылки в обоих вариантах атрибута.
        $safe = new \DOMDocument();
        $safe->loadXML($clean, LIBXML_NONET);
        foreach ($safe->getElementsByTagName('*') as $element) {
            for ($index = $element->attributes->length - 1; $index >= 0; --$index) {
                $attribute = $element->attributes->item($index);
                if ($attribute->localName === 'href' && !str_starts_with(trim($attribute->value), '#')) {
                    $element->removeAttributeNode($attribute);
                }
            }
        }
        return [$safe->saveXML(), 'svg'];
    }

    private function raster(string $bytes, string $mime): array
    {
        $size = @getimagesizefromstring($bytes);
        if (!$size || $size[0] > 4096 || $size[1] > 4096) FieldValues::invalid('Изображение должно быть не больше 4096 × 4096.');
        $image = @imagecreatefromstring($bytes);
        if (!$image) FieldValues::invalid('Не удалось прочитать изображение.');
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        try {
            $ok = $mime === 'image/png' ? imagepng($image) : imagewebp($image, null, 90);
            $result = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (!$ok) FieldValues::invalid('Не удалось обработать изображение.');
        return [$result, $mime === 'image/png' ? 'png' : 'webp'];
    }
}