<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Workspace;

use Flarum\User\User;
use Flarum\User\Exception\PermissionDeniedException;

/** Права рабочего пространства не дают доступ к административной панели. */
final class WorkspaceAccess
{
    public const CATALOGS = ['perks', 'spells', 'items'];

    public static function canView(User $actor): bool
    {
        return $actor->exists && $actor->can('viewForum') && $actor->can('roleplay.view')
            && $actor->can('roleplay.workspace');
    }

    public static function assertView(User $actor): void
    {
        if (!self::canView($actor)) throw new PermissionDeniedException();
    }

    public static function canCatalog(User $actor, string $action): bool
    {
        return in_array($action, ['view', 'create', 'edit', 'delete'], true)
            && self::canView($actor) && $actor->can('roleplay.catalog.view')
            && ($action === 'view' || $actor->can('roleplay.catalog.'.$action));
    }

    public static function catalog(User $actor, string $key, string $action): void
    {
        if (!in_array($key, self::CATALOGS, true) || !self::canCatalog($actor, $action)) {
            throw new PermissionDeniedException();
        }
    }

    /** Админка сохраняет прежние права, рабочее пространство ограничено тремя каталогами. */
    public static function store(User $actor, string $key, string $action): void
    {
        if ($actor->isAdmin()) return;
        self::catalog($actor, $key, $action);
    }

    public static function capabilities(User $actor): array
    {
        $result = [];
        foreach (['view', 'create', 'edit', 'delete'] as $action) $result[$action] = self::canCatalog($actor, $action);
        return $result;
    }
}
