<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Character\Moderation\CharacterIdentity;
use Maxcuso\Roleplay\Character\Moderation\CharacterReview;
use Maxcuso\Roleplay\Character\Profile\CharacterProfile;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CharacterReviewController implements RequestHandlerInterface
{
    public function __construct(
        private readonly CharacterReview $review,
        private readonly CharacterIdentity $identity,
        private readonly CharacterProfile $profile,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::view($actor);
        try {
            $query = $request->getQueryParams();
            $id = CatalogRequest::positive($query['id'] ?? null, 'Персонаж');
            if ($request->getMethod() === 'PUT') {
                $this->identity->correct($id, $actor, $request->getParsedBody());
            } elseif ($request->getMethod() === 'POST') {
                $this->review->change($id, $actor, $request->getParsedBody());
            } else {
                throw new ApiProblem('invalid_request', 'Не удалось определить действие. Попробуйте обновить страницу', 400);
            }
            return ApiResponse::data($this->profile->read($id, $actor));
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}
