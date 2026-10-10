<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Catalog\Attributes\AttributeIcons;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

/** Узкий запрос одной формы: пакетное сохранение иконок, без редактирования самих характеристик. */
final class AttributeIconsController implements RequestHandlerInterface
{
    public function __construct(private AttributeIcons $icons) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::admin($actor);
        try {
            if ($request->getMethod() === 'GET') return ApiResponse::data($this->icons->read($actor));
            $body = OperationRequest::body($request->getParsedBody(), ['revision', 'icons']);
            if (!is_array($body['icons'] ?? null)) throw new ApiProblem('invalid_request', 'Нужен список иконок.', 400);
            return ApiResponse::data($this->icons->save($actor, OperationRequest::revision($body['revision'] ?? null), $body['icons']));
        } catch (ApiProblem $problem) {return ApiResponse::problem($problem);}
    }
}
