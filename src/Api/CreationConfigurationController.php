<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;
use Maxcuso\Roleplay\Creation\CreationConfigurationStore;
use Maxcuso\Roleplay\Creation\CreationRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Серверная настройка для будущего экрана админки. Игрок не может менять наборы и стартовые балансы. */
final class CreationConfigurationController implements RequestHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::admin($actor);
        try {
            $store = new CreationConfigurationStore($this->db);
            if ($request->getMethod() === 'GET') {
                return ApiResponse::data((new CatalogRevision($this->db))->read(fn (int $revision) =>
                    ['revision' => $revision, 'configuration' => $store->read(),
                        'lookups' => [
                            'items' => (new \Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry($this->db))->definitionsFor('items'),
                            'sources' => (new \Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry($this->db))->definitionsFor('sources'),
                        ]]));
            }
            if ($request->getMethod() !== 'PUT') throw new ApiProblem('invalid_request', 'Не удалось определить действие. Попробуйте обновить страницу', 400);
            $body = CreationRequest::body($request->getParsedBody(), ['revision', 'configuration']);
            if (!is_array($body['configuration'] ?? null)) throw new ApiProblem('invalid_request', 'Не удалось обработать настройки: ожидается объект JSON. Попробуйте обновить страницу', 400);
            $tagId = $body['configuration']['application']['tag_id'] ?? null;
            if ($tagId !== null && !\Flarum\Tags\Tag::query()->where('id', $tagId)->exists()) {
                throw new ApiProblem('application_tag_missing', 'Раздел не найден. Обновите список и выберите доступный раздел');
            }
            return ApiResponse::data($store->save($actor, CreationRequest::revision($body['revision'] ?? null), $body['configuration']));
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}