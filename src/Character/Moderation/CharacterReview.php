<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Moderation;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Api\OperationRequest;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Character\Application\ApplicationTopics;
use Maxcuso\Roleplay\Character\Operations\CharacterOperation;
use Maxcuso\Roleplay\Character\Profile\CharacterVisibility;

/** Две независимые проверки. Одобрение механики не одобряет литературный текст и наоборот. */
final class CharacterReview
{
    public function __construct(private readonly ConnectionInterface $db, private readonly ApplicationTopics $topics, private readonly CharacterOperation $operations) {}

    public function present(array $character, User $actor): array
    {
        return (new CharacterReviewView($this->db, $this->topics))->present($character, $actor);
    }

    public function change(int $id, User $actor, mixed $input): array
    {
        $body = OperationRequest::body($input, ['operation_key', 'revision', 'catalog_revision', 'part', 'action', 'comment', 'topic_hash']);
        $part = $body['part'] ?? null;
        $action = $body['action'] ?? null;
        if ($part === 'literature' && !in_array($action, ['submit', 'withdraw'], true)) {
            throw new ApiProblem('literature_topic_only', 'Для принятия литературной части оставьте сообщение модератора и закройте тему анкеты', 400);
        }
        if (!in_array($part, ['mechanics', 'literature'], true) || !in_array($action, ['approve', 'request_changes', 'submit', 'withdraw'], true)
            || $action === 'withdraw' && $part !== 'literature') {
            throw new ApiProblem('invalid_request', 'Не удалось определить действие с анкетой. Попробуйте обновить страницу', 400);
        }
        $comment = FieldValues::text($body['comment'] ?? null, 'Замечания', 4000, $action === 'request_changes');
        return $this->operations->run($id, $actor, 'character_review', $body,
            function (array $character) use ($actor, $action): void {
                (new CharacterVisibility())->assertVisible($character, $actor);
                $allowed = in_array($action, ['submit', 'withdraw'], true)
                    ? ReviewPermissions::owner($character, $actor) : ReviewPermissions::staff($actor);
                if (!$allowed) throw new ApiProblem('review_forbidden', 'У вас нет прав для выполнения этого действия', 403);
            },
            function (array $character) use ($actor, $part, $action, $comment, $body): array {
                if ($character['status'] !== 'creating') {
                    throw new ApiProblem('review_closed', 'Проверка этого персонажа уже завершена. Попробуйте обновить страницу', 409);
                }
                $topic = $character['discussion_id'] ? $this->topics->inspect($actor, (int) $character['discussion_id'])
                    : ['available' => false, 'hash' => null];
                $literature = CharacterReviewView::literatureStatus($character, $topic);
                $statuses = ['mechanics' => $character['mechanics_status'], 'literature' => $literature];
                $current = $statuses[$part];
                // Отправить можно только доступную тему. Это не одобрение литературного текста.
                if ($part === 'literature' && $action === 'submit' && !$topic['available']) {
                    throw new ApiProblem('application_topic_missing', 'Литературная тема недоступна. Обратитесь к администратору', 409);
                }
                // Отзыв возвращает только свою ожидающую анкету в работу, не отменяя решение модератора.
                if ($action === 'withdraw' && ($current !== 'review' || !empty($topic['accepted']))) {
                    throw new ApiProblem('review_state_changed', 'Отозвать можно только литературную часть, которая ещё не одобрена', 409);
                }
                if ($action === 'submit' && !in_array($current, ['draft', 'changes_requested'], true)
                    || $action === 'approve' && $current !== 'review'
                    || $action === 'request_changes' && !in_array($current, ['review', 'approved'], true)) {
                    throw new ApiProblem('review_state_changed', 'Это действие больше недоступно. Попробуйте обновить страницу', 409);
                }

                $statuses[$part] = match ($action) {
                    'approve' => 'approved', 'request_changes' => 'changes_requested', 'withdraw' => 'draft', default => 'review',
                };
                $fields = ['mechanics_status' => $statuses['mechanics'], 'literature_status' => $statuses['literature'],
                    'literature_approved' => $statuses['literature'] === 'approved'];

                // Даже при одобрении механики проверяем, что литературная тема ещё существует.
                $activate = $statuses['mechanics'] === 'approved' && $statuses['literature'] === 'approved' && $topic['available'];
                if ($statuses['mechanics'] === 'approved' && $statuses['literature'] === 'approved' && !$topic['available']) {
                    throw new ApiProblem('application_topic_missing', 'Не удалось активировать персонажа: литературная тема недоступна. Восстановите доступ к теме перед активацией', 409);
                }
                if ($activate) {
                    $fields['status'] = 'active';
                    (new \Maxcuso\Roleplay\Character\Profile\RoleplayerStatistics($this->db))
                        ->activated((int) $character['user_id'], gmdate('Y-m-d H:i:s'));
                }
                return ['fields' => $fields, 'event' => $action === 'submit' ? 'review_submitted' : 'review_decided',
                    'payload' => ['part' => $part, 'action' => $action, 'comment' => $comment,
                        'before' => $current, 'after' => $statuses[$part], 'activated' => $activate]];
            });
    }

}
