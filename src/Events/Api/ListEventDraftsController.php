<?php

namespace Maxcuso\Roleplay\Events\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Maxcuso\Roleplay\Events\EventDrafts;
use Maxcuso\Roleplay\Events\EventAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListEventDraftsController implements RequestHandlerInterface
{
    public function __construct(private EventDrafts $drafts)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('viewForum');
        if (!$actor->can('quest-events.manage')) EventAccess::assertCreateAny($actor);
        return new JsonResponse([
            'data' => $this->drafts->listing($actor),
            'meta' => ['available' => $this->drafts->available($actor), 'limit' => EventDrafts::LIST_LIMIT],
        ]);
    }
}
