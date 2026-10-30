import {catalogueIcon, uiIcon, type UiIconName} from '../uiIcons';
import {lookup, object, text, type Fields, type Lookups} from '../catalogData';
import {damage} from '../presentation';

// Формулировки для отображения. Здесь нет расчёта величин: берём готовую привязку перка/предмета.
const statTargets: Record<string, string> = {
  mana_max: 'к общему запасу маны',
  stamina_max: 'к общему запасу выносливости',
  carry_capacity: 'к грузоподъёмности',
  melee_damage: 'к урону в ближнем бою',
  ranged_damage: 'к урону в дальнем бою / метательным оружием',
  magic_damage: 'к магическому урону',
  all_attacks: 'к урону всех атак',
  ignored_damage: 'к игнорируемому урону',
};
const actionTargets: Record<string, string> = {
  shop_discount: 'к скидке в магазине',
  quest_experience: 'к опыту за квесты',
  extra_dodges: 'к количеству уклонений',
  mana_recovery: 'к восстановлению маны',
  stamina_recovery: 'к восстановлению выносливости',
  incoming_damage: 'к входящему урону',
  all_checks: 'ко всем проверкам характеристик и навыков',
};
const numeric = new Intl.NumberFormat('ru', {maximumFractionDigits: 3});

/** Числовая строка собирается из механики, цели и величины: отдельное описание не требуется. */
export function effectLabel(effect: Fields, lookups: Lookups = {}): string {
  const definition = object(effect.definition);
  const mechanic = text(definition, 'mechanic');
  let label = text(effect, 'description') || text(effect, 'name');
  if (mechanic !== 'text' && effect.value !== null && effect.value !== undefined) {
    const value = Number(effect.value);
    const sign = value < 0 ? '−' : '+';
    const operation = text(definition, 'operation');
    let amount = sign + numeric.format(Math.abs(value));
    let target = actionTargets[mechanic] ?? '';
    if (mechanic === 'stat') {
      const stat = text(definition, 'stat');
      target = statTargets[stat] ?? '';
      if (value !== 0 && operation === 'add' && ['melee_damage', 'ranged_damage', 'magic_damage', 'all_attacks', 'ignored_damage'].includes(stat)) {
        amount = sign + damage(Math.abs(value)).toLocaleLowerCase('ru');
      } else if (stat === 'carry_capacity' && operation === 'add') amount += ' кг';
    } else if (mechanic === 'attribute' || mechanic === 'attribute_check') {
      const name = lookup(lookups, 'attributes', definition.attribute_id)?.name;
      if (name) target = mechanic === 'attribute' ? 'к характеристике «' + name + '»' : 'к проверкам «' + name + '»';
    } else if (mechanic === 'skill_check') {
      const name = lookup(lookups, 'skills', definition.skill_id)?.name;
      if (name) target = 'к проверкам навыка «' + name + '»';
    }
    if (operation === 'base_percent') {
      amount += '%';
      if (mechanic === 'stat') target = 'базового урона';
      else if (mechanic === 'skill_check') target = 'базового бонуса навыка «'
        + (lookup(lookups, 'skills', definition.skill_id)?.name ?? text(effect, 'name')) + '»';
    } else if (operation === 'percent' || mechanic === 'shop_discount') amount += '%';
    else if (operation === 'tiers') amount += ' ступ.';
    label = target ? amount + ' ' + target : amount + ' · ' + text(effect, 'name');
  }
  // Условный бонус не должен выглядеть как постоянный бонус общих параметров.
  if (effect.condition === 'unarmed') label += ' (без оружия)';
  else if (effect.condition === 'manual' && text(effect, 'condition_text')) label += ' — ' + text(effect, 'condition_text');
  if (text(definition, 'stack_group')) label += ' (не складывается)';
  if (effect.scope === 'item_attack') label += ' (атака этим предметом)';
  return label;
}

export function effectSource(effect: Fields): string {
  const source = object(effect.source);
  return (source.kind === 'item' ? 'Предмет: ' : 'Перк: ') + text(source, 'label');
}

/** Иконка по механике/цели. Явное оформление владельца имеет приоритет. */
export function effectIcon(effect: Fields, lookups: Lookups = {}): string {
  if (text(effect, 'icon')) return catalogueIcon(text(effect, 'icon'));
  const definition = object(effect.definition);
  if (definition.attribute_id) {
    const attribute = lookup(lookups, 'attributes', definition.attribute_id);
    if (attribute && text(attribute, 'icon')) return catalogueIcon(text(attribute, 'icon'));
  }
  const targets: Record<string, UiIconName> = {
    text: 'effects.text', all_checks: 'effects.allChecks',
    mana_max: 'resources.mana', stamina_max: 'resources.stamina', carry_capacity: 'resources.weight',
    melee_damage: 'combat.melee', ranged_damage: 'combat.ranged', magic_damage: 'combat.magic',
    ignored_damage: 'combat.defense', all_attacks: 'fields.damage',
    mana_recovery: 'resources.mana', stamina_recovery: 'resources.stamina',
    shop_discount: 'resources.gold', skill_check: 'sections.training',
    quest_experience: 'resources.activeExperience',
    incoming_damage: 'combat.defense',
  };
  return uiIcon(targets[text(definition, 'stat')] ?? targets[text(definition, 'mechanic')] ?? 'sections.effects');
}
