<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Rules\DevelopmentConfigurationStore;
use Maxcuso\Roleplay\Catalog\Shared\{CatalogRegistry, CatalogRevision};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

final class DevelopmentConfigurationController implements RequestHandlerInterface
{
    public function __construct(private readonly ConnectionInterface $db) {}
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::admin($actor);
        try {
            $store = new DevelopmentConfigurationStore($this->db);
            if ($request->getMethod() === 'GET') return ApiResponse::data((new CatalogRevision($this->db))->read(function ($revision) use ($store) {
                $registry = new CatalogRegistry($this->db);
                return ['revision' => $revision, 'configuration' => $store->read()->values,
                    'lookups' => ['attributes' => $registry->definitionsFor('attributes'), 'proficiencies' => $registry->definitionsFor('proficiencies')]];
            }));
            $body = OperationRequest::body($request->getParsedBody(), ['revision', 'configuration']);
            if (!is_array($body['configuration'] ?? null)) throw new ApiProblem('invalid_request', 'Не удалось обработать настройки развития: ожидается объект JSON. Попробуйте обновить страницу');
            return ApiResponse::data($store->save($actor, OperationRequest::revision($body['revision'] ?? null), $body['configuration']));
        } catch (ApiProblem $problem) {return ApiResponse::problem($problem);}
    }
}
