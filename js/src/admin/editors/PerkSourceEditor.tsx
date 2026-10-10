import Icon from 'flarum/common/components/Icon';
import type {EditorContext} from '../../common/catalog/types';
import {TextField, FieldLabel} from '../../common/catalog/components/FormFields';
import EditorSection from '../../common/catalog/components/EditorSection';
import {object, text} from '../../common/catalog/helpers';
import {catalogueIcon} from '../../common/uiIcons';

const kinds = {race: 'Раса', skill: 'Навык', quest: 'Квест', event: 'Событие'};

export default function PerkSourceEditor({state, lookups}: EditorContext): JSX.Element {
  function dependency(field: string, label: string): JSX.Element {
    const enabled = state.text(field) !== '';
    return <div className="RpDependency">
      <label><input type="checkbox" checked={enabled}
        onchange={(event: Event) => state.set(field, (event.target as HTMLInputElement).checked ? 'race' : '')}/>
        <FieldLabel label={label} icon={field === 'name_source' ? 'fields.name' : 'fields.icon'}/></label>
      <select className="FormControl" aria-label={label} disabled={!enabled} value={state.text(field) || 'race'}
        onchange={(event: Event) => state.set(field, (event.target as HTMLSelectElement).value)}>
        {Object.entries(kinds).map(([value, label]) => <option key={value} value={value}>{label}</option>)}
      </select>
    </div>;
  }

  const overrides = object(state.values.attribute_icons);
  return <div className="RpIconEditor">
    <div className="RpIconEditor-preview"><Icon name={catalogueIcon(state.text('icon'))}/></div>
    <div>
      {TextField({state, field: 'name', label: 'Название', required: true})}
      {dependency('name_source', 'Название зависит от')}
      {TextField({state, field: 'icon', label: 'Иконка'})}
      {dependency('icon_source', 'Иконка зависит от')}
      {state.text('icon_source') === 'skill' && <EditorSection title="Иконки навыков" icon="sections.training">
        <p className="helpText">Пустое поле наследует иконку характеристики.</p>
        <div className="RpGrid">{(lookups.attributes ?? []).map(attribute => {
          const slug = text(attribute, 'slug');
          return <label className="RpField" key={attribute.id}>
            <span><Icon name={catalogueIcon(String(overrides[slug] || attribute.icon || ''))}/> {attribute.name}</span>
            <input className="FormControl" maxlength={100} value={String(overrides[slug] ?? '')}
              placeholder={text(attribute, 'icon')} oninput={(event: Event) => {
                state.set('attribute_icons', {...object(state.values.attribute_icons), [slug]: (event.target as HTMLInputElement).value});
              }}/>
          </label>;
        })}</div>
      </EditorSection>}
    </div>
  </div>;
}
