<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Character\Moderation\CharacterManagement;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CharacterManagementController implements RequestHandlerInterface
{
    public function __construct(private readonly CharacterManagement $management, private readonly CharacterProfile $profile) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::view($actor);
        try {
            $id = CatalogRequest::positive($request->getQueryParams()['id'] ?? null, 'Персонаж');
            if ($request->getMethod() === 'GET') return ApiResponse::data($this->management->lookups($id, $actor));
            if ($request->getMethod() !== 'POST') throw new ApiProblem('invalid_request', 'Не удалось определить действие. Попробуйте обновить страницу', 400);
            $this->management->change($id, $actor, $request->getParsedBody());
            return ApiResponse::data($this->profile->read($id, $actor));
        } catch (ApiProblem $problem) {return ApiResponse::problem($problem);}
    }
}
