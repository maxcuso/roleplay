<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Character\Moderation;

use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Источник относится к конкретной выдаче, не к определению перка.
 * Названия связанных рас/навыков получает сервер, а не доверяет подписи клиента.
 */
final class PerkGrantSource
{
    public function context(array $source, array $input, array $definitions): array
    {
        FieldValues::allowed($input, ['race_id', 'skill_id', 'quest_name', 'event_name']);
        $context = [];
        foreach (array_unique(array_filter([$source['name_source'], $source['icon_source']])) as $kind) {
            if ($kind === 'race' || $kind === 'skill') {
                $id = FieldValues::integer($input[$kind.'_id'] ?? null, 'Источник '.$kind, 1, PHP_INT_MAX);
                $definition = $definitions[$kind === 'race' ? 'races' : 'skills'][$id] ?? null;
                if (!$definition) throw new ApiProblem('source_context_missing', 'Связанная запись источника не найдена.');
                $context[$kind.'_id'] = $id;
                $context[$kind.'_name'] = $definition['name'];
                if ($kind === 'skill') {
                    $context['attribute_slug'] = $definitions['attributes'][$definition['attribute_id']]['slug'];
                }
            } else {
                $context[$kind.'_name'] = FieldValues::text($input[$kind.'_name'] ?? null, 'Название источника', 100, true);
            }
        }
        return $context;
    }
}