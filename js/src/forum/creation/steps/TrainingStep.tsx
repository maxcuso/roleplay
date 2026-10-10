import Icon from 'flarum/common/components/Icon';
import type CreationState from '../CreationState';
import {race, raceRules} from '../helpers';
import {catalogueIcon, uiIcon} from '../../../common/uiIcons';
import {lookup, text} from '../../../common/catalogData';
import {roman} from '../../../common/presentation';

export default function TrainingStep(state: CreationState): JSX.Element {
  const view = state.view!, payload = view.draft.payload;
  const count = Number(raceRules(state).proficiency_choice_count ?? 0);
  const racialPool = view.preview.racial_proficiency_ids ?? [];
  return <div>
    <p className="RpWizard-instruction">Выберите навыки и владения.</p>
    {(view.preview.errors.training ?? []).length > 0 && <div className="RpWizard-reset">
      {payload.skills.length > 0 && <button type="button" onclick={() => state.change(() => {payload.skills = [];})}>Сбросить выбор навыков</button>}
      {payload.proficiencies.length > 0 && <button type="button" onclick={() => state.change(() => {payload.proficiencies = [];})}>Сбросить выбор обычных владений</button>}
    </div>}
    <div className="RpWizard-trainingGrid">{view.catalog.definitions.attributes.map(attribute => {
      const slots = view.preview.training_slots.filter(slot => slot.attribute_id === attribute.id);
      return <div key={attribute.id} className="RpWizard-trainingGroup">
        <Icon name={catalogueIcon(text(attribute, 'icon'))}/>
        {slots.length ? slots.map(slot =>
          <label key={slot.kind + ':' + slot.threshold}><span className="sr-only">
            {attribute.name}: {slot.kind === 'skill' ? 'навык' : 'владение'}, порог {slot.threshold}</span>
            <select value={slot.selected_id ?? ''} disabled={state.dirty || state.busy || slot.ordinal > slots
              .filter(previous => previous.kind === slot.kind && previous.selected_id !== null).length}
              onchange={(event: Event) => {
                const value = (event.target as HTMLSelectElement).value;
                state.setTraining(slot, value ? Number(value) : null);
              }}>
              <option value="">Оставить выбор на будущее</option>
              {slot.selected_id && !slot.options.some(option => option.id === slot.selected_id)
                && <option value={slot.selected_id}>Недоступный выбор — измените его</option>}
              {slot.options.map(option => <option key={option.id} value={option.id}>
                {option.name}{option.level ? ' ' + roman(option.level) : ''}
              </option>)}
            </select>
          </label>) : <small>Нет выборов на этом уровне</small>}
      </div>;
    })}</div>
    {(count > 0 || payload.racial_proficiency_ids.length > 0) && <div className="RpWizard-racialTraining">
      <h3><Icon name={uiIcon('sections.races')}/> {race(state)?.name} {payload.racial_proficiency_ids.length}/{count}</h3>
      {Array.from({length: Math.max(count, payload.racial_proficiency_ids.length)}, (_, index) => <label key={index}>
        <span className="sr-only">Расовое владение {index + 1}</span>
        <select disabled={state.dirty || state.busy || index > payload.racial_proficiency_ids.length}
          value={payload.racial_proficiency_ids[index] ?? ''}
          onchange={(event: Event) => state.change(() => {
            const value = Number((event.target as HTMLSelectElement).value);
            const selected = [...payload.racial_proficiency_ids];
            if (value) selected[index] = value; else selected.splice(index, 1);
            payload.racial_proficiency_ids = selected;
          })}>
          <option value="">Выберите владение</option>
          {payload.racial_proficiency_ids[index] && !racialPool.includes(payload.racial_proficiency_ids[index])
            && <option value={payload.racial_proficiency_ids[index]}>Недоступное владение — измените его</option>}
          {racialPool.filter(id => id === payload.racial_proficiency_ids[index] || !payload.racial_proficiency_ids.includes(id))
            .map(id => <option key={id} value={id}>{lookup(view.catalog.definitions, 'proficiencies', id)?.name}</option>)}
        </select>
      </label>)}
    </div>}
  </div>;
}
