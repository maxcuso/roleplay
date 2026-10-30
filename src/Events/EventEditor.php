<?php

namespace Maxcuso\Roleplay\Events;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class EventEditor
{
    public function __construct(private ConnectionInterface $db, private EventDrafts $drafts, private EventStarter $starter, private LoggerInterface $logger, private QuestSettings $settings, private \Maxcuso\Roleplay\Events\Participation\ParticipationTypes $types) {}

    public static function revision(QuestEvent $event): string
    {
        $attributes = $event->getAttributes();
        // withCount adds a computed field which is not part of the stored row.
        unset($attributes['participants_count']);
        ksort($attributes);
        return hash('sha256', json_encode([$attributes, $event->participants()->orderBy('users.id')->pluck('users.id')->all()], JSON_THROW_ON_ERROR));
    }

    private function assertRevision(QuestEvent $event, string $revision): void
    {
        if (!hash_equals(self::revision($event), $revision)) {
            throw new DomainException('Событие изменилось. Обнови список и открой форму заново.');
        }
    }

    public function update(User $actor, int $id, array $data): QuestEvent
    {
        $actor->assertCan('quest-events.manage');
        $this->db->transaction(function () use ($actor, $id, $data) {
            if ($data['leaderId'] !== null && User::where('id', $data['leaderId'])->lockForUpdate()->first() === null) {
                throw new DomainException('Ведущий не найден');
            }
            $event = QuestEvent::where('id', $id)->lockForUpdate()->first();
            if ($event === null) throw new DomainException('Событие удалено. Обнови список.');
            EventAccess::assertView($event, $actor);
            $this->assertRevision($event, $data['revision']);
            $this->settings->assertTypeAllowed($data['eventType'], $event->event_type);
            $definition = new EventDefinition($data['title'], $data['minParticipants'], $data['maxParticipants']);
            $count = $event->participants()->count();
            if ($data['maxParticipants'] < $count) throw new DomainException('Максимум не может быть меньше числа записавшихся участников');
            $event->title = $definition->title;
            $reward = $data['reward'] ?? ($data['eventType'] === 'quest' ? EventReward::data($event) : []);
            foreach (EventReward::columns($data['eventType'], $reward) as $field => $value) $event->$field = $value;
            $description = trim($data['description'] ?? '');
            $event->description = $description === '' ? null : $description;
            $event->leader_id = $data['leaderId'];
            foreach (EventAppearance::validate($data['eventType'], $data['difficulty'], $data['customColor']) as $field => $value) $event->$field = $value;
            $event->min_participants = $data['minParticipants'];
            $event->max_participants = $data['maxParticipants'];
            $event->start_delay_hours = $data['startDelayHours'];
            $event->preferred_start_at = $data['preferredStartAt'] === null ? null : (new DateTimeImmutable($data['preferredStartAt']))->setTimezone(new DateTimeZone('UTC'));
            $timingChanged = $event->isDirty(['min_participants', 'max_participants', 'start_delay_hours', 'preferred_start_at']);
            if ($event->status !== 'recruiting' && ($timingChanged || $data['draftAction'] !== 'keep')) {
                throw new DomainException('После старта нельзя менять условия набора, дату или черновик');
            }
            if ($data['draftAction'] === 'clear') {
                $event->source_draft_id = null;
                $event->draft_owner_id = null;
            } elseif ($data['draftAction'] === 'select') {
                $draft = $this->drafts->read($actor, $data['draftId']);
                $event->source_draft_id = $draft['id'];
                $event->draft_owner_id = $actor->id;
            }
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            if ($event->status === 'recruiting') {
                if (!$definition->hasMinimumParticipants($count)) {
                    $event->scheduled_start_at = null;
                } elseif (!$definition->hasAvailableSlot($count) && ($event->preferred_start_at === null || $event->preferred_start_at <= $now)) {
                    $event->scheduled_start_at = $now;
                } elseif ($timingChanged || $event->scheduled_start_at === null) {
                    $event->scheduled_start_at = $definition->calculateStartAt($now, $event->preferred_start_at, $event->start_delay_hours);
                }
                if ($event->scheduled_start_at === null) {
                    $event->start_error = null;
                    $event->start_failed_at = null;
                }
            }
            $event->save();
            if ($event->status === 'recruiting' && $event->scheduled_start_at !== null && $event->scheduled_start_at <= $now) {
                $this->db->afterCommit(function () use ($id) {
                    try { $this->starter->startIfDue($id); }
                    catch (Throwable $exception) { $this->logger->error('Не удалось запустить событие после редактирования', ['event_id' => $id, 'exception' => $exception]); }
                });
            }
        });
        return QuestEvent::with(['leader', 'participants'])->withCount('participants')->findOrFail($id);
    }

    public function delete(User $actor, int $id, string $revision): void
    {
        $actor->assertCan('quest-events.manage');
        $this->db->transaction(function () use ($actor, $id, $revision) {
            $event = QuestEvent::where('id', $id)->lockForUpdate()->first();
            if ($event === null) throw new DomainException('Событие уже удалено. Обнови список.');
            EventAccess::assertView($event, $actor);
            $this->assertRevision($event, $revision);
            // Foreign keys remove registrations; the discussion remains intact.
            $this->types->remove($event);
            $event->delete();
        });
    }
}

