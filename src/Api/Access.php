<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\User\User;
use Flarum\User\Exception\PermissionDeniedException;

/** Общие границы доступа. Полномочия проверяются сервером при каждом запросе. */
final class Access
{
    public static function view(User $actor): void
    {
        $actor->assertCan('viewForum');
        $actor->assertCan('roleplay.view');
    }

    public static function create(User $actor): void
    {
        // Гость не может владеть черновиком, даже если группе дали право создания.
        if (!$actor->exists) throw new PermissionDeniedException();
        self::view($actor);
        $actor->assertCan('roleplay.create');
    }

    public static function admin(User $actor): void
    {
        $actor->assertAdmin();
    }

    /** Модератор создаёт выдаваемые сущности, но не получает управление остальными справочниками. */
    public static function catalogCreate(User $actor): void
    {
        if (!$actor->exists) throw new PermissionDeniedException();
        self::view($actor);
        if (!$actor->can('roleplay.moderate')
            && !\Maxcuso\Roleplay\Workspace\WorkspaceAccess::canCatalog($actor, 'create')) {
            throw new PermissionDeniedException();
        }
    }

    public static function catalogImage(User $actor): void
    {
        if (\Maxcuso\Roleplay\Workspace\WorkspaceAccess::canCatalog($actor, 'edit')) return;
        self::catalogCreate($actor);
    }
}
