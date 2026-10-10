import Icon from 'flarum/common/components/Icon';
import type {EditorContext, Fields} from '../types';
import {object, text} from '../helpers';
import {uiIcon, type UiIconName} from '../../uiIcons';
import {effectRules, effectMechanic, effectConditions, isAttackTarget, isDamageTarget} from '../../effectRules';
import {effectIcon, effectLabel} from '../../character/effectPresentation';
import EditorSection from './EditorSection';
import {FieldLabel} from './FormFields';

interface Context extends EditorContext {owner: 'perks' | 'items'}

/** Один редактор для обоих владельцев. Читаем актуальный черновик при каждом вводе. */
export default function EffectAttachmentsEditor({state, lookups, owner}: Context): JSX.Element {
  const rows = (): Fields[] => Array.isArray(state.values.effects) ? state.values.effects.map(object) : [];
  const update = (index: number, patch: Fields): void =>
    state.set('effects', rows().map((row, position) => position === index ? {...row, ...patch} : row));
  return <EditorSection title="Эффекты" icon="sections.effects">
    {rows().map((row, index) => {
      const rule = effectMechanic(row.mechanic);
      const preview = {...row, definition: row};
      const allChecks = row.mechanic === 'all_checks';
      const attributeCheck = allChecks || row.mechanic === 'attribute_check';
      const attack = row.mechanic === 'stat' && isAttackTarget(row.stat);
      const fractional = ['percent', 'base_percent'].includes(String(row.operation)) || row.stat === 'carry_capacity' || row.mechanic === 'shop_discount';
      // При смене механики/цели очищаем только несовместимые настройки, а не соседние эффекты.
      const mechanic = (value: string): void => update(index, {mechanic: value, operation: 'add',
        stat: value === 'stat' ? 'mana_max' : null, attribute_id: null, skill_id: null,
        value: effectMechanic(value).numeric ? 0 : null, condition: effectConditions(value)[0], condition_text: null, scope: 'all', stack_group: null});
      const select = (field: string, label: string, icon: UiIconName, choices: Record<string, string>,
        change?: (value: string) => void, selected?: string): JSX.Element => <label className="RpField">
        <FieldLabel label={label} icon={icon}/><select className="FormControl" value={selected ?? text(row, field)}
          aria-label={label + ' эффекта ' + (index + 1)} onchange={(event: Event) => {
            const value = (event.target as HTMLSelectElement).value;
            if (change) change(value); else update(index, {[field]: value});
          }}>{Object.entries(choices).map(([key, title]) => <option key={key} value={key}>{title}</option>)}</select>
      </label>;
      const input = (field: string, label: string, icon: UiIconName, required = false): JSX.Element =>
        <label className="RpField"><FieldLabel label={label} icon={icon}/>
          <input className="FormControl" required={required} maxlength={field === 'condition_text' ? 1000 : 100}
            value={text(row, field)} aria-label={label + ' эффекта ' + (index + 1)}
            oninput={(event: Event) => update(index, {[field]: (event.target as HTMLInputElement).value || null})}/></label>;
      const targets = (key: string): Record<string, string> => Object.fromEntries([
        ['', 'Выбери цель'], ...(lookups[key] ?? []).map(value => [String(value.id), value.name]),
      ]);
      const conditions = Object.fromEntries(Object.entries(effectRules.conditions).filter(([key]) =>
        effectConditions(row.mechanic).includes(key) &&
        (key !== 'unarmed' || (row.mechanic === 'stat' && row.stat === 'melee_damage' && row.scope !== 'item_attack'))));
      const operations = Object.fromEntries(rule.operations.filter(key => (key !== 'tiers' || rule.target !== 'stat' || isDamageTarget(row.stat))
        && (key !== 'base_percent' || rule.target !== 'stat' || isAttackTarget(row.stat)))
        .map(key => [key, effectRules.operations[key as keyof typeof effectRules.operations]]));
      return <div className="RpEffectAttachment" key={index}>
        <div className="RpEffectAttachment-title">
          <span><Icon name={effectIcon(preview, lookups)}/> {effectLabel(preview, lookups) || 'Новый эффект'}</span>
          <button type="button" className="Button Button--icon" aria-label={'Убрать эффект ' + (index + 1)}
            onclick={() => state.set('effects', rows().filter((_, position) => position !== index))}>
            <Icon name={uiIcon('actions.delete')}/></button>
        </div>
        <div className="RpGrid">
          {select('mechanic', 'Механика', 'fields.mechanic', Object.fromEntries(
            Object.entries(effectRules.mechanics).filter(([key]) => key !== 'all_checks').map(([key, value]) => [key, value.label])),
            mechanic, allChecks ? 'attribute_check' : undefined)}
          {rule.target === 'stat' && select('stat', 'Параметр', 'fields.value', effectRules.stats, value =>
            update(index, {stat: value, operation: (row.operation === 'tiers' && !isDamageTarget(value)) || (row.operation === 'base_percent' && !isAttackTarget(value)) ? 'add' : row.operation,
              condition: 'always', condition_text: null, scope: 'all'}))}
          {(rule.target === 'attribute' || allChecks) && select('attribute_id', 'Характеристика', 'fields.attribute',
            {...targets('attributes'), ...(attributeCheck ? {all: 'Все характеристики'} : {})}, value => {
              // «Все» относится только к проверкам: значения и уровни не повышаются.
              update(index, {attribute_id: value && value !== 'all' ? Number(value) : null,
                mechanic: attributeCheck ? (value === 'all' ? 'all_checks' : 'attribute_check') : row.mechanic});
            }, allChecks ? 'all' : undefined)}
          {rule.target === 'skill' && select('skill_id', 'Навык', 'sections.training', targets('skills'),
            value => update(index, {skill_id: value ? Number(value) : null}))}
          {rule.numeric && <label className="RpField"><FieldLabel label={['percent', 'base_percent'].includes(String(row.operation)) ? 'Величина, %' : 'Величина'} icon="fields.value"/>
            <input className="FormControl" type="number" required step={fractional ? 0.001 : 1}
              min={row.operation === 'tiers' ? -12 : -1000000} max={row.operation === 'tiers' ? 12 : 1000000}
              value={row.value ?? ''} aria-label={'Величина эффекта ' + (index + 1)} oninput={(event: Event) => {
                const value = (event.target as HTMLInputElement).value;
                update(index, {value: value === '' ? null : Number(value)});
              }}/></label>}
          {rule.operations.length > 1 && select('operation', 'Операция', 'fields.operation', operations)}
          {!rule.numeric && input('name', 'Название', 'fields.name', true)}
        </div>
        <details className="RpEffectAttachment-appearance" open={row.condition !== 'always' || row.scope !== 'all' || Boolean(row.stack_group)}>
          <summary><Icon name={uiIcon('fields.condition')}/> Условия и оформление</summary>
          <div className="RpGrid">
          {select('condition', 'Условие', 'fields.condition', conditions, value =>
            update(index, {condition: value, condition_text: null}))}
          {owner === 'items' && attack && select('scope', 'Область', 'fields.scope', effectRules.scopes, value =>
            update(index, {scope: value, condition: 'always', condition_text: null}))}
          </div>
          {row.condition === 'manual' && input('condition_text', 'Когда применяется', 'fields.condition', true)}
          {['stat', 'skill_check', 'attribute_check', 'all_checks'].includes(String(row.mechanic)) &&
            input('stack_group', 'Группа бонусов (применяется наибольший)', 'fields.scope')}
          {input('icon', 'Иконка', 'fields.icon')}
          <label className="RpField"><FieldLabel label="Описание" icon="fields.description"/>
            <textarea className="FormControl" rows={2} maxlength={20000} value={text(row, 'description')}
              oninput={(event: Event) => update(index, {description: (event.target as HTMLTextAreaElement).value || null})}/>
          </label>
        </details>
      </div>;
    })}
    <button type="button" className="Button RpEffects-add" disabled={rows().length >= 100}
      onclick={() => state.set('effects', [...rows(), {name: null, description: null, icon: null,
        mechanic: 'stat', operation: 'add', stat: 'mana_max', attribute_id: null, skill_id: null,
        value: 0, condition: 'always', condition_text: null, scope: 'all'}])}>
      <Icon name={uiIcon('actions.create')}/> Добавить эффект
    </button>
  </EditorSection>;
}
