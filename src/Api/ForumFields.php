<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Api\Context;
use Flarum\Api\Schema;
use Maxcuso\Roleplay\Character\Moderation\ReviewPermissions;

/** Подсказки для навигации. Каждая операция всё равно проверяет права на сервере. */
final class ForumFields
{
    public function __invoke(): array
    {
        return [
            Schema\Boolean::make('roleplayCanUseWorkspace')->get(fn ($model, Context $context) =>
                \Maxcuso\Roleplay\Workspace\WorkspaceAccess::canView($context->getActor())),
            Schema\Boolean::make('roleplayWorkspaceCatalogs')->get(fn ($model, Context $context) =>
                \Maxcuso\Roleplay\Workspace\WorkspaceAccess::canCatalog($context->getActor(), 'view')),
            Schema\Boolean::make('roleplayCanModerate')->get(fn ($model, Context $context) =>
                ReviewPermissions::staff($context->getActor())),
            Schema\Boolean::make('roleplayCanView')->get(fn ($model, Context $context) =>
                $context->getActor()->can('viewForum') && $context->getActor()->can('roleplay.view')),
            Schema\Boolean::make('roleplayCanCreate')->get(fn ($model, Context $context) =>
                $context->getActor()->exists && $context->getActor()->can('viewForum')
                && $context->getActor()->can('roleplay.view') && $context->getActor()->can('roleplay.create')),
        ];
    }
}
