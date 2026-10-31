<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Checks\{CheckAccess, CheckPlans, CheckRead};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

final class CheckController implements RequestHandlerInterface
{
    public function __construct(private CheckRead $reader, private CheckPlans $plans) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        try {
            $q = array_replace($request->getQueryParams(), $request->getAttribute('routeParameters', []));
            $discussion = isset($q['discussion_id']) && $q['discussion_id'] !== '' ? CatalogRequest::positive($q['discussion_id'], 'Тема') : null;
            CheckAccess::discussion($discussion, $actor);
            if ($request->getMethod() === 'POST') return ApiResponse::data($this->plans->prepare($actor, CatalogRequest::body($request->getParsedBody())));
            $reader = $this->reader;
            if (str_ends_with($request->getUri()->getPath(), '/targets')) return ApiResponse::data($reader->targets($actor, FieldValues::text($q['search'] ?? '', 'Поиск', 100) ?? '', min(100000, CatalogRequest::positive($q['page'] ?? 1, 'Страница'))));
            $parent = isset($q['request_id']) ? OperationRequest::key($q['request_id']) : null;
            if (isset($q['characterId'])) return ApiResponse::data($reader->character(CatalogRequest::positive($q['characterId'], 'Персонаж'), $actor, $discussion, $parent));
            return ApiResponse::data($reader->context($discussion, $actor, $parent));
        } catch (ApiProblem $problem) {return ApiResponse::problem($problem);}
    }
}
