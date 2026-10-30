<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Character\Progression;

use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;
use Maxcuso\Roleplay\Character\State\{CharacterSnapshot, CharacterGrants, CharacterBalances};

/** Источники и условия вычисляются одинаково для списка и для защищённой операции. */
final class SpellLearning
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public static function options(CharacterSnapshot $snapshot, array $catalog): array
    {
        $owned = array_column($snapshot->spells, 'id');
        $definitions = $catalog['definitions'];
        $circle = $catalog['development']->statistics()->magicCircleAt((int) ($snapshot->attributes['magic']['level'] ?? 0));
        $experience = $snapshot->balances['experience_active'] ?? 0;
        $options = [];
        foreach ($snapshot->inventory as $instance) {
            $item = $instance['definition'];
            if (!$item['enabled'] || !$item['is_literature'] || $instance['quantity'] < 1) continue;
            foreach ($item['literature_contents'] as $content) {
                if ($content['type'] !== 'spell' || in_array($content['knowledge_id'], $owned, true)) continue;
                $spell = $definitions['spells'][$content['knowledge_id']] ?? null;
                if (!$spell) continue;
                $path = $definitions['paths'][$spell['magic_path_id']] ?? null;
                $cost = $catalog['development']->values['spell_learning_costs'][$spell['level']] ?? null;
                $reasons = [];
                if (!$spell['enabled'] || !($path['enabled'] ?? false)) $reasons[] = 'Заклинание или его путь отключены';
                if ($spell['level'] > $circle) $reasons[] = 'Требуется '.$spell['level'].' круг';
                if ($cost === null) $reasons[] = 'Стоимость изучения не настроена';
                elseif ($experience < $cost) $reasons[] = 'Не хватает '.($cost - $experience).' опыта';
                $options[$spell['id']] ??= ['id' => $spell['id'], 'spell' => $spell, 'path' => $path,
                    'cost' => $cost, 'allowed' => !$reasons, 'reasons' => $reasons, 'sources' => []];
                // Одну книгу показываем один раз, независимо от числа её экземпляров.
                $options[$spell['id']]['sources'][$item['id']] ??= ['instance_id' => $instance['id'],
                    'item_id' => $item['id'], 'name' => $item['name'], 'consumed' => $item['consumed_on_learning']];
            }
        }
        foreach ($options as &$option) {
            $option['sources'] = array_values($option['sources']);
            usort($option['sources'], fn ($a, $b) => $a['consumed'] <=> $b['consumed'] ?: $a['instance_id'] <=> $b['instance_id']);
        }
        unset($option);
        $options = array_values($options);
        usort($options, fn ($a, $b) => $b['allowed'] <=> $a['allowed'] ?: $a['spell']['level'] <=> $b['spell']['level'] ?: $a['id'] <=> $b['id']);
        return ['rules_hash' => $catalog['rules_hash'], 'experience' => $experience, 'spells' => $options];
    }

    /** Вызывается только под CharacterOperation: общий мьютекс, версия, транзакция и повтор. */
    public function learn(int $id, int $spellId, array $body, CharacterSnapshot $snapshot, array $catalog): array
    {
        $option = array_column(self::options($snapshot, $catalog)['spells'], null, 'id')[$spellId] ?? null;
        if (!$option || !$option['allowed']) throw new ApiProblem('learning_unavailable',
            $option ? implode('. ', $option['reasons']) : 'Заклинание уже изучено или отсутствует в вашей литературе. Обновите анкету', 409);
        $instanceId = FieldValues::integer($body['source_instance_id'] ?? null, 'Источник знания', 1, PHP_INT_MAX);
        $source = array_column($option['sources'], null, 'instance_id')[$instanceId] ?? null;
        if (!$source) throw new ApiProblem('learning_source_missing', 'Выберите книгу или свиток из инвентаря персонажа', 409);
        $instance = $this->db->table('roleplay_character_items')->where('character_id', $id)->where('id', $instanceId)->lockForUpdate()->first();
        if (!$instance || $instance->quantity < 1) throw new ApiProblem('learning_source_missing', 'Источник знания больше недоступен. Обновите анкету', 409);
        if ($option['cost'] > 0) (new CharacterBalances($this->db))->adjust($id, 'experience_active', -$option['cost']);
        (new CharacterGrants($this->db))->spell($id, $spellId);
        if ($source['consumed']) {
            if ($instance->quantity > 1) $this->db->table('roleplay_character_items')->where('id', $instanceId)->decrement('quantity');
            else $this->db->table('roleplay_character_items')->where('id', $instanceId)->delete();
        }
        return ['fields' => [], 'event' => 'character_developed', 'payload' => ['kind' => 'spell', 'target_id' => $spellId,
            'name' => $option['spell']['name'], 'level' => $option['spell']['level'], 'experience_spent' => $option['cost'],
            'source' => $source['name'], 'source_item_id' => $source['item_id'], 'source_instance_id' => $instanceId,
            'source_consumed' => $source['consumed']]];
    }
}
