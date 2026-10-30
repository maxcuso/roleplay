import Icon from 'flarum/common/components/Icon';
import type CreationState from '../CreationState';
import {race, toggle} from '../helpers';
import {lookup} from '../../../common/catalogData';
import {uiIcon} from '../../../common/uiIcons';
import {roman} from '../../../common/presentation';
import SpellCard from '../../../common/cards/SpellCard';
import SelectableEntityCard from '../../../common/cards/SelectableEntityCard';

export default function SpellsStep(state: CreationState): JSX.Element {
  const view = state.view!, payload = view.draft.payload, choices = view.preview.spell_choices;
  function group(field: 'spell_ids' | 'racial_spell_ids', pool: number[], count: number, title: string, icon: string): JSX.Element {
    return <section className="RpWizard-choiceSection">
      <h3>Выбрано {payload[field].length} из {count}</h3>
      <div className="RpWizard-cardGrid">{Array.from(new Set([...pool, ...payload[field]])).map(id => {
        const spell = lookup(view.catalog.definitions, 'spells', id);
        const selected = payload[field].includes(id);
        if (!spell) return <SelectableEntityCard key={id} name="Убрать недоступное заклинание" selected={selected}
          disabled={state.dirty || state.busy || !selected} onchange={() => state.change(() => {payload[field] = toggle(payload[field], id);})}>
          <p>Заклинание больше недоступно. Нажмите, чтобы убрать его из выбранных</p></SelectableEntityCard>;
        return <SelectableEntityCard key={id} name={spell.name + ' ' + roman(Number(spell.level))}
          selected={selected} disabled={state.dirty || state.busy || !selected && payload[field].length >= count}
          onchange={() => state.change(() => {payload[field] = toggle(payload[field], id);})}>
          <SpellCard mode="expanded" spell={spell} path={lookup(view.catalog.definitions, 'paths', spell.magic_path_id)}/>
        </SelectableEntityCard>;
      })}</div>
      {!pool.length && <p className="RpWizard-empty">Нет доступных заклинаний в этой группе.</p>}
    </section>;
  }
  const fixed = view.preview.spells.filter(spell => !payload.spell_ids.includes(spell.id) && !payload.racial_spell_ids.includes(spell.id));
  return <div>
    <p className="RpWizard-instruction">Выберите стартовые заклинания</p>
    {fixed.length > 0 && <section><h3>Выдаются автоматически</h3><div className="RpWizard-cardGrid">{fixed.map(spell =>
      <SpellCard key={spell.id} mode="expanded" spell={spell} path={lookup(view.catalog.definitions, 'paths', spell.magic_path_id)}/>)}</div></section>}
    {((choices.racial_count ?? 0) > 0 || payload.racial_spell_ids.length > 0) && group('racial_spell_ids', choices.racial_ids ?? [], choices.racial_count ?? 0,
      race(state)?.name ?? 'Раса', uiIcon('sections.races'))}
    {((choices.ordinary_count ?? 0) > 0 || payload.spell_ids.length > 0) && group('spell_ids', choices.ordinary_ids ?? [], choices.ordinary_count ?? 0, 'Магия', uiIcon('sections.paths'))}
    {!fixed.length && !choices.racial_count && !choices.ordinary_count && <p className="RpWizard-empty">На этом этапе заклинания не выдаются.</p>}
  </div>;
}
