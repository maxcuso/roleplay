<?php

declare(strict_types=1);

use Flarum\Extend;
use Flarum\Api\Resource\ForumResource;
use Maxcuso\Roleplay\Api\ForumFields;
use Maxcuso\Roleplay\Api\CharacterProfileController;
use Maxcuso\Roleplay\Api\CharacterEquipmentController;
use Maxcuso\Roleplay\Api\AdminCatalogController;
use Maxcuso\Roleplay\Api\AdminCharacterController;
use Maxcuso\Roleplay\Api\UploadPerkImageController;
use Maxcuso\Roleplay\Api\CreationDraftController;
use Maxcuso\Roleplay\Api\CreateCharacterController;
use Maxcuso\Roleplay\Api\CreationConfigurationController;

// Здесь только интеграция. Правила и работа с данными находятся в подсистемах src/.
// Обе сборки сейчас цельные, без отложенных чанков. Не добавляем jsDirectory:
// для отсутствующей папки Flarum создаёт пустой каталог с закрытыми правами,
// если кеш очищает root, и следующая пересборка через веб-сервер падает.
return [
    (new Extend\ServiceProvider())->register(\Maxcuso\Roleplay\RoleplayServiceProvider::class),
    new \Maxcuso\Roleplay\Events\Module(),
    ...(require __DIR__.'/resources/checks.php'),
    (new Extend\Event())
        ->listen(\Flarum\Post\Event\Posted::class, \Maxcuso\Roleplay\Character\Application\SynchronizeLiteratureEvent::class)
        ->listen(\Flarum\Post\Event\Revised::class, \Maxcuso\Roleplay\Character\Application\SynchronizeLiteratureEvent::class)
        ->listen(\Flarum\Post\Event\Hidden::class, \Maxcuso\Roleplay\Character\Application\SynchronizeLiteratureEvent::class)
        ->listen(\Flarum\Post\Event\Restored::class, \Maxcuso\Roleplay\Character\Application\SynchronizeLiteratureEvent::class)
        ->listen(\Flarum\Post\Event\Deleted::class, \Maxcuso\Roleplay\Character\Application\SynchronizeLiteratureEvent::class)
        ->listen(\Flarum\Discussion\Event\Hidden::class, \Maxcuso\Roleplay\Character\Application\SynchronizeLiteratureEvent::class)
        ->listen(\Flarum\Discussion\Event\Restored::class, \Maxcuso\Roleplay\Character\Application\SynchronizeLiteratureEvent::class)
        ->listen(\Flarum\Lock\Event\DiscussionWasLocked::class, \Maxcuso\Roleplay\Character\Application\SynchronizeLiteratureEvent::class)
        ->listen(\Flarum\Lock\Event\DiscussionWasUnlocked::class, \Maxcuso\Roleplay\Character\Application\SynchronizeLiteratureEvent::class),
    new Extend\Locales(__DIR__.'/locale'),
    (new Extend\Notification())
        ->type(\Maxcuso\Roleplay\Notification\ReviewBlueprint::class, ['alert'])
        ->type(\Maxcuso\Roleplay\Notification\GrantBlueprint::class, ['alert'])
        ->type(\Maxcuso\Roleplay\Notification\RankBlueprint::class, ['alert']),
    new Extend\ApiResource(\Maxcuso\Roleplay\Notification\CharacterEventResource::class),
    (new Extend\ModelVisibility(\Maxcuso\Roleplay\Notification\CharacterEvent::class))
        ->scope(\Maxcuso\Roleplay\Notification\ScopeCharacterEvents::class),
    (new Extend\Frontend('admin'))
        ->js(__DIR__.'/js/dist/admin.js')
        ->css(__DIR__.'/resources/admin.css')
        ->css(__DIR__.'/resources/development.css')
        ->css(__DIR__.'/resources/catalog-editor.css')
        ->css(__DIR__.'/resources/application.css')
        ->css(__DIR__.'/resources/ranks.css')
        ->css(__DIR__.'/resources/cards.css')
        ->css(__DIR__.'/resources/modals.css'),
    (new Extend\Frontend('forum'))
        ->js(__DIR__.'/js/dist/forum.js')
        ->css(__DIR__.'/resources/cards.css')
        ->css(__DIR__.'/resources/forum.css')
        ->css(__DIR__.'/resources/creation.css')
        ->css(__DIR__.'/resources/application.css')
        ->css(__DIR__.'/resources/ranks.css')
        ->css(__DIR__.'/resources/catalog-editor.css')
        ->css(__DIR__.'/resources/management.css')
        ->css(__DIR__.'/resources/learning.css')
        ->css(__DIR__.'/resources/workspace.css')
        ->css(__DIR__.'/resources/episode-completion.css')
        ->css(__DIR__.'/resources/checks.css')
        ->css(__DIR__.'/resources/composer.css')
        ->css(__DIR__.'/resources/modals.css')
        ->css(__DIR__.'/resources/guide/style.less')
        ->css(__DIR__.'/resources/guide/forum.css')
        ->css(__DIR__.'/resources/update/style.css')
        ->route('/role', 'roleplay.guide')
        ->route('/roleplay/create', 'roleplay.creation')
        ->route('/u/{username}/moderation', 'roleplay.workspace', \Flarum\Forum\Content\User::class)
        ->route('/u/{username}/roleplay', 'roleplay.profile', \Flarum\Forum\Content\User::class),
    (new Extend\ApiResource(ForumResource::class))->fields(ForumFields::class),
    (new Extend\Policy())->modelPolicy(\Flarum\Post\Post::class, \Maxcuso\Roleplay\Character\Application\ApplicationPostPolicy::class),
    (new Extend\Filesystem())->disk('roleplay-images', function (\Flarum\Foundation\Paths $paths, \Flarum\Http\UrlGenerator $url): array {
        return [
            'root' => $paths->public.'/assets/roleplay',
            'url' => $url->to('forum')->path('assets/roleplay'),
            'visibility' => 'public',
        ];
    }),
    (new Extend\Routes('forum'))
        ->get('/role/update', 'roleplay.update', \Maxcuso\Roleplay\Guide\UpdateController::class)
        ->get('/role/media/{path:.+}/file', 'roleplay.guide.asset', \Maxcuso\Roleplay\Guide\GuideAssetController::class),
    (new Extend\Routes('api'))
        ->get('/roleplay/workspace/episodes', 'roleplay.workspace.episodes', \Maxcuso\Roleplay\Api\WorkspaceEpisodesController::class)
        ->get('/roleplay/workspace/catalogs/{catalog}', 'roleplay.workspace.catalog.list', \Maxcuso\Roleplay\Api\WorkspaceCatalogController::class)
        ->post('/roleplay/workspace/catalogs/{catalog}', 'roleplay.workspace.catalog.create', \Maxcuso\Roleplay\Api\WorkspaceCatalogController::class)
        ->put('/roleplay/workspace/catalogs/{catalog}/{id}', 'roleplay.workspace.catalog.save', \Maxcuso\Roleplay\Api\WorkspaceCatalogController::class)
        ->patch('/roleplay/workspace/catalogs/{catalog}/{id}', 'roleplay.workspace.catalog.status', \Maxcuso\Roleplay\Api\WorkspaceCatalogController::class)
        ->delete('/roleplay/workspace/catalogs/{catalog}/{id}', 'roleplay.workspace.catalog.remove', \Maxcuso\Roleplay\Api\WorkspaceCatalogController::class)
        ->get('/roleplay/guide', 'roleplay.guide.content', \Maxcuso\Roleplay\Guide\GuideController::class)
        ->get('/roleplay/users/{userId}/characters', 'roleplay.profile.list', CharacterProfileController::class)
        ->get('/roleplay/characters/{id}', 'roleplay.profile.show', CharacterProfileController::class)
        ->get('/roleplay/characters/{id}/events/{eventId}', 'roleplay.profile.event', \Maxcuso\Roleplay\Api\CharacterEventController::class)
        ->get('/roleplay/characters/{id}/mechanics', 'roleplay.mechanics.edit', \Maxcuso\Roleplay\Api\CharacterMechanicsController::class)
        ->post('/roleplay/characters/{id}/mechanics', 'roleplay.mechanics.preview', \Maxcuso\Roleplay\Api\CharacterMechanicsController::class)
        ->put('/roleplay/characters/{id}/mechanics', 'roleplay.mechanics.save', \Maxcuso\Roleplay\Api\CharacterMechanicsController::class)
        ->get('/roleplay/characters/{id}/management', 'roleplay.management.options', \Maxcuso\Roleplay\Api\CharacterManagementController::class)
        ->post('/roleplay/characters/{id}/management', 'roleplay.management.change', \Maxcuso\Roleplay\Api\CharacterManagementController::class)
        ->get('/roleplay/management/catalogs/{catalog}', 'roleplay.management.catalog.form', \Maxcuso\Roleplay\Api\ManagementCatalogController::class)
        ->post('/roleplay/management/catalogs/{catalog}', 'roleplay.management.catalog.create', \Maxcuso\Roleplay\Api\ManagementCatalogController::class)
        ->post('/roleplay/management/perk-image', 'roleplay.management.perks.image', UploadPerkImageController::class)
        ->post('/roleplay/characters/{id}/progression', 'roleplay.progression.change', \Maxcuso\Roleplay\Api\CharacterProgressionController::class)
        ->post('/roleplay/characters/{id}/equipment', 'roleplay.equipment.change', CharacterEquipmentController::class)
        ->post('/roleplay/characters/{id}/review', 'roleplay.review.change', \Maxcuso\Roleplay\Api\CharacterReviewController::class)
        ->put('/roleplay/characters/{id}/identity', 'roleplay.identity.correct', \Maxcuso\Roleplay\Api\CharacterReviewController::class)
        ->get('/roleplay/admin/application', 'roleplay.application.settings', \Maxcuso\Roleplay\Api\ApplicationSettingsController::class)
        ->put('/roleplay/admin/application', 'roleplay.application.configure', \Maxcuso\Roleplay\Api\ApplicationSettingsController::class)
        ->get('/roleplay/admin/attribute-icons', 'roleplay.admin.attribute-icons.show', \Maxcuso\Roleplay\Api\AttributeIconsController::class)
        ->put('/roleplay/admin/attribute-icons', 'roleplay.admin.attribute-icons.save', \Maxcuso\Roleplay\Api\AttributeIconsController::class)
        ->get('/roleplay/admin/catalog-json', 'roleplay.admin.catalog.export', \Maxcuso\Roleplay\Api\CatalogJsonController::class)
        ->post('/roleplay/admin/catalog-json', 'roleplay.admin.catalog.import', \Maxcuso\Roleplay\Api\CatalogJsonController::class)
        ->get('/roleplay/admin/catalogs/{catalog}', 'roleplay.admin.catalog.list', AdminCatalogController::class)
        ->post('/roleplay/admin/catalogs/{catalog}', 'roleplay.admin.catalog.create', AdminCatalogController::class)
        ->put('/roleplay/admin/catalogs/{catalog}/{id}', 'roleplay.admin.catalog.save', AdminCatalogController::class)
        ->patch('/roleplay/admin/catalogs/{catalog}/{id}', 'roleplay.admin.catalog.status', AdminCatalogController::class)
        ->delete('/roleplay/admin/catalogs/{catalog}/{id}', 'roleplay.admin.catalog.remove', AdminCatalogController::class)
        ->get('/roleplay/admin/characters', 'roleplay.admin.characters.list', AdminCharacterController::class)
        ->put('/roleplay/admin/characters/{id}', 'roleplay.admin.characters.save', AdminCharacterController::class)
        ->delete('/roleplay/admin/characters/{id}', 'roleplay.admin.characters.remove', AdminCharacterController::class)
        ->post('/roleplay/admin/perk-image', 'roleplay.admin.perks.image', UploadPerkImageController::class)
        ->get('/roleplay/admin/creation', 'roleplay.admin.creation.show', CreationConfigurationController::class)
        ->get('/roleplay/admin/development', 'roleplay.admin.development.show', \Maxcuso\Roleplay\Api\DevelopmentConfigurationController::class)
        ->put('/roleplay/admin/development', 'roleplay.admin.development.save', \Maxcuso\Roleplay\Api\DevelopmentConfigurationController::class)
        ->put('/roleplay/admin/creation', 'roleplay.admin.creation.save', CreationConfigurationController::class)
        ->get('/roleplay/creation/draft', 'roleplay.creation.draft.show', CreationDraftController::class)
        ->put('/roleplay/creation/draft', 'roleplay.creation.draft.save', CreationDraftController::class)
        ->post('/roleplay/creation/avatar', 'roleplay.creation.avatar', \Maxcuso\Roleplay\Api\UploadCharacterAvatarController::class)
        ->post('/roleplay/creation', 'roleplay.creation.create', CreateCharacterController::class),
];
