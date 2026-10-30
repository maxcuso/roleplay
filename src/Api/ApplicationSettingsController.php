<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Flarum\Tags\Tag;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;
use Maxcuso\Roleplay\Creation\CreationConfigurationStore;
use Maxcuso\Roleplay\Character\Application\ApplicationConfiguration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Узкая форма меняет только настройки тем; остальные настройки создания сохраняются. */
final class ApplicationSettingsController implements RequestHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::admin($actor);
        try {
            $store = new CreationConfigurationStore($this->db);
            if ($request->getMethod() === 'GET') {
                return ApiResponse::data((new CatalogRevision($this->db))->read(fn (int $revision) => [
                    'revision' => $revision, 'application' => $store->read()['application'],
                    'tags' => Tag::query()->orderBy('name')->get()->map(fn ($tag) => [
                        'id' => (int) $tag->id, 'name' => $tag->name,
                    ])->all(),
                ]));
            }
            if ($request->getMethod() !== 'PUT') throw new ApiProblem('invalid_request', 'Не удалось определить действие. Попробуйте обновить страницу', 400);
            $body = OperationRequest::body($request->getParsedBody(), ['revision', 'application']);
            $application = (new ApplicationConfiguration())->normalize($body['application'] ?? null);
            if ($application['tag_id'] !== null && !Tag::query()->where('id', $application['tag_id'])->exists()) {
                throw new ApiProblem('application_tag_missing', 'Раздел не найден. Обновите список и выберите доступный раздел');
            }
            $configuration = (new CatalogRevision($this->db))->read(fn () => $store->read());
            $configuration['application'] = $application;
            return ApiResponse::data($store->save($actor, OperationRequest::revision($body['revision'] ?? null), $configuration));
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}