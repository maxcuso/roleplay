<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Api;

use Flarum\Http\RequestUtil;
use Maxcuso\Roleplay\Events\Api\EventData;
use Maxcuso\Roleplay\Events\Api\ManagedEventData;
use Maxcuso\Roleplay\Events\EventAccess;
use Maxcuso\Roleplay\Events\QuestEvent;
use Maxcuso\Roleplay\Workspace\WorkspaceAccess;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Только свои эпизоды. Назначение ведущим не расширяет доступ к скрытым источникам. */
final class WorkspaceEpisodesController implements RequestHandlerInterface
{
    public function __construct(private EventData $data, private ManagedEventData $managed) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $actor = RequestUtil::getActor($request);
        WorkspaceAccess::assertView($actor);
        try {
            $input = $request->getQueryParams();
            $type = $input['type'] ?? 'quest';
            $status = $input['status'] ?? 'current';
            if (!in_array($type, ['quest', 'forum'], true) || !in_array($status, ['current', 'completed'], true)) {
                throw new ApiProblem('invalid_filter', 'Выберите доступный тип событий и статус');
            }
            $before = isset($input['before']) ? CatalogRequest::positive($input['before'], 'Страница') : null;
            $events = EventAccess::scope(QuestEvent::query(), $actor)->where('leader_id', $actor->id)
                ->where('event_type', $type)->whereIn('status', $status === 'completed' ? ['completed'] : ['recruiting', 'active'])
                ->when($before !== null, fn ($query) => $query->where('id', '<', $before))
                ->with(['leader', 'participants'])->withCount('participants')->orderByDesc('id')->limit(26)->get();
            $canManage = $actor->can('quest-events.manage');
            $rows = [];
            foreach ($events->take(25) as $event) {
                $rows[] = ($canManage ? $this->managed : $this->data)->serialize($event, $actor)
                    + ['canManage' => $canManage, 'sourcePostId' => $event->source_post_id];
            }
            return ApiResponse::data(['rows' => $rows, 'nextBefore' => $events->count() > 25 ? end($rows)['id'] : null]);
        } catch (ApiProblem $problem) {
            return ApiResponse::problem($problem);
        }
    }
}
