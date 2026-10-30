<?php

namespace Maxcuso\Roleplay\Events\Api;

use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Maxcuso\Roleplay\Events\QuestEvent;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ListEventsController implements RequestHandlerInterface
{
    public function __construct(private ManagedEventData $serializer)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertCan('viewForum');
        $actor->assertCan('quest-events.manage');
        $before = $request->getQueryParams()['before'] ?? null;
        if ($before !== null && filter_var($before, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            return new JsonResponse(['error' => 'Некорректная страница'], 400);
        }

        $events = \Maxcuso\Roleplay\Events\EventAccess::scope(QuestEvent::query(), $actor)->orderBy('id', 'desc')
            ->when($before !== null, fn ($query) => $query->where('id', '<', $before))
            ->withCount('participants')
            ->with(['leader', 'participants'])
            ->limit(26)
            ->get();

        $data = [];

        $hasMore = $events->count() > 25;
        foreach ($events->take(25) as $event) {
            $data[] = $this->serializer->serialize($event, $actor);
        }

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'count' => count($data),
                'limit' => 25,
                'nextBefore' => $hasMore ? end($data)['id'] : null,
            ],
        ]);
    }
}

