<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Skills;

use Illuminate\Database\ConnectionInterface;

/** Уровни являются частью определения навыка, а не самостоятельным административным разделом. */
final class SkillLevelStore
{
    public function __construct(private ConnectionInterface $db) {}

    /** Один запрос на весь список: редакторы и селекторы не создают по запросу на каждый навык. */
    public function forSkills(array $skillIds): array
    {
        if (!$skillIds) {
            return [];
        }
        $result = [];
        foreach ($this->db->table('roleplay_skill_levels')->whereIn('skill_id', $skillIds)
            ->orderBy('level')->get() as $row) {
            $result[(int) $row->skill_id][] = [
                'level' => (int) $row->level,
                'perk_id' => $row->perk_id === null ? null : (int) $row->perk_id,
            ];
        }
        return $result;
    }

    /** Вызывается только из сохранения навыка, внутри общей транзакции справочника. */
    public function replace(int $skillId, array $levels): void
    {
        $this->db->table('roleplay_skill_levels')->where('skill_id', $skillId)->delete();
        $rows = array_map(fn (array $level): array => ['skill_id' => $skillId] + $level, $levels);
        $this->db->table('roleplay_skill_levels')->insert($rows);
    }
}