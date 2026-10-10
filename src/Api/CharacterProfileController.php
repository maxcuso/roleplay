<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
use Maxcuso\Roleplay\Character\Application\ApplicationTopics;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Контроллер только разбирает транспорт. Правила видимости живут в CharacterVisibility. */
final class CharacterProfileController implements RequestHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db, private readonly ApplicationTopics $topics) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::view($actor);
        try {
            $query = $request->getQueryParams();
            FieldValues::allowed($query, ['id', 'userId', 'before']);
            $profile = new CharacterProfile($this->db, $this->topics);
            if (isset($query['userId'])) {
                $userId = CatalogRequest::positive($query['userId'], 'Пользователь');
                $before = isset($query['before']) ? CatalogRequest::positive($query['before'], 'Курсор') : null;
                return ApiResponse::data($profile->list($userId, $before, $actor));
            }
            return ApiResponse::data($profile->read(CatalogRequest::positive($query['id'] ?? null, 'Персонаж'), $actor));
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}