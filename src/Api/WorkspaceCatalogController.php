<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Workspace\WorkspaceAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Узкий доступ к существующим редакторам вместо открытия административных маршрутов. */
final class WorkspaceCatalogController implements RequestHandlerInterface
{
    public function __construct(private CatalogRegistry $catalogs) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        WorkspaceAccess::assertView($actor);
        try {
            $query = array_replace($request->getQueryParams(), $request->getAttribute('routeParameters', []));
            $key = FieldValues::text($query['catalog'] ?? null, 'Справочник', 32, true);
            $method = $request->getMethod();
            $action = match ($method) {
                'GET' => 'view', 'POST' => 'create', 'PUT', 'PATCH' => 'edit', 'DELETE' => 'delete',
                default => throw new ApiProblem('invalid_request', 'Не удалось определить действие. Попробуйте обновить страницу', 400),
            };
            WorkspaceAccess::catalog($actor, $key, $action);
            if ($method === 'GET') {
                [$search, $enabled, $page, $filters] = CatalogRequest::listing($query);
                return ApiResponse::data($this->catalogs->listForWorkspace($key, $actor, $search, $enabled, $page, $filters));
            }
            $body = CatalogRequest::body($request->getParsedBody());
            $revision = CatalogRequest::positive($body['revision'] ?? null, 'Версия');
            $id = $method === 'POST' ? null : CatalogRequest::positive($query['id'] ?? null, 'Запись');
            $service = $this->catalogs->get($key);
            if ($method === 'DELETE') {
                FieldValues::allowed($body, ['revision']);
                $result = $service->remove($id, $revision, $actor);
            } elseif ($method === 'PATCH') {
                FieldValues::allowed($body, ['revision', 'enabled']);
                $result = $service->toggle($id, $revision, FieldValues::boolean($body['enabled'] ?? null, 'Статус'), $actor);
            } else {
                FieldValues::allowed($body, ['revision', 'fields']);
                if (!is_array($body['fields'] ?? null)) FieldValues::invalid('Не удалось обработать данные формы. Попробуйте обновить страницу');
                $result = $service->save($id, $revision, $body['fields'], $actor);
            }
            return ApiResponse::data($result, $method === 'POST' ? 201 : 200);
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}
