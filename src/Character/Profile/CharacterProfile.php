<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Character\Profile;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Character\Application\ApplicationTopics;
use Maxcuso\Roleplay\Character\Moderation\CharacterReview;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRegistry;
use Maxcuso\Roleplay\Character\Calculation\CharacterCalculationBuilder;
use Maxcuso\Roleplay\Character\State\CharacterSnapshotBuilder;
use Maxcuso\Roleplay\Character\State\CharacterStateReader;
use Maxcuso\Roleplay\Character\Progression\ProgressionOptions;
use Maxcuso\Roleplay\Creation\CreationCatalog;
use Maxcuso\Roleplay\Rules\DevelopmentConfigurationStore;

/** Профиль соединяет права, снимок и общий расчёт мастера/анкеты. */
final class CharacterProfile
{
    public function __construct(private readonly ConnectionInterface $db, private readonly ?ApplicationTopics $topics = null) {}

    public function list(int $userId, ?int $before, User $actor): array
    {
        Access::view($actor);
        $query = (new CharacterVisibility())->apply($this->db->table('roleplay_characters')->where('user_id', $userId), $actor);
        if ($before !== null) $query->where('id', '<', $before);
        $rows = $query->orderByDesc('id')->limit(31)->get();
        $hasMore = $rows->count() > 30;
        $rows = $rows->take(30);
        return ['roleplayer' => (new RoleplayerStatistics($this->db))->read($userId),
            'characters' => $rows->map(fn ($row) => [
            'avatar' => $row->avatar ?? null, 'id' => (int) $row->id, 'name' => trim($row->first_name.' '.$row->last_name), 'status' => $row->status,
        ])->all(), 'next_before' => $hasMore ? (int) $rows->last()->id : null];
    }

    /** История финансов и выдач видна владельцу и персоналу, не посетителям профиля. */
    private function history(int $id, array $character, User $actor): array
    {
        if ((int) $character['user_id'] !== (int) $actor->id
            && !\Maxcuso\Roleplay\Character\Moderation\ReviewPermissions::staff($actor)) return [];
        return $this->db->table('roleplay_character_events')->where('character_id', $id)
            ->whereIn('type', ['character_developed', 'character_managed'])->orderByDesc('id')->limit(50)->get()
            ->map(fn ($row) => ['id' => (int) $row->id, 'type' => $row->type, 'actor_id' => (int) $row->actor_user_id,
                'created_at' => $row->created_at, 'details' => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)])->all();
    }

    public function read(int $id, User $actor): array
    {
        Access::view($actor);
        return (new CatalogRevision($this->db))->read(function () use ($id, $actor): array {
            $visibility = new CharacterVisibility();
            $reader = new CharacterStateReader($this->db);
            $state = $reader->read($id, fn (array $character) => $visibility->assertVisible($character, $actor));
            $rules = (new DevelopmentConfigurationStore($this->db))->read();
            $snapshot = (new CharacterSnapshotBuilder($reader, $rules->learning()))->fromState($state);
            $calculation = CharacterCalculationBuilder::defaults($rules)->calculate($snapshot);
            $data = $snapshot->jsonSerialize();
            // Публичный заголовок разрешаем явным списком; не публикуем внутренние настройки создания.
            $data['character'] = array_intersect_key($snapshot->character, array_flip([
                'id', 'user_id', 'first_name', 'last_name', 'age', 'avatar', 'status', 'revision',
                'literature_approved', 'discussion_id', 'mechanics_status', 'literature_status',
            ]));
            $data['character']['race'] = array_intersect_key($snapshot->character['race'], array_flip(['id', 'name', 'icon', 'rules_url']));
            // Ссылка и отображаемые поля нужны и после выдачи: редактор должен знать текущий ранг.
            $data['character']['rank_id'] = $snapshot->character['rank_id'];
            $data['character']['rank'] = $snapshot->character['rank'] === null ? null
                : array_intersect_key($snapshot->character['rank'], array_flip(['id', 'level', 'letter', 'name', 'description', 'enabled']));
            unset($data['pending_calculation']);
            $attributes = array_map(fn (array $row, string $slug) => [
                'id' => $row['id'], 'name' => $row['name'], 'icon' => $row['icon'], 'slug' => $slug,
            ], array_values($snapshot->attributes), array_keys($snapshot->attributes));
            // Цель эффекта может быть ещё не изученным навыком. Для подписи нужны только его ID и имя.
            $skillIds = array_values(array_unique(array_filter(array_map(
                fn (array $effect) => $effect['definition']['skill_id'] ?? null, $calculation['effects']
            ))));
            $effectSkills = $skillIds ? array_map(fn (array $row) => ['id' => $row['id'], 'name' => $row['name']],
                (new CatalogRegistry($this->db))->definitionsFor('skills', $skillIds)) : [];
            $canDevelop = $snapshot->character['status'] === 'active' && (int) $snapshot->character['user_id'] === (int) $actor->id;
            $catalog = $canDevelop ? (new CreationCatalog($this->db))->read(fn (array $catalog) => $catalog) : null;
            $development = $catalog ? (new ProgressionOptions($catalog['development']))->evaluate($snapshot, $catalog['definitions'], $catalog['configuration'])
                    + ['rules_hash' => $catalog['rules_hash']] : null;
            return [
                'roleplayer' => (new RoleplayerStatistics($this->db))->read((int) $snapshot->character['user_id']),
                'snapshot' => $data, 'calculation' => $calculation,
                'lookups' => ['attributes' => $attributes, 'skills' => $effectSkills],
                'development' => $development,
                'learning' => $catalog ? \Maxcuso\Roleplay\Character\Progression\SpellLearning::options($snapshot, $catalog) : null,
                'permissions' => ['can_equip' => $visibility->canEquip($snapshot->character, $actor),
                    'can_manage' => $snapshot->character['status'] === 'active' && \Maxcuso\Roleplay\Character\Moderation\ReviewPermissions::staff($actor)],
                'history' => $this->history($id, $snapshot->character, $actor),
                'review' => $this->topics === null ? null : (new CharacterReview($this->db, $this->topics))->present($snapshot->character, $actor),
            ];
        });
    }
}
