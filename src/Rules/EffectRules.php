<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Rules;

/** Закрытый набор возможностей. Описание эффекта никогда не исполняется как формула. */
final class EffectRules
{
    public static function configuration(): array
    {
        static $configuration;
        return $configuration ??= json_decode(file_get_contents(__DIR__.'/../../resources/rules/effects.json'),
            true, flags: JSON_THROW_ON_ERROR);
    }

    public static function mechanic(string $key): array
    {
        return self::configuration()['mechanics'][$key]
            ?? throw new \DomainException('Неизвестная механика эффекта.');
    }

    public static function damageTarget(?string $stat): bool
    {
        return in_array($stat, self::configuration()['damage_stats'], true);
    }

    public static function attackTarget(?string $stat): bool
    {
        return in_array($stat, self::configuration()['attack_stats'], true);
    }

    /** Одинаковая проверка для редактора привязок и загрузки состояния. */
    public static function validateLink(array $definition, array $link, string $owner): void
    {
        $mechanic = self::mechanic($definition['mechanic']);
        $value = $link['value'];
        if (($definition['stack_group'] ?? null) !== null
            && (!is_string($definition['stack_group']) || trim($definition['stack_group']) === ''
                || mb_strlen($definition['stack_group']) > 100
                || !in_array($definition['mechanic'], ['stat', 'skill_check', 'attribute_check', 'all_checks'], true))) {
            throw new \DomainException('Группа нескладываемых бонусов доступна для параметров и проверок.');
        }
        if (isset($mechanic['conditions']) && !in_array($link['condition'], $mechanic['conditions'], true)) {
            throw new \DomainException('Этот эффект применяется вручную при указанном условии.');
        }
        if ($definition['operation'] === 'base_percent'
            && !($definition['mechanic'] === 'skill_check'
                || ($definition['mechanic'] === 'stat' && self::attackTarget($definition['stat'])))) {
            throw new \DomainException('Процент от базы доступен для урона атаки или бонуса навыка.');
        }
        if (!$mechanic['numeric']) {
            if ($value !== null) throw new \DomainException('Текстовый эффект не имеет величины.');
        } else {
            $fractional = in_array($definition['operation'], ['percent', 'base_percent'], true) || $definition['stat'] === 'carry_capacity'
                || $definition['mechanic'] === 'shop_discount';
            CalculatedValue::number($value, $fractional);
            $limit = $definition['operation'] === 'tiers' ? 12 : 1000000;
            if (abs($value) > $limit) throw new \DomainException('Величина эффекта слишком велика.');
        }
        if (!isset(self::configuration()['conditions'][$link['condition']])
            || !isset(self::configuration()['scopes'][$link['scope']])) {
            throw new \DomainException('Неизвестное условие или область эффекта.');
        }
        if ($link['condition'] === 'manual' && trim($link['condition_text'] ?? '') === '') {
            throw new \DomainException('Опиши условие применения эффекта.');
        }
        if ($link['condition'] === 'unarmed'
            && ($definition['mechanic'] !== 'stat' || $definition['stat'] !== 'melee_damage')) {
            throw new \DomainException('Безоружное условие допустимо только для урона в ближнем бою.');
        }
        if ($link['scope'] === 'item_attack' && $link['condition'] === 'unarmed') {
            throw new \DomainException('Безоружная атака не может быть атакой предметом.');
        }
        if ($link['scope'] === 'item_attack'
            && ($owner !== 'items' || $definition['mechanic'] !== 'stat' || !self::attackTarget($definition['stat']))) {
            throw new \DomainException('Область отдельного оружия применима только к урону эффекта предмета.');
        }
    }
}
