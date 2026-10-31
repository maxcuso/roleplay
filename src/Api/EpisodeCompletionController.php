<?php

declare(strict_types=1);
namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Episodes\{CompletionAccess, CompletionPlans, CompletionRead};
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class EpisodeCompletionController implements RequestHandlerInterface
{
    public function __construct(private CompletionRead $reader, private CompletionPlans $plans) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        try {
            $query = array_replace($request->getQueryParams(), $request->getAttribute('routeParameters', []));
            $discussion = CatalogRequest::positive($query['discussionId'] ?? null, 'Тема');
            $context = CompletionAccess::context($discussion, $actor);
            $reader = $this->reader;
            if ($request->getMethod() === 'POST') return ApiResponse::data($this->plans->prepare($discussion, $actor, $request->getParsedBody()));
            if (isset($query['characterId'])) return ApiResponse::data($reader->character(CatalogRequest::positive($query['characterId'], 'Персонаж'), $actor, $context['event'] !== null));
            if (isset($query['catalog'])) return ApiResponse::data($reader->search(
                FieldValues::text($query['catalog'], 'Справочник', 32, true),
                FieldValues::text($query['search'] ?? '', 'Поиск', 100) ?? '', CatalogRequest::positive($query['page'] ?? 1, 'Страница'), $actor));
            return ApiResponse::data($reader->context($discussion, $actor));
        } catch (ApiProblem $problem) { return ApiResponse::problem($problem); }
    }
}
