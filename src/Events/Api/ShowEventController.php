<?php

namespace Maxcuso\Roleplay\Events\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Maxcuso\Roleplay\Events\QuestEvent;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ShowEventController implements RequestHandlerInterface
{
    public function __construct(private EventData $data)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('viewForum');
        $id = filter_var($request->getAttribute('routeParameters', [])['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        if ($id === false) return new JsonResponse(['error' => 'Некорректный идентификатор события'], 400);

        $event = \Maxcuso\Roleplay\Events\EventAccess::scope(QuestEvent::query(), $actor)->with(['leader', 'participants'])->withCount('participants')->find($id);
        if ($event === null) return new JsonResponse(['error' => 'Событие не найдено'], 404);
        return new JsonResponse(['data' => $this->data->serialize($event, $actor)]);
    }
}

