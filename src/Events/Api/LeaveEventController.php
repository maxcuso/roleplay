<?php

namespace Maxcuso\Roleplay\Events\Api;

use DomainException;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Maxcuso\Roleplay\Events\EventRegistration;
use Maxcuso\Roleplay\Events\QuestEvent;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class LeaveEventController implements RequestHandlerInterface
{
    private EventRegistration $registration;

    public function __construct(EventRegistration $registration)
    {
        $this->registration = $registration;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('viewForum');

        $parameters = $request->getAttribute('routeParameters', []);
        $eventId = filter_var($parameters['id'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        if ($eventId === false) {
            return new JsonResponse(['error' => 'Некорректный идентификатор события'], 400);
        }

        if (!\Maxcuso\Roleplay\Events\EventAccess::scope(QuestEvent::query(), $actor)->where('id', $eventId)->exists()) {
            return new JsonResponse(['error' => 'Событие не найдено'], 404);
        }

        try {
            $this->registration->leave($eventId, (int) $actor->id);
        } catch (DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 409);
        }

        return new JsonResponse([
            'data' => [
                'eventId' => $eventId,
                'userId' => (int) $actor->id,
                'isParticipating' => false,
            ],
        ]);
    }
}

