<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Notification;
final class ReviewBlueprint extends CharacterBlueprint
{
    public static function getType(): string { return 'roleplayReview'; }
}
