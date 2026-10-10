<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** HTTP-операции общего контракта. Контроллер не знает таблиц и игровых полей. */
final class AdminCatalogController implements RequestHandlerInterface
{
    public function __construct(private CatalogRegistry $catalogs) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::admin($actor);
        try {
            $query = $request->getQueryParams();
            $key = FieldValues::text($query['catalog'] ?? null, 'Справочник', 32, true);
            $service = $this->catalogs->get($key);
            if ($request->getMethod() === 'GET') {
                [$search, $enabled, $page, $filters] = CatalogRequest::listing($query);
                return ApiResponse::data($this->catalogs->list($key, $actor, $search, $enabled, $page, $filters));
            }
            $body = CatalogRequest::body($request->getParsedBody());
            $revision = CatalogRequest::positive($body['revision'] ?? null, 'Версия');
            $id = $request->getMethod() === 'POST' ? null : CatalogRequest::positive($query['id'] ?? null, 'Запись');
            switch ($request->getMethod()) {
                case 'POST':
                case 'PUT':
                    FieldValues::allowed($body, ['revision', 'fields']);
                    if (!is_array($body['fields'] ?? null)) FieldValues::invalid('Нужен объект полей формы.');
                    $result = $service->save($id, $revision, $body['fields'], $actor);
                    break;
                case 'PATCH':
                    FieldValues::allowed($body, ['revision', 'enabled']);
                    if ($id === null) FieldValues::invalid('Не указана запись.');
                    $result = $service->toggle($id, $revision, FieldValues::boolean($body['enabled'] ?? null, 'Статус'), $actor);
                    break;
                case 'DELETE':
                    FieldValues::allowed($body, ['revision']);
                    if ($id === null) FieldValues::invalid('Не указана запись.');
                    $result = $service->remove($id, $revision, $actor);
                    break;
                default:
                    throw new ApiProblem('invalid_request', 'Неизвестная операция.', 400);
            }
            return ApiResponse::data($result, $request->getMethod() === 'POST' ? 201 : 200);
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}