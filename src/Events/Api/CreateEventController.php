<?php

namespace Maxcuso\Roleplay\Events\Api;

use DateTimeImmutable;
use DomainException;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Validation\Factory;
use InvalidArgumentException;
use Laminas\Diactoros\Response\JsonResponse;
use Maxcuso\Roleplay\Events\EventCreator;
use Maxcuso\Roleplay\Events\EventAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CreateEventController implements RequestHandlerInterface
{
    private EventCreator $creator;
    private Factory $validator;

    public function __construct(EventCreator $creator, Factory $validator)
    {
        $this->creator = $creator;
        $this->validator = $validator;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('viewForum');
        EventAccess::assertCreateAny($actor);

        $input = $request->getParsedBody();
        if (!is_array($input)) {
            return new JsonResponse(['error' => 'Ожидается JSON-объект с параметрами события'], 400);
        }

        $validation = $this->validator->make($input, [
            'title' => ['required', 'string', 'min:3', 'max:80'],
            'reward' => ['sometimes', 'array:experience,gold,items'],
            'reward.experience' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'reward.gold' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'reward.items' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'description' => ['nullable', 'string', 'max:1000'],
            'minParticipants' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'maxParticipants' => ['required', 'integer', 'gte:minParticipants', 'max:4294967295'],
            'startDelayHours' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'leaderId' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'draftId' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'eventType' => ['sometimes', 'required', 'in:quest,forum'],
            'difficulty' => ['nullable', 'integer', 'min:1', 'max:6'],
            'customColor' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'preferredStartAt' => ['nullable', 'date_format:Y-m-d\TH:i:sP'],
        ]);

        if ($validation->fails()) {
            return new JsonResponse(['errors' => $validation->errors()->toArray()], 422);
        }

        $data = $validation->validated();

        try {
            $event = $this->creator->create(
                $actor,
                $data['title'],
                $data['description'] ?? null,
                (int) $data['minParticipants'],
                (int) $data['maxParticipants'],
                (int) $data['startDelayHours'],
                isset($data['preferredStartAt']) ? new DateTimeImmutable($data['preferredStartAt']) : null,
                isset($data['leaderId']) ? (int) $data['leaderId'] : null,
                isset($data['draftId']) ? (int) $data['draftId'] : null,
                $data['eventType'] ?? 'forum',
                isset($data['difficulty']) ? (int) $data['difficulty'] : null,
                $data['customColor'] ?? null,
                $data['reward'] ?? []
            );
        } catch (InvalidArgumentException | DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422);
        }

        return new JsonResponse([
            'data' => [
                'id' => (int) $event->id,
                'title' => $event->title,
                'status' => $event->status,
                'leaderId' => (int) $event->leader_id,
            ],
        ], 201);
    }
}
