import Feedback from '../../../common/components/Feedback';
import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../../common/components/RoleplayModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import type {Lookups} from '../../../common/catalogData';
import {damage, roman} from '../../../common/presentation';
import {FieldLabel} from '../../../common/catalog/components/FormFields';
import {uiIcon} from '../../../common/uiIcons';
import {allowedTypes, attributeDefinition, ruleTypes} from './presentation';
import {sameThreshold, type AttributeKey, type DevelopmentRule, type RuleType} from './types';

interface Attrs extends IInternalModalAttrs {
  attribute: AttributeKey; maximum: number; rule: DevelopmentRule | null; rules: DevelopmentRule[];
  lookups: Lookups; onSaved: (rule: DevelopmentRule) => void;
}

/** Окно меняет только черновик. Постоянный ключ создаётся один раз и сохраняется при переносе. */
export default class DevelopmentRuleModal extends Modal<Attrs> {
  rule!: DevelopmentRule;
  error = '';
  oninit(vnode: Mithril.Vnode<Attrs, this>): void {
    super.oninit(vnode);
    this.rule = this.attrs.rule ? {...this.attrs.rule} : {
      id: 'rule-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12), level: 1, type: 'skill', value: 1,
    };
  }
  protected modalClassName(): string {return 'RpCatalogEditor RpDevelopmentRuleModal';}
  protected modalHeading(): ModalHeading {return {label: this.attrs.rule ? 'Редактирование правила' : 'Создание правила', purpose: this.attrs.rule ? 'edit' : 'create'};}
  protected wrapper(children: Mithril.Children): Mithril.Children {
    return <form oninput={() => {this.error = '';}} onchange={() => {this.error = '';}}
      onsubmit={(event: Event) => {event.preventDefault(); this.save();}}>{children}</form>;
  }
  content(): Mithril.Children {
    const rule = this.rule, numeric = rule.type !== 'automatic';
    const proficiencyAttribute = attributeDefinition(this.attrs.lookups, this.attrs.attribute)?.id;
    const proficiencies = (this.attrs.lookups.proficiencies ?? []).filter(row => row.attribute_id === proficiencyAttribute);
    const damageOptions = [...new Set([0, 1, 3, 6, 9, 18, 27, 54, 81, Number(rule.value)])].filter(Number.isFinite).sort((a, b) => a - b);
    return <div className="Modal-body">
      {this.error && <Feedback message={this.error}/>}
      <div className="RpDevelopment-editorGrid">
        <label><FieldLabel label="Уровень" icon="fields.level"/>
          <input className="FormControl" type="number" min={1} max={this.attrs.maximum - 1} step={1} required value={rule.level}
            oninput={(event: Event) => {rule.level = Number((event.target as HTMLInputElement).value);}}/></label>
        <label><FieldLabel label="Действие" icon="fields.operation"/>
          <select className="FormControl" value={rule.type} onchange={(event: Event) => {
            rule.type = (event.target as HTMLSelectElement).value as RuleType;
            rule.value = rule.type === 'automatic' ? String(proficiencies[0]?.slug ?? '') : 1;
            rule.description = null; this.error = '';
          }}>{allowedTypes[this.attrs.attribute].map(type => <option key={type} value={type}>{ruleTypes[type].label}</option>)}</select></label>
      </div>
      <label className="RpDevelopment-editorValue"><FieldLabel label={rule.type === 'automatic' ? 'Владение' : rule.type === 'skill' || rule.type === 'proficiency' ? 'Количество выборов' : 'Значение'} icon={ruleTypes[rule.type].icon}/>
        {rule.type === 'skill' || rule.type === 'proficiency' ? <input className="FormControl" type="number" min={1} max={10} step={1} required value={rule.value}
          oninput={(event: Event) => {rule.value = Number((event.target as HTMLInputElement).value);}}/>
          : <select className="FormControl" required value={rule.value} onchange={(event: Event) => {const value = (event.target as HTMLSelectElement).value; rule.value = numeric ? Number(value) : value;}}>
            {rule.type === 'automatic' ? [<option key="empty" value="">Выбери владение</option>, ...proficiencies.map(row => <option key={row.id} value={String(row.slug)}>{row.name}{row.enabled === false ? ' (выключено)' : ''}</option>)]
              : rule.type === 'circle' ? [0, 1, 2, 3, 4, 5].map(value => <option key={value} value={value}>{value ? roman(value) + ' круг' : 'Нет доступа'}</option>)
                : damageOptions.map(value => <option key={value} value={value}>{damage(value)}</option>)}
          </select>}
      </label>
      <label>Текст для страницы правил
        <textarea className="FormControl" rows={3} maxLength={1000} value={rule.description ?? ''}
          oninput={(event: Event) => {rule.description = (event.target as HTMLTextAreaElement).value;}}/>
      </label>
      <p className="helpText">Оставь пустым для стандартной формулировки. Подстановки: {rule.type === 'skill' ? '{value}, {skill_levels}'
        : rule.type === 'proficiency' ? '{value}, {proficiency_choices}' : rule.type === 'automatic' ? '{proficiency}'
          : rule.type === 'circle' ? '{value}, {circle}' : '{value}, {damage}'}. Значения обновляются из настроек.</p>
      <div className="RpEditorActions"><Button type="submit" className="Button Button--primary" icon={uiIcon('actions.select')}>Применить</Button></div>
    </div>;
  }
  private save(): void {
    if (this.attrs.rules.some(row => row.id !== this.rule.id && sameThreshold(row, this.rule))) {
      this.error = 'Такое действие уже задано на этом уровне. Уберите повторную запись или выберите другое действие'; return;
    }
    this.attrs.onSaved({...this.rule}); this.hide();
  }
}
