import Icon from 'flarum/common/components/Icon';
import type CreationState from '../CreationState';
import {race, raceRules, signed} from '../helpers';
import {object, text} from '../../../common/catalogData';
import {uiIcon} from '../../../common/uiIcons';

export default function BasicsStep(state: CreationState): JSX.Element {
  const payload = state.view!.draft.payload;
  const currentRace = race(state);
  const bonuses = object(raceRules(state).fixed_bonuses);
  return <div className="RpWizard-basics">
    <label>Имя<input className="FormControl" maxlength={100} placeholder="Введите имя персонажа…"
      value={payload.first_name} oninput={(event: Event) => state.change(() => {
        payload.first_name = (event.target as HTMLInputElement).value;
      })}/></label>
    <label>Фамилия<input className="FormControl" maxlength={100} placeholder="Введите фамилию персонажа…"
      value={payload.last_name} oninput={(event: Event) => state.change(() => {
        payload.last_name = (event.target as HTMLInputElement).value;
      })}/></label>
    <div className="RpWizard-person">
      <label>Возраст<input className="FormControl" type="number" min={Number(currentRace?.min_age ?? 0)}
        max={Number(currentRace?.max_age ?? 1000000)} value={payload.age ?? ''} placeholder="…"
        oninput={(event: Event) => state.change(() => {
          const value = (event.target as HTMLInputElement).value;
          payload.age = value === '' ? null : Number(value);
        })}/></label>
      <label>Раса<select className="FormControl" value={payload.race_id ?? ''} onchange={(event: Event) => state.change(() => {
        const value = (event.target as HTMLSelectElement).value;
        payload.race_id = value ? Number(value) : null;
        // Только расовые выборы сбрасываем явно. Обычные сервер перепроверит по новым характеристикам.
        payload.racial_attributes = []; payload.racial_proficiency_ids = [];
        payload.perk_ids = []; payload.racial_spell_ids = [];
      })}><option value="">Выберите расу</option>{state.view!.catalog.definitions.races.map(row =>
        <option key={row.id} value={row.id}>{row.name}</option>)}</select></label>
      {currentRace?.rules_url && <a className="RpWizard-raceLink" href={text(currentRace, 'rules_url')}
        target="_blank" rel="noopener noreferrer" title="Правила расы" aria-label="Открыть правила расы">
        <Icon name={uiIcon('wizard.rules')}/></a>}
    </div>
    <div className="RpWizard-bonuses">{state.view!.catalog.definitions.attributes
      .filter(row => Number(bonuses[text(row, 'slug')])).map(row =>
        <span key={row.id}>{row.name} <strong>{signed(Number(bonuses[text(row, 'slug')]))}</strong></span>)}</div>
  </div>;
}
