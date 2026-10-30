<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Shared;

use Flarum\User\User;
use Maxcuso\Roleplay\Catalog\Effects\InlineEffects;

/** Общий формат эффектов перков и магических предметов. Сохраняется вместе с владельцем. */
abstract class EffectBoundCatalog extends AbstractCatalog
{
    public function validateWithEffects(array $input): array
    {
        $fields = $this->validate($input);
        $effects = (new InlineEffects($this->store))->normalize($input['effects'] ?? [], static::TABLE);
        if (static::TABLE === 'items' && !$fields['is_magical'] && $effects) {
            FieldValues::invalid('Эффекты доступны только магическим предметам. Уберите эффекты перед изменением типа предмета');
        }
        return $fields + ['effects' => json_encode($effects, JSON_THROW_ON_ERROR)];
    }

    public function save(?int $id, int $revision, array $input, User $actor): array
    {
        return $this->store->save(static::TABLE, $id, $revision, $input, $actor, $this->validateWithEffects(...));
    }

    public function present(array $row): array
    {
        $row = parent::present($row);
        $row['effects'] = json_decode($row['effects'] ?? '[]', true, flags: JSON_THROW_ON_ERROR);
        return $row;
    }
}
