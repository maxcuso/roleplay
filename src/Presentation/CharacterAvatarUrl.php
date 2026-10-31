<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Presentation;

use Flarum\Http\UrlGenerator;

/** Только URL собственных аватаров; кодирование изображения не зависит от Flarum. */
final class CharacterAvatarUrl
{
    public function __construct(private readonly UrlGenerator $urls) {}

    public function url(?string $filename): ?string
    {
        if (!$filename || !preg_match('/^avatar-[1-9][0-9]*-[0-9a-f]{32}\.webp$/D', $filename)) return null;
        return $this->urls->to('forum')->path('assets/roleplay/avatars/'.$filename);
    }
}
