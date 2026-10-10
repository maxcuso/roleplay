<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Character\Equipment\CharacterEquipment;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
use Maxcuso\Roleplay\Character\Application\ApplicationTopics;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CharacterEquipmentController implements RequestHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db, private readonly ApplicationTopics $topics) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::view($actor);
        try {
            $id = CatalogRequest::positive($request->getQueryParams()['id'] ?? null, 'Персонаж');
            (new CharacterEquipment($this->db))->change($id, $actor, $request->getParsedBody());
            // После повтора также отдаём актуальную анкету, а не устаревший снимок первой операции.
            return ApiResponse::data((new CharacterProfile($this->db, $this->topics))->read($id, $actor));
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}