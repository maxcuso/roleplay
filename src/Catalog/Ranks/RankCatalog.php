<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Ranks;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\{AbstractCatalog, DefinitionStore, FieldValues};

/** Одна шкала I–X. Ранг не привязан к характеристике, навыку, специализации или Дому. */
final class RankCatalog extends AbstractCatalog
{
    protected const TABLE = 'ranks';
    protected const INTEGER_FIELDS = ['level'];

    public function __construct(DefinitionStore $store, private readonly ConnectionInterface $db)
    {
        parent::__construct($store);
    }

    public function validate(array $input): array
    {
        FieldValues::allowed($input, ['name', 'description', 'enabled', 'sort_order', 'letter', 'level']);
        $common = FieldValues::common($input);
        $level = FieldValues::integer($input['level'] ?? null, 'Номер ранга', 1, 10);
        $letter = FieldValues::text($input['letter'] ?? null, 'Буква', 4, true);
        if (!preg_match('/^[\x{05D0}-\x{05EA}]$/u', $letter)) {
            FieldValues::invalid('Укажи одну еврейскую букву для ранга.');
        }
        // Порядок шкалы определяется номером, не временем создания записи.
        $common['sort_order'] = $level;
        return $common + ['level' => $level, 'letter' => $letter];
    }

    public function save(?int $id, int $revision, array $input, User $actor): array
    {
        return $this->store->save(self::TABLE, $id, $revision, $input, $actor, function (array $input) use ($id): array {
            $fields = $this->validate($input);
            // Проверка и запись идут под общей блокировкой справочников; SQL UNIQUE — дополнительная защита.
            $query = $this->db->table('roleplay_ranks')->where('level', $fields['level']);
            if ($id !== null) $query->where('id', '!=', $id);
            if ($query->exists()) throw new ApiProblem('rank_level_taken', 'Этот номер ранга уже занят.', 409);
            return $fields;
        });
    }
}
