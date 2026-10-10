<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Character\Application\ApplicationTopics;
use Maxcuso\Roleplay\Character\Moderation\CharacterIdentity;
use Maxcuso\Roleplay\Character\Moderation\CharacterReview;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CharacterReviewController implements RequestHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db, private readonly ApplicationTopics $topics) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::view($actor);
        try {
            $query = $request->getQueryParams();
            $id = CatalogRequest::positive($query['id'] ?? null, 'Персонаж');
            if ($request->getMethod() === 'PUT') {
                (new CharacterIdentity($this->db))->correct($id, $actor, $request->getParsedBody());
            } elseif ($request->getMethod() === 'POST') {
                (new CharacterReview($this->db, $this->topics))->change($id, $actor, $request->getParsedBody());
            } else {
                throw new ApiProblem('invalid_request', 'Неизвестная операция.', 400);
            }
            return ApiResponse::data((new CharacterProfile($this->db, $this->topics))->read($id, $actor));
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}