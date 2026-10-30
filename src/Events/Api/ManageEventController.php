<?php

namespace Maxcuso\Roleplay\Events\Api;

use DomainException;
use InvalidArgumentException;
use Flarum\Http\RequestUtil;
use Illuminate\Contracts\Validation\Factory;
use Laminas\Diactoros\Response\JsonResponse;
use Maxcuso\Roleplay\Events\EventEditor;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ManageEventController implements RequestHandlerInterface
{
    public function __construct(private Factory $validator, private EventEditor $editor, private ManagedEventData $data) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('viewForum');
        $actor->assertCan('quest-events.manage');
        $id = filter_var($request->getAttribute('routeParameters', [])['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) return new JsonResponse(['error' => 'Некорректный номер события'], 400);
        $input = $request->getParsedBody();
        if (!is_array($input)) return new JsonResponse(['error' => 'Ожидается объект параметров'], 400);
        $deleting = $request->getMethod() === 'DELETE';
        if (isset($input['title']) && is_string($input['title'])) $input['title'] = trim($input['title']);
        $rules = ['revision' => ['required', 'string', 'size:64']];
        if (!$deleting) $rules += [
            'title' => ['required', 'string', 'min:3', 'max:80'],
            'reward' => ['sometimes', 'array:experience,gold,items'],
            'reward.experience' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'reward.gold' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'reward.items' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'description' => ['present', 'nullable', 'string', 'max:1000'],
            'minParticipants' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'maxParticipants' => ['required', 'integer', 'gte:minParticipants', 'max:4294967295'],
            'startDelayHours' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'preferredStartAt' => ['present', 'nullable', 'date_format:Y-m-d\TH:i:sP'],
            'leaderId' => ['present', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'eventType' => ['required', 'in:quest,forum'],
            'difficulty' => ['present', 'nullable', 'integer', 'min:1', 'max:6'],
            'customColor' => ['present', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/D'],
            'draftAction' => ['required', 'in:keep,clear,select'],
            'draftId' => ['required_if:draftAction,select', 'nullable', 'integer', 'min:1', 'max:4294967295'],
        ];
        $validation = $this->validator->make($input, $rules);
        if ($validation->fails()) return new JsonResponse(['error' => $validation->errors()->first()], 422);
        $data = $validation->validated();
        try {
            if ($deleting) {
                $this->editor->delete($actor, $id, $data['revision']);
                return new JsonResponse(['data' => ['id' => $id, 'deleted' => true]]);
            }
            return new JsonResponse(['data' => $this->data->serialize($this->editor->update($actor, $id, $data), $actor)]);
        } catch (DomainException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 409);
        } catch (InvalidArgumentException $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 422);
        }
    }
}
