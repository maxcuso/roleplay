import type CreationState from '../CreationState';
import {race, raceRules, toggle, source} from '../helpers';
import {ids, lookup} from '../../../common/catalogData';
import PerkCard from '../../../common/cards/PerkCard';
import SelectableEntityCard from '../../../common/cards/SelectableEntityCard';

export default function PerksStep(state: CreationState): JSX.Element {
  const view = state.view!, payload = view.draft.payload;
  const rules = raceRules(state), count = Number(rules.perk_choice_count ?? 0);
  const fixed = view.preview.perks.filter(grant => !payload.perk_ids.includes(grant.id));
  const sourceId = view.catalog.configuration.perk_sources.race;
  const racialSource = lookup(view.catalog.definitions, 'sources', sourceId);
  return <div>
    <p className="RpWizard-instruction">Ознакомьтесь с перками персонажа</p>
    <div className="RpWizard-cardGrid">{fixed.map(grant => {
      const perk = lookup(view.catalog.definitions, 'perks', grant.id);
      return perk && <PerkCard key={grant.id} mode="expanded" perk={{...perk, rarity: grant.rarity}} source={source(state, grant)}/>;
    })}</div>
    {(count > 0 || payload.perk_ids.length > 0) && <section className="RpWizard-choiceSection">
      <p className="RpWizard-instruction">Перки по выбору – выбрано <strong>{payload.perk_ids.length} из {count}</strong></p>
      <div className="RpWizard-cardGrid">{Array.from(new Set([...ids(rules.perk_choice_ids), ...payload.perk_ids])).map(id => {
        const perk = lookup(view.catalog.definitions, 'perks', id);
        const selected = payload.perk_ids.includes(id);
        if (!perk) return <SelectableEntityCard key={id} name="Убрать недоступный перк" selected={selected}
          disabled={state.dirty || state.busy || !selected} onchange={() => state.change(() => {payload.perk_ids = toggle(payload.perk_ids, id);})}>
          <p>Перк больше недоступен. Нажмите, чтобы убрать его из выбранных</p></SelectableEntityCard>;
        const grant = {id, source_id: sourceId ?? 0, kind: 'race', rarity: 'common',
          context: {race_name: race(state)?.name}};
        return <SelectableEntityCard key={id} name={perk.name} selected={selected}
          disabled={state.dirty || state.busy || !selected && payload.perk_ids.length >= count}
          onchange={() => state.change(() => {payload.perk_ids = toggle(payload.perk_ids, id);})}>
          <PerkCard mode="expanded" perk={{...perk, rarity: 'common'}}
            source={racialSource ? source(state, grant) : undefined}/>
        </SelectableEntityCard>;
      })}</div>
    </section>}
    {!fixed.length && count === 0 && <p className="RpWizard-empty">Стартовые перки отсутствуют.</p>}
  </div>;
}
