<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Transfer\CatalogJsonTransfer;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

/** Только административный обмен JSON. Сохранение и связи выполняет общий сервис переноса. */
final class CatalogJsonController implements RequestHandlerInterface
{
    public function __construct(private CatalogJsonTransfer $transfer) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        Access::admin($actor);
        try {
            if ($request->getMethod() === 'GET') {
                $query = $request->getQueryParams();
                $catalog = FieldValues::text($query['catalog'] ?? null, 'Раздел', 32, true);
                $scope = FieldValues::text($query['scope'] ?? 'section', 'Объём экспорта', 16, true);
                unset($query['catalog'], $query['scope']);
                return ApiResponse::data($this->transfer->export($actor, $catalog, $scope, $query));
            }
            $body = CatalogRequest::body($request->getParsedBody());
            FieldValues::allowed($body, ['document', 'mode', 'revision', 'apply']);
            if (!is_array($body['document'] ?? null)) FieldValues::invalid('Укажите справочники в виде объекта JSON');
            return ApiResponse::data($this->transfer->import($actor, $body['document'],
                FieldValues::text($body['mode'] ?? 'skip', 'Режим', 16, true),
                CatalogRequest::positive($body['revision'] ?? null, 'Версия справочников'),
                FieldValues::boolean($body['apply'] ?? false, 'Применение')));
        } catch (ApiProblem $problem) { return ApiResponse::problem($problem); }
    }
}
