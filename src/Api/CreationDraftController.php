<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Creation\CreationDraft;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class CreationDraftController implements RequestHandlerInterface
{
    public function __construct(private readonly CreationDraft $draft) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::create($actor);
        try {
            $data = match ($request->getMethod()) {
                'GET' => $this->draft->read($actor),
                'PUT' => $this->draft->save($actor, $request->getParsedBody()),
                default => throw new ApiProblem('invalid_request', 'Не удалось определить действие. Попробуйте обновить страницу', 400),
            };
            // Эти поля — карты с именованными ключами, даже когда пока пусты.
            // Внутренние PHP-планы используют массивы; на границе JSON нужен объект {}.
            foreach (['base_attributes', 'loadout'] as $field) {
                $data['draft']['payload'][$field] = (object) $data['draft']['payload'][$field];
            }
            return ApiResponse::data($data);
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}