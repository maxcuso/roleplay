import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type CatalogEditorState from '../catalog/CatalogEditorState';
import type {ItemProperty} from '../cards/ItemCard';
import {uiIcon} from '../uiIcons';
import EditorSection from '../catalog/components/EditorSection';
import {FieldLabel} from '../catalog/components/FormFields';
import {TimeField} from '../catalog/components/TimedFields';

interface Attrs {state: CatalogEditorState}

/** Свойства редактируются как обычный текст. Перезарядка относится к своему свойству. */
export default class ItemPropertiesEditor extends Component<Attrs> {
  rows(): ItemProperty[] {
    return Array.isArray(this.attrs.state.values.properties) ? this.attrs.state.values.properties as ItemProperty[] : [];
  }

  update(index: number, patch: Partial<ItemProperty>): void {
    this.attrs.state.set('properties', this.rows().map((row, current) => current === index ? {...row, ...patch} : row));
  }

  view(): JSX.Element {
    const rows = this.rows();
    return <EditorSection title="Свойства" icon="fields.property">
      <div>{rows.map((property, index) => <div key={index} className="RpItemProperty">
        <label className="RpField"><FieldLabel label={'Свойство ' + (index + 1)} icon="fields.property"/><textarea className="FormControl" rows={3}
          required maxlength={20000} value={property.description}
          oninput={(event: Event) => this.update(index, {description: (event.target as HTMLTextAreaElement).value})}/></label>
        <div>
          {TimeField({label: 'Перезарядка', icon: 'fields.cooldown', value: property.cooldown_value, unit: property.cooldown_unit,
            onValue: value => this.update(index, {cooldown_value: value}), onUnit: unit => this.update(index, {cooldown_unit: unit})})}
        </div>
        <Button className="Button Button--icon RpProperty-remove" icon={uiIcon('actions.delete')} aria-label={'Убрать свойство ' + (index + 1)}
          onclick={() => this.attrs.state.set('properties', this.rows().filter((_row, current) => current !== index))}/>
      </div>)}</div>
      <Button className="Button Button--link" icon={uiIcon('actions.create')} disabled={rows.length >= 100}
        onclick={() => this.attrs.state.set('properties', [...this.rows(), {description: '', cooldown_value: 0, cooldown_unit: 'turn'}])}>
        Добавить свойство
      </Button>
    </EditorSection>;
  }
}
