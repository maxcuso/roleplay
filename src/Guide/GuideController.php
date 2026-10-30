<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Guide;

use Flarum\Http\{RequestUtil, UrlGenerator};
use Maxcuso\Roleplay\Api\ApiResponse;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;

/** Статичный текст и публичные данные справочников; данные персонажей сюда не входят. */
final class GuideController implements RequestHandlerInterface
{
    public function __construct(private GuideCatalog $catalog, private UrlGenerator $url) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('viewForum');
        $html = GuideDocument::read($this->url, 'guide/content.html');
        return ApiResponse::data(['html' => $html] + $this->catalog->read());
    }
}
