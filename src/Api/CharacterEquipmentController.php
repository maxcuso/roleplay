<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Character\Equipment\CharacterEquipment;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CharacterEquipmentController implements RequestHandlerInterface
{
    public function __construct(private readonly CharacterEquipment $equipment, private readonly CharacterProfile $profile) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::view($actor);
        try {
            $id = CatalogRequest::positive($request->getQueryParams()['id'] ?? null, 'Персонаж');
            $this->equipment->change($id, $actor, $request->getParsedBody());
            // После повтора также отдаём актуальную анкету, а не устаревший снимок первой операции.
            return ApiResponse::data($this->profile->read($id, $actor));
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}
