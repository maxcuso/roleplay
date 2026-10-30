<?php
namespace Maxcuso\Roleplay\Events\Api;

use DomainException;
use Flarum\Http\RequestUtil;
use Laminas\Diactoros\Response\JsonResponse;
use Maxcuso\Roleplay\Events\{EventAccess, EventRegistration, EventStarter, QuestEvent};
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class EventModerationController implements RequestHandlerInterface
{
    public function __construct(private EventRegistration $registration, private EventStarter $starter, private ManagedEventData $data, private LoggerInterface $logger) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        $actor->assertRegistered();
        $actor->assertCan('viewForum');
        $actor->assertCan('quest-events.manage');
        $params = $request->getAttribute('routeParameters', []);
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $input = $request->getParsedBody();
        $revision = is_array($input) ? ($input['revision'] ?? null) : null;
        if ($id === false || !is_string($revision) || !preg_match('/^[a-f0-9]{64}$/D', $revision)) return new JsonResponse(['error'=>'Некорректный запрос или версия события'], 422);
        $event = EventAccess::scope(QuestEvent::query(), $actor)->find($id);
        if ($event === null) return new JsonResponse(['error'=>'Событие не найдено'], 404);
        $error = null;
        try {
            if ($request->getMethod() === 'DELETE') {
                $userId = filter_var($params['userId'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
                if ($userId === false) return new JsonResponse(['error'=>'Некорректный участник'], 422);
                $this->registration->removeParticipant($actor, $id, $userId, $revision);
            } else {
                $this->starter->startIfDue($id, $actor, $revision);
            }
        } catch (DomainException $exception) {
            $error = $exception->getMessage();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return new JsonResponse(['error'=>'Событие не найдено'], 404);
        } catch (\Throwable $exception) {
            $this->logger->error('Ошибка управления событием', ['event_id'=>$id,'exception'=>$exception]);
            $error = 'Не удалось выполнить действие. Обнови список и проверь причину ошибки запуска.';
        }
        $event = EventAccess::scope(QuestEvent::query(), $actor)->with(['leader','participants'])->withCount('participants')->find($id);
        if ($event === null) return new JsonResponse(['error'=>'Событие не найдено'], 404);
        $response = ['data'=>$this->data->serialize($event, $actor)];
        if ($error !== null) $response['error'] = $error;
        return new JsonResponse($response, $error === null ? 200 : 409);
    }
}
