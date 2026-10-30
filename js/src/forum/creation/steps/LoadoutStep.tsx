import type CreationState from '../CreationState';
import type {LoadoutOption} from '../types';
import {lookup} from '../../../common/catalogData';
import ItemCard from '../../../common/cards/ItemCard';
import SelectableEntityCard from '../../../common/cards/SelectableEntityCard';

export default function LoadoutStep(state: CreationState): JSX.Element {
  const view = state.view!, payload = view.draft.payload;
  function contents(option: LoadoutOption, automatic = false): JSX.Element {
    return <div className={automatic ? 'RpWizard-cardGrid' : 'RpWizard-bundle'}>{option.items.map((entry, index) => {
      const item = lookup(view.catalog.definitions, 'items', entry.item_id);
      return item ? <ItemCard key={index} mode="expanded" item={item} quantity={entry.quantity}
        type={lookup(view.catalog.definitions, 'item-types', item.item_type_id)}/> : <p key={index}>Предмет больше недоступен. Измените выбор</p>;
    })}</div>;
  }
  return <div>
    <p className="RpWizard-instruction">Выберите стартовое снаряжение</p>
    {view.catalog.configuration.loadout.map(group => <section key={group.key} className="RpWizard-choiceSection">
      <h3>{group.name}{!group.automatic && <span> – выбрано {(payload.loadout[group.key] ?? []).length} из {group.max}</span>}</h3>
      {(payload.loadout[group.key] ?? []).filter(key => !group.options.some(option => option.key === key)).map(key =>
        <button key={key} type="button" onclick={() => state.change(() => {
          payload.loadout[group.key] = payload.loadout[group.key].filter(value => value !== key);
        })}>Убрать недоступный вариант набора</button>)}
      {group.automatic ? <div><p className="RpWizard-help">Выдаётся автоматически</p>{contents(group.options[0], true)}</div>
        : <div className="RpWizard-cardGrid">{group.options.map(option => {
          const chosen = payload.loadout[group.key] ?? [], selected = chosen.includes(option.key);
          const available = option.items.every(entry => {
            const item = lookup(view.catalog.definitions, 'items', entry.item_id);
            return item && lookup(view.catalog.definitions, 'item-types', item.item_type_id);
          });
          return <SelectableEntityCard key={option.key} name={option.name} selected={selected}
            disabled={!available || state.dirty || state.busy || !selected && chosen.length >= group.max}
            onchange={() => state.change(() => {
              payload.loadout[group.key] = selected ? chosen.filter(key => key !== option.key) : [...chosen, option.key];
            })}>{contents(option)}</SelectableEntityCard>;
        })}</div>}
    </section>)}
    {Object.entries(payload.loadout).filter(([key, values]) => values.length
      && !view.catalog.configuration.loadout.some(group => group.key === key)).map(([key]) =>
      <button key={key} type="button" onclick={() => state.change(() => {delete payload.loadout[key];})}>
        Убрать выбор из недоступной группы
      </button>)}
    <p className="RpWizard-help">Начальное золото: {view.catalog.configuration.starting_gold}. Вещи поступят в инвентарь.</p>
  </div>;
}
