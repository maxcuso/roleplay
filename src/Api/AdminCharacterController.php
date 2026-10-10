<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Character\Admin\CharacterAdmin;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class AdminCharacterController implements RequestHandlerInterface
{
    public function __construct(private CharacterAdmin $characters, private CatalogRegistry $catalogs) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::admin($actor);
        try {
            $query = $request->getQueryParams();
            if ($request->getMethod() === 'GET') {
                FieldValues::allowed($query, ['search', 'status', 'page']);
                $result = $this->characters->list($actor,
                    FieldValues::text($query['search'] ?? '', 'Поиск', 100) ?? '',
                    FieldValues::text($query['status'] ?? '', 'Статус', 16) ?? '',
                    CatalogRequest::positive($query['page'] ?? 1, 'Страница'));
                $result['lookups'] = $this->catalogs->references($actor);
                return ApiResponse::data($result);
            }
            if (!in_array($request->getMethod(), ['PUT', 'DELETE'], true)) throw new ApiProblem('invalid_request', 'Неизвестная операция.', 400);
            $body = CatalogRequest::body($request->getParsedBody());
            if ($request->getMethod() === 'DELETE') {
                FieldValues::allowed($body, ['revision']);
                return ApiResponse::data($this->characters->remove(
                    CatalogRequest::positive($query['id'] ?? null, 'Персонаж'),
                    CatalogRequest::positive($body['revision'] ?? null, 'Версия'), $actor));
            }
            FieldValues::allowed($body, ['revision', 'fields']);
            if (!is_array($body['fields'] ?? null)) FieldValues::invalid('Нужен объект полей формы.');
            return ApiResponse::data($this->characters->save(
                CatalogRequest::positive($query['id'] ?? null, 'Персонаж'),
                CatalogRequest::positive($body['revision'] ?? null, 'Версия'), $body['fields'], $actor));
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        } catch (\Illuminate\Database\QueryException $error) {
            if ((int) ($error->errorInfo[1] ?? 0) === 1062) {
                return ApiResponse::problem(new ApiProblem('discussion_already_linked', 'Тема уже привязана к другому персонажу.', 409));
            }
            throw $error;
        }
    }
}
