import Component from 'flarum/common/Component';
import type {EditorContext} from '../types';
import {FieldLabel} from './FormFields';
import {timeOptions} from '../../presentation';
import type {UiIconName} from '../../uiIcons';

interface Attrs extends EditorContext {prefix: string; label: string; disabled?: boolean; min?: number}

interface TimeFieldAttrs {
  label: string; icon: UiIconName; value: number; unit: string; min?: number; disabled?: boolean;
  onValue: (value: number) => void; onUnit: (unit: string) => void;
}

/** Одна подпись над числом и единицей. Используется также для свойства предмета,
 * чтобы его перезарядка не получала другую геометрию и другую обработку пустого ввода. */
export function TimeField({label, icon, value, unit, min = 0, disabled = false, onValue, onUnit}: TimeFieldAttrs): JSX.Element {
  return <div className="RpField">
    <FieldLabel label={label} icon={icon}/>
    <div className="RpValueUnit">
      <input className="FormControl" type="number" aria-label={label} min={min} max={1000000} required
        disabled={disabled} value={value} oninput={(event: Event) => {
          const text = (event.target as HTMLInputElement).value;
          onValue(text === '' ? NaN : Number(text));
        }}/>
      <select className="FormControl" aria-label={'Единица времени: ' + label} disabled={disabled} value={unit}
        onchange={(event: Event) => onUnit((event.target as HTMLSelectElement).value)}>
        {Object.entries(timeOptions).map(([key, caption]) => <option key={key} value={key}>{caption}</option>)}
      </select>
    </div>
  </div>;
}

function renderCard({state, prefix, label, disabled = false, min = 0}: Attrs): JSX.Element {
  const icon = prefix === 'duration' ? 'fields.duration' : prefix === 'cast' ? 'fields.castTime' : 'fields.cooldown';
  return TimeField({label, icon, disabled, min, value: state.number(prefix + '_value'), unit: state.text(prefix + '_unit'),
    onValue: value => state.set(prefix + '_value', value), onUnit: unit => state.set(prefix + '_unit', unit)});
}
export default class TimedFields extends Component<Attrs> {
  view() { return renderCard(this.attrs); }
}
