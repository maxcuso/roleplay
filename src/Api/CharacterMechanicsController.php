<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Character\Moderation\CharacterMechanics;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

/** GET — открыть редактор; POST — проверить форму; PUT — сохранить существующего персонажа. */
final class CharacterMechanicsController implements RequestHandlerInterface
{
    public function __construct(private readonly CharacterMechanics $mechanics) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::view($actor);
        try {
            $id = CatalogRequest::positive($request->getQueryParams()['id'] ?? null, 'Персонаж');
            $data = match ($request->getMethod()) {
                'GET' => $this->mechanics->read($id, $actor),
                'POST' => $this->mechanics->preview($id, $actor, $request->getParsedBody()),
                'PUT' => $this->mechanics->save($id, $actor, $request->getParsedBody()),
                default => throw new ApiProblem('invalid_request', 'Не удалось определить действие. Попробуйте обновить страницу', 400),
            };
            if (isset($data['draft'])) {
                foreach (['base_attributes', 'loadout'] as $key) $data['draft']['payload'][$key] = (object) $data['draft']['payload'][$key];
            }
            return ApiResponse::data($data);
        } catch (ApiProblem $problem) {return ApiResponse::problem($problem);}
    }
}
