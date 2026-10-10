<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Повторно используем валидацию админки. Здесь доступны только форма и создание, без изменения существующих записей. */
final class ManagementCatalogController implements RequestHandlerInterface
{
    public function __construct(private CatalogRegistry $catalogs) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::catalogCreate($actor);
        try {
            $key = FieldValues::text($request->getQueryParams()['catalog'] ?? null, 'Справочник', 32, true);
            if ($request->getMethod() === 'GET') return ApiResponse::data($this->catalogs->creationForm($key, $actor));
            if ($request->getMethod() !== 'POST') throw new ApiProblem('invalid_request', 'Неизвестная операция.', 400);
            $body = CatalogRequest::body($request->getParsedBody());
            FieldValues::allowed($body, ['revision', 'fields']);
            if (!is_array($body['fields'] ?? null)) FieldValues::invalid('Нужен объект полей формы.');
            return ApiResponse::data($this->catalogs->createForModerator($key,
                CatalogRequest::positive($body['revision'] ?? null, 'Версия'), $body['fields'], $actor), 201);
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}
