<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Guide;

use Flarum\Http\UrlGenerator;

/** Читаемый HTML хранится в расширении. Имена файлов задаются только в коде. */
final class GuideDocument
{
    public static function read(UrlGenerator $url, string $file): string
    {
        $forumUrl = htmlspecialchars(rtrim($url->to('forum')->base(), '/'), ENT_QUOTES);
        $html = file_get_contents(__DIR__.'/../../resources/'.$file);
        $html = str_replace('https://questpost.ru/', $forumUrl.'/', $html);
        // /file оставляет изображения под контролем маршрута PHP, в том числе за nginx.
        $html = preg_replace_callback('~"/role/assets/([^"<>]+)"~',
            fn ($match) => '"'.$forumUrl.'/role/media/'.$match[1].'/file"', $html);
        return preg_replace('~href="/role(?=[/#"])~', 'href="'.$forumUrl.'/role', $html);
    }
}
