<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Character\Moderation\ReviewPermissions;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Прямая ссылка из колокольчика открывает и старую запись, за пределами последних 50 событий. */
final class CharacterEventController implements RequestHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::view($actor);
        try {
            $query = $request->getQueryParams();
            $id = CatalogRequest::positive($query['id'] ?? null, 'Персонаж');
            $eventId = CatalogRequest::positive($query['eventId'] ?? null, 'Событие');
            $character = $this->db->table('roleplay_characters')->where('id', $id)->first();
            if (!$character || !ReviewPermissions::owner((array) $character, $actor) && !ReviewPermissions::staff($actor)) {
                throw new ApiProblem('character_not_found', 'Персонаж не найден. Попробуйте обновить страницу', 404);
            }
            $event = $this->db->table('roleplay_character_events')->where('id', $eventId)->where('character_id', $id)
                ->whereIn('type', ['review_decided', 'character_managed'])->first();
            if (!$event) throw new ApiProblem('event_not_found', 'Запись истории недоступна', 404);
            return ApiResponse::data(['id' => (int) $event->id, 'type' => $event->type, 'actor_id' => (int) $event->actor_user_id,
                'created_at' => $event->created_at, 'details' => json_decode($event->payload, true, flags: JSON_THROW_ON_ERROR)]);
        } catch (ApiProblem $error) { return ApiResponse::problem($error); }
    }
}
