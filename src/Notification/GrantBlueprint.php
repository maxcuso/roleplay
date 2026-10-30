<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Notification;
final class GrantBlueprint extends CharacterBlueprint
{
    public static function getType(): string { return 'roleplayGrants'; }
}
