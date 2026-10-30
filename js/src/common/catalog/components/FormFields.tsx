import type Mithril from 'mithril';
import type CatalogEditorState from '../CatalogEditorState';
import Icon from 'flarum/common/components/Icon';
import Component from 'flarum/common/Component';
import {uiIcon, type UiIconName} from '../../uiIcons';

interface FieldAttrs {
  state: CatalogEditorState;
  field: string;
  label: string;
  required?: boolean;
  max?: number;
  min?: number;
  nullable?: boolean;
  step?: number;
  disabled?: boolean;
  options?: Record<string, string>;
  numeric?: boolean;
  icon?: UiIconName;
}

/** Здесь связываются поля с семантическими значками. Сами классы значков меняются
 * в resources/presentation/icons.json, без миграций и правок каждого редактора. */
const fieldIcons: Readonly<Record<string, UiIconName>> = {
  name: 'fields.name', first_name: 'fields.name', last_name: 'fields.name',
  description: 'fields.description', appearance: 'fields.description', quote: 'fields.description', icon: 'fields.icon',
  color: 'fields.color', rarity: 'fields.rarity', image: 'fields.image', level: 'fields.level',
  magic_path_id: 'sections.paths', item_type_id: 'fields.itemType', attribute_id: 'fields.attribute',
  skill_id: 'sections.training', rules_url: 'fields.link', discussion_link: 'fields.link',
  min_age: 'fields.age', max_age: 'fields.age', equipment_limit: 'fields.equipment',
  average_height: 'fields.height', distinctive_traits: 'fields.property', background_color: 'fields.color',
  distance_mode: 'fields.distance', distance_value: 'fields.distance', radius_value: 'fields.radius',
  cast_mode: 'fields.castTime', mana_cost: 'resources.mana', stamina_cost: 'resources.stamina',
  other_cost: 'sections.items', base_price: 'resources.gold', weight: 'resources.weight', max_stack: 'fields.stack',
  operation: 'fields.operation', mechanic: 'fields.mechanic', stat: 'fields.parameter',
  is_melee_weapon: 'item.meleeWeapon', is_ranged_weapon: 'item.rangedWeapon',
  is_magic_weapon: 'item.magicWeapon', is_armor: 'item.armor', is_magical: 'item.magical',
  is_ingredient: 'item.ingredient', is_literature: 'item.literature',
};

export class FieldLabel extends Component<{label: string; field?: string; icon?: UiIconName}> {
  view(): JSX.Element {
    const {label, field = '', icon} = this.attrs;
    return <span className="RpForm-label"><Icon name={uiIcon(icon ?? fieldIcons[field] ?? 'fields.parameter')}/>{label}</span>;
  }
}

/** Небольшие элементы ввода общие; расположение и набор полей задаёт конкретный редактор. */
export function TextField({state, field, label, icon, required = false, max = 100}: FieldAttrs): Mithril.Children {
  return <label className="RpField"><FieldLabel field={field} label={label} icon={icon}/><input className="FormControl" maxlength={max}
    required={required} value={state.text(field)}
    oninput={(event: Event) => state.set(field, (event.target as HTMLInputElement).value)}/></label>;
}
export function TextArea({state, field, label, icon}: FieldAttrs): Mithril.Children {
  return <label className="RpField RpField--wide"><FieldLabel field={field} label={label} icon={icon}/>
    <textarea className="FormControl" rows={3} maxlength={20000} value={state.text(field)}
      oninput={(event: Event) => state.set(field, (event.target as HTMLTextAreaElement).value)}/></label>;
}
export function NumberField({state, field, label, icon, min = 0, max = 1000000, nullable = false, step = 1, disabled = false}: FieldAttrs): Mithril.Children {
  return <label className="RpField"><FieldLabel field={field} label={label} icon={icon}/><input className="FormControl" type="number"
    min={min} max={max} step={step} required={!nullable} disabled={disabled} value={state.values[field] ?? ''}
    oninput={(event: Event) => {
      const value = (event.target as HTMLInputElement).value;
      state.set(field, value === '' && nullable ? null : value === '' ? NaN : Number(value));
    }}/></label>;
}
export function SelectField({state, field, label, icon, options = {}, numeric = false, required = false}: FieldAttrs): Mithril.Children {
  return <label className="RpField"><FieldLabel field={field} label={label} icon={icon}/><select className="FormControl" required={required}
    value={state.text(field)} onchange={(event: Event) => {
      const value = (event.target as HTMLSelectElement).value;
      state.set(field, numeric ? value === '' ? null : Number(value) : value);
    }}>{Object.entries(options).map(([value, caption]) => <option key={value || 'empty'} value={value}>{caption}</option>)}</select></label>;
}
export function CheckField({state, field, label, icon, disabled = false}: FieldAttrs): Mithril.Children {
  return <label className="RpCheck"><input type="checkbox" disabled={disabled} checked={state.flag(field)}
    onchange={(event: Event) => state.set(field, (event.target as HTMLInputElement).checked)}/><FieldLabel field={field} label={label} icon={icon}/></label>;
}
