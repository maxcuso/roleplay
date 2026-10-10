<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Admin;

use Flarum\Discussion\Discussion;
use Flarum\Http\UrlGenerator;
use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Catalog\Shared\CatalogRevision;
use Maxcuso\Roleplay\Character\Operations\ActorOperationLock;

/** Администратор меняет идентичность и литературную ссылку. Создание и развитие — другие операции. */
final class CharacterAdmin
{
    public const STATUSES = ['creating', 'active', 'dead', 'archived'];

    public function __construct(private ConnectionInterface $db, private UrlGenerator $url) {}

    public function list(User $actor, string $search, string $status, int $page): array
    {
        Access::admin($actor);
        if ($status !== '' && !in_array($status, self::STATUSES, true)) FieldValues::invalid('Неизвестный статус персонажа.');
        $query = $this->db->table('roleplay_characters');
        if ($search !== '') {
            $literal = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($names) use ($literal) {
                $names->where('first_name', 'like', '%'.$literal.'%')->orWhere('last_name', 'like', '%'.$literal.'%');
            });
        }
        if ($status !== '') $query->where('status', $status);
        $total = $query->count();
        $rows = $query->orderBy('id')->offset(($page - 1) * 30)->limit(30)->get();
        $owners = User::query()->whereIn('id', $rows->pluck('user_id'))->pluck('username', 'id');
        $races = $this->db->table('roleplay_races')->pluck('name', 'id');
        return [
            'revision' => 0, 'total' => $total, 'page' => $page, 'per_page' => 30,
            'rows' => $rows->map(function ($row) use ($owners, $races): array {
                $data = (array) $row;
                $data['id'] = (int) $row->id;
                $data['revision'] = (int) $row->revision;
                $data['name'] = trim($row->first_name.' '.$row->last_name);
                $data['owner_name'] = $owners[$row->user_id] ?? '—';
                $data['race_name'] = $races[$row->race_id] ?? '—';
                $data['discussion_link'] = $row->discussion_id ? $this->url->to('forum')->path('d/'.$row->discussion_id) : '';
                return $data;
            })->all(),
        ];
    }

    public function save(int $id, int $revision, array $input, User $actor): array
    {
        Access::admin($actor);
        FieldValues::allowed($input, ['first_name', 'last_name', 'discussion_link']);
        $first = FieldValues::text($input['first_name'] ?? null, 'Имя', 100, true);
        $last = FieldValues::text($input['last_name'] ?? null, 'Фамилия', 100);
        $discussionId = $this->discussionId($input['discussion_link'] ?? null, $actor);
        return $this->db->transaction(function () use ($id, $revision, $first, $last, $discussionId): array {
            $row = $this->db->table('roleplay_characters')->where('id', $id)->lockForUpdate()->first();
            if (!$row) throw new ApiProblem('character_not_found', 'Персонаж не найден.', 404);
            if ((int) $row->revision !== $revision) {
                throw new ApiProblem('character_revision_conflict', 'Персонаж изменился. Обнови список.', 409);
            }
            if ($discussionId !== null && $this->db->table('roleplay_characters')->where('discussion_id', $discussionId)
                ->where('id', '!=', $id)->exists()) FieldValues::invalid('Тема уже привязана к другому персонажу.');
            $this->db->table('roleplay_characters')->where('id', $id)->update([
                'first_name' => $first, 'last_name' => $last, 'discussion_id' => $discussionId,
                'revision' => $revision + 1,
                'lore_revision' => (int) $row->lore_revision + ((int) $row->discussion_id !== (int) $discussionId ? 1 : 0),
                // Перепривязка администратором сохраняет решение; старый отпечаток относится к прежней теме.
                'literature_hash' => (int) $row->discussion_id !== (int) $discussionId ? null : $row->literature_hash,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                // literature_approved и status сохраняются согласно договорённости.
            ]);
            return ['id' => $id];
        });
    }

    /** Удаляем игровое состояние одной транзакцией; аккаунт и литературная тема принадлежат форуму. */
    public function remove(int $id, int $revision, User $actor): array
    {
        Access::admin($actor);
        return (new CatalogRevision($this->db))->read(function () use ($id, $revision): array {
            $owner = $this->db->table('roleplay_characters')->where('id', $id)->value('user_id');
            if ($owner === null) throw new ApiProblem('character_not_found', 'Персонаж не найден.', 404);
            // Как при создании: сначала владелец, затем персонаж. Лимит персонажей меняется согласованно.
            ActorOperationLock::acquire($this->db, (int) $owner);
            $row = $this->db->table('roleplay_characters')->where('id', $id)->lockForUpdate()->first();
            if (!$row) throw new ApiProblem('character_not_found', 'Персонаж не найден.', 404);
            if ((int) $row->revision !== $revision) {
                throw new ApiProblem('character_revision_conflict', 'Персонаж изменился. Обнови список.', 409);
            }
            // Сначала дочерние строки: внешние ключи намеренно запрещают удалять только заголовок.
            // При добавлении новой таблицы состояния персонажа добавь её суффикс в этот список.
            foreach (['events', 'choices', 'balances', 'items', 'spells', 'perks',
                'weapon_proficiencies', 'skills', 'attributes'] as $section) {
                $this->db->table('roleplay_character_'.$section)->where('character_id', $id)->delete();
            }
            // UUID сохраняем: запоздалый повтор создания не должен воскресить удалённого персонажа.
            $this->db->table('roleplay_operations')->where('character_id', $id)->update(['character_id' => null]);
            $this->db->table('roleplay_characters')->where('id', $id)->delete();
            return ['id' => $id];
        });
    }

    private function discussionId(mixed $value, User $actor): ?int
    {
        $link = FieldValues::text($value, 'Литературная анкета', 1000);
        if ($link === null) return null;
        $root = rtrim($this->url->to('forum')->path(''), '/');
        if (str_starts_with($link, $root.'/')) $link = substr($link, strlen($root));
        if (!preg_match('~^/d/([1-9][0-9]*)(?:-[^/?#]*)?(?:[/?#].*)?$~D', $link, $match)) {
            FieldValues::invalid('Укажи ссылку на тему этого форума: /d/номер.');
        }
        $id = (int) $match[1];
        if (!Discussion::whereVisibleTo($actor)->where('id', $id)->exists()) FieldValues::invalid('Тема анкеты не найдена.');
        return $id;
    }
}
