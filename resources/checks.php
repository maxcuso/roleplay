<?php

use Flarum\Extend;
use Flarum\Api\{Schema, Context};
use Flarum\Api\Resource\{ForumResource, PostResource, DiscussionResource};
use Maxcuso\Roleplay\Checks\{CheckAccess, ConfigureChecks, RenderChecks, PublishChecks};
use Maxcuso\Roleplay\Events\Api\TransactionalPostSave;
use Maxcuso\Roleplay\Api\CheckController;

return [
    // Общая транзакция публикации нужна и событиям, и наградам, и проверкам.
    (new Extend\ApiResource(PostResource::class))->endpoint(['create', 'update'], TransactionalPostSave::class),
    (new Extend\ApiResource(DiscussionResource::class))->endpoint('create', TransactionalPostSave::class),
    (new Extend\ApiResource(ForumResource::class))->fields(fn () => [
        Schema\Boolean::make('roleplayChecksAvailable')->get(fn ($model, Context $context) =>
            $context->getActor()->exists && $context->getActor()->can('roleplay.view') && CheckAccess::enabled()),
    ]),
    (new Extend\Formatter())->configure(ConfigureChecks::class)->render(RenderChecks::class),
    (new Extend\Event())->listen(\Flarum\Post\Event\Saving::class, PublishChecks::class),
    (new Extend\Routes('api'))
        ->get('/roleplay/checks/context', 'roleplay.checks.context', CheckController::class)
        ->get('/roleplay/checks/characters/{characterId}', 'roleplay.checks.character', CheckController::class)
        ->get('/roleplay/checks/targets', 'roleplay.checks.targets', CheckController::class)
        ->post('/roleplay/checks/plans', 'roleplay.checks.prepare', CheckController::class),
];
