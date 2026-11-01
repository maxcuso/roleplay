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
        // Ни анонимный посетитель, ни общий аккаунт тестовых боёв не владеют анкетой.
        if (self::creationRequiresLogin($actor) || !$actor->is_email_confirmed) {
            throw new PermissionDeniedException();
        }
        self::view($actor);
        $actor->assertCan('roleplay.create');
    }

    /** Общий Guest определяется по ID, который хранит сама Арена; зависимости от имени нет. */
    public static function creationRequiresLogin(User $actor): bool
    {
        return !$actor->exists || (class_exists(\forumaker\Arena\ArenaGuestUser::class)
            && \forumaker\Arena\ArenaGuestUser::isGuestId((int) $actor->id));
    }

    public static function canCreate(User $actor): bool
    {
        return !self::creationRequiresLogin($actor) && $actor->is_email_confirmed
            && $actor->can('viewForum') && $actor->can('roleplay.view') && $actor->can('roleplay.create');
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
