import Icon from 'flarum/common/components/Icon';
import type CreationState from '../CreationState';
import {canAdjustAttribute, raceRules} from '../helpers';
import {object, text} from '../../../common/catalogData';
import {catalogueIcon} from '../../../common/uiIcons';

export default function AttributesStep(state: CreationState): JSX.Element {
  const payload = state.view!.draft.payload;
  const rules = raceRules(state);
  const rows = state.view!.catalog.definitions.attributes;
  const distribution = state.view!.catalog.configuration.attribute_distribution;
  const minimum = Math.min(...distribution);
  const values = rows.map(row => Number(payload.base_attributes[text(row, 'slug')] ?? minimum));
  const left = distribution.reduce((sum, value) => sum + value, 0)
    - values.reduce((sum, value) => sum + value, 0);
  const fixed = object(rules.fixed_bonuses);
  const choices = Array.isArray(rules.choice_attributes) ? rules.choice_attributes : [];
  const count = Number(rules.attribute_count ?? 0);
  return <div>
    <p className="RpWizard-instruction">Выберите стартовые характеристики. Очков осталось: <strong>{left}</strong></p>
    <p className="RpWizard-help">Каждая характеристика — от {minimum} до {Math.max(...distribution)} до расовых бонусов.</p>
    <div className="RpWizard-attributes">
      <div className="RpWizard-attributeRow">{rows.map((row, index) => {
        const slug = text(row, 'slug'), value = Number(payload.base_attributes[slug] ?? minimum);
        return <div key={row.id} className="RpWizard-attribute">
          <Icon name={catalogueIcon(text(row, 'icon'))}/><span className="RpWizard-value">{value}</span>
          <div className="RpWizard-adjust">
            <button type="button" disabled={!canAdjustAttribute(distribution, values, index, -1)} aria-label={'Уменьшить: ' + row.name}
              onclick={() => state.adjustAttribute(slug, -1)}>−</button>
            <button type="button" disabled={!canAdjustAttribute(distribution, values, index, 1)} aria-label={'Увеличить: ' + row.name}
              onclick={() => state.adjustAttribute(slug, 1)}>+</button>
          </div>
        </div>;
      })}</div>
      {count > 0 && <div className="RpWizard-racialBonuses">
        <strong>Бонус от расы {payload.racial_attributes.length}/{count}</strong>
        <div className="RpWizard-attributeRow">{rows.map(row => {
          const slug = text(row, 'slug'), selected = payload.racial_attributes.includes(slug);
          return <label key={row.id} title={row.name}>
            <input type="checkbox" aria-label={'Расовый бонус: ' + row.name} checked={selected}
              disabled={!choices.includes(slug) || !selected && payload.racial_attributes.length >= count}
              onchange={() => state.change(() => {
                payload.racial_attributes = selected ? payload.racial_attributes.filter(value => value !== slug)
                  : [...payload.racial_attributes, slug];
              })}/>
          </label>;
        })}</div>
      </div>}
      <div className="RpWizard-attributeRow RpWizard-totals">{rows.map(row => {
        const slug = text(row, 'slug');
        const bonus = Number(fixed[slug] ?? 0) + (payload.racial_attributes.includes(slug) ? Number(rules.attribute_bonus ?? 0) : 0);
        return <strong key={row.id} title={row.name}>{Number(payload.base_attributes[slug] ?? minimum) + bonus}</strong>;
      })}</div>
    </div>
  </div>;
}
