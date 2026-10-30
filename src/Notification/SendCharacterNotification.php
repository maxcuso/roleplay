<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Notification;

use Flarum\Notification\NotificationSyncer;
use Flarum\Queue\AbstractJob;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;

/** Штатная очередь Flarum. ID события в payload даёт штатному Syncer ключ от дублей. */
final class SendCharacterNotification extends AbstractJob
{
    public int $tries = 3;
    public int $backoff = 30;
    public function __construct(private int $ownerId, private int $actorId, private array $data) { parent::__construct(); }

    public function handle(): void
    {
        $db = resolve(ConnectionInterface::class);
        $owner = User::find($this->ownerId);
        if (!$owner || !$owner->can('roleplay.view')) return;
        $character = $db->table('roleplay_characters')->where('id', $this->data['characterId'])->where('user_id', $this->ownerId)->first();
        $event = CharacterEvent::where('id', $this->data['eventId'])->where('character_id', $this->data['characterId'])->first();
        if (!$character || !$event) return;
        $class = str_starts_with($this->data['kind'], 'rank_') ? RankBlueprint::class
            : (in_array($this->data['kind'], ['reward', 'deduction', 'grant_item', 'remove_item', 'grant_perk', 'remove_perk', 'grant_spell', 'remove_spell'], true) ? GrantBlueprint::class : ReviewBlueprint::class);
        if (!$owner->shouldAlert($class::getType())) return;
        resolve(NotificationSyncer::class)->sync(new $class($event, User::find($this->actorId), $this->data), [$owner]);
    }
}
