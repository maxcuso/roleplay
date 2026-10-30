<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Notification;

use Flarum\Api\Resource\AbstractDatabaseResource;
use Illuminate\Database\Eloquent\Builder;
use Tobyz\JsonApiServer\Context;

/** Только идентификатор subject. Закрытый payload журнала через JSON:API не публикуется. */
final class CharacterEventResource extends AbstractDatabaseResource
{
    public function type(): string { return 'roleplay-events'; }
    public function model(): string { return CharacterEvent::class; }
    public function scope(Builder $query, Context $context): void { $query->whereVisibleTo($context->getActor()); }
    public function fields(): array { return []; }
    public function endpoints(): array { return []; }
}
