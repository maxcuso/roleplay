<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Creation\CreateCharacter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CreateCharacterController implements RequestHandlerInterface
{
    public function __construct(private readonly CreateCharacter $create) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::create($actor);
        try {
            if ($request->getMethod() !== 'POST') {
                throw new ApiProblem('invalid_request', 'Нужен запрос создания.', 400);
            }
            return ApiResponse::data($this->create->create($actor, $request->getParsedBody()), 201);
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}