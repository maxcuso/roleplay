<?php

use Flarum\Extend;
use Flarum\Discussion\Event\Deleting;
use Flarum\Discussion\Event\Hidden;
use Flarum\Lock\Event\DiscussionWasLocked;
use Illuminate\Console\Scheduling\Event;
use Maxcuso\Roleplay\Events\Console\StartDueEventsCommand;
use Maxcuso\Roleplay\Events\Api\ListEventsController;
use Maxcuso\Roleplay\Events\Api\RegisterParticipantController;
use Maxcuso\Roleplay\Events\Api\LeaveEventController;
use Maxcuso\Roleplay\Events\Api\CreateEventController;
use Maxcuso\Roleplay\Events\Api\ListEventDraftsController;
use Maxcuso\Roleplay\Events\Api\ShowEventController;
use Maxcuso\Roleplay\Events\Api\ManageEventController;
use Maxcuso\Roleplay\Events\Listener\CompleteEventOnDiscussionChange;
use Maxcuso\Roleplay\Events\Formatter\ConfigureEventBbcode;
use Maxcuso\Roleplay\Events\Formatter\RenderEventBbcode;
use Maxcuso\Roleplay\Events\Listener\CreateEventsFromPost;
use Flarum\Api\Resource\DiscussionResource;
use Flarum\Post\Event\Saving as PostSaving;
use Flarum\Api\Resource\ForumResource;
use Flarum\Api\Schema;
use Flarum\Api\Context;

$frontend = new Extend\Frontend('forum');

$frontend->css(__DIR__ . '/forum.css');
$frontend->route('/quest-events', 'quest-events.page');

return [
    (new Extend\ServiceProvider())->register(\Maxcuso\Roleplay\Events\QuestEventsServiceProvider::class),
    $frontend,
    (new Extend\Settings())
        ->default(\Maxcuso\Roleplay\Events\QuestSettings::ENABLED, '1')
        ->serializeToForum('questEventsQuestsEnabled', \Maxcuso\Roleplay\Events\QuestSettings::ENABLED, fn ($value) => filter_var($value, FILTER_VALIDATE_BOOLEAN)),
    (new Extend\Frontend('admin'))
        ->css(__DIR__ . '/forum.css')
        ->css(__DIR__ . '/admin.css'),
    (new Extend\ApiResource(ForumResource::class))->fields(fn () => [
        Schema\Boolean::make('roleplayEventsAvailable')->get(fn () => true),
        Schema\Boolean::make('canCreateQuestEvents')
            ->get(fn ($model, Context $context) => $context->getActor()->can('quest-events.create')),
        Schema\Boolean::make('canCreateForumEvents')
            ->get(fn ($model, Context $context) => $context->getActor()->can('quest-events.create') || $context->getActor()->can('quest-events.createForum')),
        Schema\Boolean::make('canManageQuestEvents')
            ->get(fn ($model, Context $context) => $context->getActor()->can('quest-events.manage')),
    ]),

    (new Extend\ApiResource(ForumResource::class))->fields(\Maxcuso\Roleplay\Events\Api\IntegrationFields::class),

    (new Extend\ApiResource(DiscussionResource::class))
        ->fields(fn () => [Schema\Boolean::make('roleplayCanCompleteEpisode')->get(function ($model, Context $context): bool {
            $actor = $context->getActor();
            if (!$actor->exists || !$actor->can('roleplay.view') || !$actor->can('lock', $model)) return false;
            $event = \Maxcuso\Roleplay\Events\QuestEvent::where('discussion_id', $model->id)->where('event_type', 'quest')->first();
            return !$event || ($event->status === 'active' && !$model->is_locked);
        })]),

    (new Extend\Formatter())
        ->configure(ConfigureEventBbcode::class)
        ->render(RenderEventBbcode::class),
    (new Extend\Formatter())
        ->configure(\Maxcuso\Roleplay\Episodes\ConfigureCompletion::class)
        ->render(\Maxcuso\Roleplay\Episodes\RenderCompletion::class),

    (new Extend\Routes('api'))
        ->get('/roleplay/episodes/{discussionId}/completion', 'roleplay.episode.context', \Maxcuso\Roleplay\Api\EpisodeCompletionController::class)
        ->post('/roleplay/episodes/{discussionId}/completion', 'roleplay.episode.prepare', \Maxcuso\Roleplay\Api\EpisodeCompletionController::class)
        ->get('/roleplay/episodes/{discussionId}/completion/catalogs/{catalog}', 'roleplay.episode.catalog', \Maxcuso\Roleplay\Api\EpisodeCompletionController::class)
        ->get('/roleplay/episodes/{discussionId}/completion/characters/{characterId}', 'roleplay.episode.character', \Maxcuso\Roleplay\Api\EpisodeCompletionController::class)
        ->get('/quest-event-drafts', 'quest-events.drafts', ListEventDraftsController::class)
        ->get('/quest-events', 'quest-events.index', ListEventsController::class)
        ->get('/quest-events/{id}', 'quest-events.show', ShowEventController::class)
        ->post('/quest-events', 'quest-events.create', CreateEventController::class)
        ->patch('/quest-events/{id}', 'quest-events.update', ManageEventController::class)
        ->delete('/quest-events/{id}', 'quest-events.delete', ManageEventController::class)
        ->post('/quest-events/{id}/retry-start', 'quest-events.retry', \Maxcuso\Roleplay\Events\Api\EventModerationController::class)
        ->delete('/quest-events/{id}/participants/{userId:[0-9]+}', 'quest-events.remove-participant', \Maxcuso\Roleplay\Events\Api\EventModerationController::class)
        ->post('/quest-events/{id}/participants', 'quest-events.register', RegisterParticipantController::class)
        ->delete('/quest-events/{id}/participants/me', 'quest-events.leave', LeaveEventController::class),

    (new Extend\Event())
        ->listen(PostSaving::class, CreateEventsFromPost::class)
        ->listen(PostSaving::class, \Maxcuso\Roleplay\Episodes\PublishCompletion::class)
        ->listen(
            DiscussionWasLocked::class,
            CompleteEventOnDiscussionChange::class . '@onLocked'
        )
        ->listen(
            Hidden::class,
            CompleteEventOnDiscussionChange::class . '@onHidden'
        )
        ->listen(
            Deleting::class,
            CompleteEventOnDiscussionChange::class . '@onDeleting'
        ),

    (new Extend\Console())
        ->command(StartDueEventsCommand::class)
        ->schedule(StartDueEventsCommand::class, function (Event $event) {
            $event->everyMinute()->withoutOverlapping();
        }),
];

