<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Контроллер только разбирает транспорт. Правила видимости живут в CharacterVisibility. */
final class CharacterProfileController implements RequestHandlerInterface
{
    public function __construct(private readonly CharacterProfile $profile) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::view($actor);
        try {
            $query = $request->getQueryParams();
            FieldValues::allowed($query, ['id', 'userId', 'before']);
            if (isset($query['userId'])) {
                $userId = CatalogRequest::positive($query['userId'], 'Пользователь');
                $before = isset($query['before']) ? CatalogRequest::positive($query['before'], 'Курсор') : null;
                return ApiResponse::data($this->profile->list($userId, $before, $actor));
            }
            return ApiResponse::data($this->profile->read(CatalogRequest::positive($query['id'] ?? null, 'Персонаж'), $actor));
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}
