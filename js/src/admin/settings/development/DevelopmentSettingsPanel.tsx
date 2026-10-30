import {clearInputFeedback} from '../../../common/messageKind';
import Component, {type ComponentAttrs} from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import SettingsState from '../SettingsState';
import SettingsHeader from '../SettingsHeader';
import SettingsFeedback from '../SettingsFeedback';
import {FieldLabel} from '../../../common/catalog/components/FormFields';
import showModal from '../../../common/showModal';
import {catalogueIcon, uiIcon} from '../../../common/uiIcons';
import {text} from '../../../common/catalogData';
import DevelopmentSettingsApi from './DevelopmentSettingsApi';
import DevelopmentRuleModal from './DevelopmentRuleModal';
import {attributeDefinition, attributeKeys, growthLabels, ruleTypes, ruleValue} from './presentation';
import {sameThreshold, type AttributeKey, type DevelopmentRule, type DevelopmentView} from './types';

/** Список по уровням: одна строка — одно правило. Совпадающие уровни не объединяем.
 * Все изменения остаются черновиком до нажатия общей кнопки сохранения.
 */
export default class DevelopmentSettingsPanel extends Component {
  settings!: SettingsState<DevelopmentView>;
  selected: AttributeKey = 'physiology';
  private dirty = false;
  oninit(vnode: Mithril.Vnode<ComponentAttrs, this>): void {
    super.oninit(vnode);
    const api = new DevelopmentSettingsApi();
    this.settings = new SettingsState(() => api.read(), data => api.save(data), () => m.redraw());
    void this.settings.load();
  }
  onremove(): void {this.settings.dispose();}
  private changed(): void {clearInputFeedback(this.settings); this.dirty = true; this.settings.saved = false;}
  private async refresh(): Promise<void> {await this.settings.load(); if (!this.settings.error) this.dirty = false;}
  private async save(): Promise<void> {await this.settings.save(); if (this.settings.saved) this.dirty = false;}
  private canMove(rule: DevelopmentRule, direction: number): boolean {
    const configuration = this.settings.data!.configuration;
    const moved = {...rule, level: rule.level + direction};
    return moved.level > 0 && moved.level < configuration.maximum_level
      && !configuration.attributes[this.selected].rules.some(other => other.id !== rule.id && sameThreshold(other, moved));
  }
  private move(rule: DevelopmentRule, direction: number): void {
    if (!this.canMove(rule, direction)) return;
    rule.level += direction; this.changed();
  }
  private edit(rule: DevelopmentRule | null): void {
    const data = this.settings.data!;
    // Захватываем характеристику, чтобы ответ окна не зависел от последующего переключения вкладки.
    const attribute = this.selected;
    showModal(DevelopmentRuleModal, {attribute, rule, maximum: data.configuration.maximum_level,
      rules: data.configuration.attributes[attribute].rules, lookups: data.lookups, onSaved: updated => {
        const rows = data.configuration.attributes[attribute].rules;
        const index = rows.findIndex(row => row.id === updated.id);
        if (index < 0) rows.push(updated); else rows[index] = updated;
        this.changed();
      }});
  }
  view(): Mithril.Children {
    const state = this.settings, data = state.data;
    const disabled = state.loading || state.saving || state.stale;
    if (!data) return <section className="RpSettings-panel RpDevelopment"><SettingsHeader title="Развитие" icon="settings.development" disabled={disabled} refresh={() => void this.refresh()}/><SettingsFeedback state={state}/></section>;
    const configuration = data.configuration, attribute = configuration.attributes[this.selected];
    const rules = [...attribute.rules].sort((a, b) => a.level - b.level || a.id.localeCompare(b.id));
    const count = (type: string) => rules.filter(rule => rule.type === type).reduce((sum, rule) => sum + Number(rule.value), 0);
    const input = (label: string, value: number, change: (value: number) => void, min = 0, max = 1000000, step = 1): Mithril.Children =>
      <label className="RpDevelopment-number">{label}<input className="FormControl" type="number" required min={min} max={max} step={step}
        value={value} disabled={disabled} oninput={(event: Event) => {change(Number((event.target as HTMLInputElement).value)); this.changed();}}/></label>;
    return <section className="RpSettings-panel RpDevelopment">
      <SettingsHeader title="Развитие" icon="settings.development" disabled={disabled} refresh={() => void this.refresh()}/>
      {/* Сообщение об успешном сохранении находится внизу рядом с общей кнопкой. */}
      <SettingsFeedback state={{loading: state.loading, error: state.error, saved: false}}/>
      <form oninput={() => clearInputFeedback(state)} onchange={() => clearInputFeedback(state)} onsubmit={(event: Event) => {event.preventDefault(); void this.save();}}>
        <details className="RpDevelopment-general"><summary><Icon name={uiIcon('profile.selectChevron')}/> Общие правила</summary>
          <div className="RpDevelopment-numbers">
            {input('Максимум характеристики', configuration.maximum_level, value => {configuration.maximum_level = value;}, 3, 255)}
            {input('Навык II · характеристика', configuration.skill_minimums[2], value => {configuration.skill_minimums[2] = value;}, 2, configuration.maximum_level)}
            {input('Навык III · характеристика', configuration.skill_minimums[3], value => {configuration.skill_minimums[3] = value;}, 3, configuration.maximum_level)}
          </div>
          <h3><Icon name={uiIcon('resources.activeExperience')}/> Стоимость повышения, опыт</h3>
          <div className="RpDevelopment-numbers">{configuration.experience_costs.map((range, index) => {
            const last = (configuration.experience_costs[index + 1]?.minimum ?? configuration.maximum_level + 1) - 1;
            return <div key={range.minimum}>{input(range.minimum + '–' + last + ' уровень', range.cost, value => {range.cost = value;}, 0, Number.MAX_SAFE_INTEGER)}</div>;
          })}</div>
          <h3><Icon name={uiIcon('sections.spells')}/> Изучение заклинаний, опыт</h3>
          <div className="RpDevelopment-numbers">{[1, 2, 3, 4, 5].map((circle, index) =>
            <div key={circle}>{input(['I', 'II', 'III', 'IV', 'V'][index] + ' круг', configuration.spell_learning_costs?.[circle] ?? [5, 10, 15, 20, 30][index], value => {
              configuration.spell_learning_costs ??= {1: 5, 2: 10, 3: 15, 4: 20, 5: 30};
              configuration.spell_learning_costs[circle] = value;
            })}</div>)}</div>
        </details>
        <nav className="RpDevelopment-attributes" aria-label="Характеристика развития">{attributeKeys.map(key => {
          const definition = attributeDefinition(data.lookups, key);
          return <Button key={key} className={'Button ' + (this.selected === key ? 'is-selected' : '')} disabled={state.loading || state.saving}
            aria-pressed={this.selected === key} onclick={() => {this.selected = key;}}>
            <Icon name={catalogueIcon(definition ? text(definition, 'icon') : '')}/>{definition?.name ?? key}</Button>;
        })}</nav>
        <p className="RpDevelopment-summary">Выборов навыка: {count('skill')} · Владений на выбор: {count('proficiency')}
          {rules.some(rule => rule.type === 'automatic') && ' · Автоматических владений: ' + rules.filter(rule => rule.type === 'automatic').length}</p>
        <div className="RpDevelopment-growth">{Object.entries(attribute.growth).map(([key, values]) => <div key={key}>
          <strong><FieldLabel label={growthLabels[key].label} icon={growthLabels[key].icon}/></strong>
          <div className="RpDevelopment-growthFields">
            {input('На I уровне', values.initial, value => {values.initial = value;}, 0, 1000000, key === 'carry_capacity' ? 0.001 : 1)}
            {input('За следующий уровень', values.per_level, value => {values.per_level = value;}, 0, 1000000, key === 'carry_capacity' ? 0.001 : 1)}
          </div>
        </div>)}</div>
        {Object.keys(attribute.growth).length > 0 && <label>Текст начала шкалы развития
          <textarea className="FormControl" rows={2} maxLength={1000} disabled={disabled} value={attribute.initial_description ?? ''}
            oninput={(event: Event) => {attribute.initial_description = (event.target as HTMLTextAreaElement).value; this.changed();}}/>
          <span className="helpText">Подстановки: {Object.keys(attribute.growth).map(key => '{' + key + '}').join(', ')}. Пустое поле скрывает вступление.</span>
        </label>}
        <div className="RpDevelopment-tableHeading"><strong>Пороги развития</strong>
          <Button className="Button" icon={uiIcon('actions.create')} disabled={disabled} onclick={() => this.edit(null)}>Добавить</Button></div>
        <table className="RpDevelopment-table"><thead><tr><th scope="col">Уровень</th><th scope="col">Что открывается</th><th scope="col" aria-label="Действия"/></tr></thead>
          <tbody>{rules.map(rule => <tr key={rule.id}>
            <td><div className="RpDevelopment-level"><span>{rule.level}</span><div className="RpDevelopment-levelArrows">{[-1, 1].map(direction =>
              <Button key={direction} className="Button Button--icon" icon={uiIcon(direction < 0 ? 'actions.moveUp' : 'actions.moveDown')}
                title={direction < 0 ? 'На уровень раньше' : 'На уровень позже'}
                aria-label={(direction < 0 ? 'На уровень раньше: ' : 'На уровень позже: ') + ruleTypes[rule.type].label + ' · ' + rule.level}
                disabled={disabled || !this.canMove(rule, direction)} onclick={() => this.move(rule, direction)}/>)}</div></div></td>
            <td><span className="RpDevelopment-rule"><Icon name={uiIcon(ruleTypes[rule.type].icon)}/><span>{ruleTypes[rule.type].label} <b>{ruleValue(rule, data.lookups)}</b></span></span></td>
            <td><div className="RpDevelopment-ruleActions">
              <Button className="Button Button--icon" icon={uiIcon('actions.edit')} title="Редактировать" aria-label={'Редактировать: ' + ruleTypes[rule.type].label + ' · ' + rule.level}
                disabled={disabled} onclick={() => this.edit(rule)}/>
              <Button className="Button Button--icon" icon={uiIcon('actions.delete')} title="Удалить" aria-label={'Удалить: ' + ruleTypes[rule.type].label + ' · ' + rule.level}
                disabled={disabled} onclick={() => {attribute.rules = attribute.rules.filter(row => row.id !== rule.id); this.changed();}}/>
            </div></td>
          </tr>)}</tbody></table>
        <div className="RpDevelopment-reserved"><b>{configuration.maximum_level}</b><Icon name={uiIcon('profile.effects')}/> Особый уровень · резерв</div>
        <footer className="RpSettings-footer"><span className="RpDevelopment-status" role="status">{this.dirty ? 'Есть изменения' : 'Сохранено'}</span>
          <Button type="submit" className="Button Button--primary" icon={uiIcon('actions.save')} disabled={disabled || !this.dirty} loading={state.saving}>Сохранить изменения</Button></footer>
      </form>
    </section>;
  }
}
