<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Character\Application\ApplicationTopics;
use Maxcuso\Roleplay\Character\Moderation\CharacterManagement;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CharacterManagementController implements RequestHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db, private readonly ApplicationTopics $topics) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::view($actor);
        try {
            $id = CatalogRequest::positive($request->getQueryParams()['id'] ?? null, 'Персонаж');
            $service = new CharacterManagement($this->db);
            if ($request->getMethod() === 'GET') return ApiResponse::data($service->lookups($id, $actor));
            if ($request->getMethod() !== 'POST') throw new ApiProblem('invalid_request', 'Неизвестная операция.', 400);
            $service->change($id, $actor, $request->getParsedBody());
            return ApiResponse::data((new CharacterProfile($this->db, $this->topics))->read($id, $actor));
        } catch (ApiProblem $problem) {return ApiResponse::problem($problem);}
    }
}