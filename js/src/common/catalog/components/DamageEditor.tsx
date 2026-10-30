import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import type CatalogEditorState from '../CatalogEditorState';
import {damage, damageTiers} from '../../presentation';
import {uiIcon} from '../../uiIcons';
import {FieldLabel} from './FormFields';

interface Attrs {
  state: CatalogEditorState;
  /** Один редактор ступеней для урона и защиты, без копирования компонента. */
  field?: string;
  label?: string;
}
interface DamagePart {id: number; tier: number; count: number}

/** Составные ступени удобны для ввода, но в форму уходит единственное числовое значение. */
export default class DamageEditor extends Component<Attrs> {
  parts: DamagePart[] = [];
  nextId = 1;

  oninit(vnode: Mithril.Vnode<Attrs, this>): void {
    super.oninit(vnode);
    let left = this.attrs.state.number(this.attrs.field ?? 'damage_units');
    for (const tier of damageTiers) {
      const count = Math.floor(left / tier.value);
      left %= tier.value;
      if (count) this.parts.push({id: this.nextId++, tier: tier.value, count});
    }
  }

  sync(): void {
    this.attrs.state.set(this.attrs.field ?? 'damage_units', this.parts.reduce((sum, part) => sum + part.tier * part.count, 0));
  }

  view(): Mithril.Children {
    return <section className="RpDamageEditor RpField">
      <FieldLabel label={this.attrs.label ?? 'Урон'} icon={this.attrs.field === 'ignored_damage_units' ? 'combat.defense' : 'fields.damage'}/>
      <div className="RpDamage-summary">{damage(this.attrs.state.number(this.attrs.field ?? 'damage_units'))}</div>
      <div>{this.parts.map(part => <div key={part.id} className="RpDamage-part">
        <select className="FormControl" aria-label={this.attrs.label ? 'Ступень: ' + this.attrs.label : 'Ступень урона'} value={part.tier}
          onchange={(event: Event) => {part.tier = Number((event.target as HTMLSelectElement).value); this.sync();}}>
          {damageTiers.map(tier => <option key={tier.value} value={tier.value}>{tier.name}</option>)}
        </select>
        <span>×</span><input className="FormControl" type="number" aria-label="Количество ступеней" required min={1}
          max={1000000} value={part.count} oninput={(event: Event) => {
            const value = (event.target as HTMLInputElement).value;
            part.count = value === '' ? NaN : Number(value);
            this.sync();
          }}/>
        <Button className="Button Button--icon" icon={uiIcon('actions.close')} aria-label="Убрать ступень"
          onclick={() => {this.parts = this.parts.filter(row => row.id !== part.id); this.sync();}}/>
      </div>)}</div>
      <Button className="Button Button--link RpDamage-add" icon={uiIcon('actions.create')}
        onclick={() => {this.parts.push({id: this.nextId++, tier: 3, count: 1}); this.sync();}}>Добавить ступень</Button>
    </section>;
  }
}
