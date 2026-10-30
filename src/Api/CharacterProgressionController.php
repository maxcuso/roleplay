<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Character\Progression\CharacterProgression;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CharacterProgressionController implements RequestHandlerInterface
{
    public function __construct(private readonly CharacterProgression $progression, private readonly CharacterProfile $profile) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::view($actor);
        try {
            if ($request->getMethod() !== 'POST') throw new ApiProblem('invalid_request', 'Не удалось определить действие. Попробуйте обновить страницу', 400);
            $id = CatalogRequest::positive($request->getQueryParams()['id'] ?? null, 'Персонаж');
            $this->progression->change($id, $actor, $request->getParsedBody());
            return ApiResponse::data($this->profile->read($id, $actor));
        } catch (ApiProblem $problem) {return ApiResponse::problem($problem);}
    }
}
