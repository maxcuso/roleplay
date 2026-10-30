import Icon from 'flarum/common/components/Icon';
import type {CatalogRow, EditorContext} from '../../common/catalog/types';
import {TextField, TextArea, NumberField, FieldLabel} from '../../common/catalog/components/FormFields';
import EditorSection from '../../common/catalog/components/EditorSection';
import MultiPicker from '../../common/catalog/components/MultiPicker';
import {ids, object, lookup, text} from '../../common/catalog/helpers';
import {catalogueIcon} from '../../common/uiIcons';
import {roman} from '../../common/presentation';
import ImageUpload from '../../common/catalog/components/ImageUpload';

/** Каждый вид расового выбора имеет своё поле; выдача не выполняется из редактора. */
export default function RaceEditor({state, lookups}: EditorContext): JSX.Element {
  const rules = object(state.values.rules);
  const bonuses = object(rules.fixed_bonuses);
  const chosenAttributes = Array.isArray(rules.choice_attributes) ? rules.choice_attributes.map(String) : [];
  function setRule(key: string, value: unknown): void {
    state.set('rules', {...object(state.values.rules), [key]: value});
  }
  function count(field: string, label: string): JSX.Element {
    return <label className="RpField"><FieldLabel label={label} icon="fields.value"/><input className="FormControl" type="number" min={field === 'attribute_bonus' ? 1 : 0} max={1000}
      required value={Number(rules[field] ?? 0)} oninput={(event: Event) => {
        const value = (event.target as HTMLInputElement).value;
        setRule(field, value === '' ? NaN : Number(value));
      }}/></label>;
  }
  function picker(collection: string, field: string, blockedField?: string): JSX.Element {
    return <MultiPicker rows={lookups[collection] ?? []} selected={ids(rules[field])}
      blocked={blockedField ? ids(rules[blockedField]) : []}
      onchange={(value: number[]) => setRule(field, value)}
      icon={(row: CatalogRow) => collection === 'spells' ? text(lookup(lookups, 'paths', row.magic_path_id) ?? {}, 'icon')
        : collection === 'proficiencies' ? text(lookup(lookups, 'attributes', row.attribute_id) ?? {}, 'icon') : text(row, 'icon')}
      label={(row: CatalogRow) => row.name + (collection === 'spells' ? ' ' + roman(Number(row.level)) : '')}/>;
  }

  const background = state.text('background_color');
  const swatch = /^#?[0-9a-f]{6}$/i.test(background) ? '#' + background.replace(/^#/, '') : '#808080';

  return <div>
    {TextField({state, field: 'name', label: 'Название', required: true})}
    <div className="RpGrid RpGrid--2 RpRaceEditor-summary">
      <div className="RpField">
        <FieldLabel label="Продолжительность жизни" icon="fields.age"/>
        <div className="RpRaceEditor-lifespan">
          {NumberField({state, field: 'min_age', label: 'От', nullable: true})}
          <span aria-hidden="true">—</span>
          {NumberField({state, field: 'max_age', label: 'До', nullable: true})}
        </div>
      </div>
      {TextField({state, field: 'average_height', label: 'Средний рост', max: 255})}
    </div>
    {TextArea({state, field: 'description', label: 'Описание'})}
    {TextArea({state, field: 'quote', label: 'Цитата'})}
    <ImageUpload state={state} label="Изображение расы" fileLabel="Файл изображения расы"/>
    {TextArea({state, field: 'distinctive_traits', label: 'Отличительные черты'})}
    <div className="RpField"><FieldLabel field="background_color" label="Цвет подложки"/>
      <span className="RpRaceEditor-color">
        <input type="color" value={swatch} aria-label="Выбрать цвет подложки"
          oninput={(event: Event) => state.set('background_color', (event.target as HTMLInputElement).value)}/>
        <input className="FormControl" maxlength={7} placeholder="#AABBCC" aria-label="Цвет подложки (HEX)" value={background}
          oninput={(event: Event) => state.set('background_color', (event.target as HTMLInputElement).value)}/>
      </span>
    </div>
    {TextField({state, field: 'rules_url', label: 'Ссылка на правила расы', max: 1000})}
    <EditorSection title="Бонусы характеристик" icon="fields.attribute">
      <div className="RpGrid RpGrid--4">{(lookups.attributes ?? []).map(attribute => {
        const slug = text(attribute, 'slug');
        return <label key={attribute.id} className="RpField">
          <span><Icon name={catalogueIcon(text(attribute, 'icon'))}/> {attribute.name}</span>
          <input className="FormControl" type="number" min={-1000} max={1000} required value={Number(bonuses[slug] ?? 0)}
            oninput={(event: Event) => {
              const value = (event.target as HTMLInputElement).value;
              setRule('fixed_bonuses', {...object(object(state.values.rules).fixed_bonuses), [slug]: value === '' ? NaN : Number(value)});
            }}/>
        </label>;
      })}</div>
      <h4><FieldLabel label="Бонусы на выбор" icon="fields.attribute"/></h4>
      <div className="RpGrid">{(lookups.attributes ?? []).map(attribute => {
        const slug = text(attribute, 'slug');
        return <label key={attribute.id} className="RpCheck">
          <input type="checkbox" checked={chosenAttributes.includes(slug)} onchange={(event: Event) => {
            setRule('choice_attributes', (event.target as HTMLInputElement).checked
              ? [...chosenAttributes, slug] : chosenAttributes.filter(value => value !== slug));
          }}/> <Icon name={catalogueIcon(text(attribute, 'icon'))}/> {attribute.name}
        </label>;
      })}</div>
      <div className="RpGrid">
        {count('attribute_bonus', 'Бонус к каждой характеристике')}
        {count('attribute_count', 'Количество характеристик на выбор')}
      </div>
    </EditorSection>
    <EditorSection title="Дополнительные владения" icon="sections.training">
      {count('proficiency_choice_count', 'Сколько владений выбрать')}
      <label className="RpField"><FieldLabel label="Доступные владения" icon="sections.training"/>
        <select className="FormControl" value={String(rules.proficiency_choice_mode ?? 'list')}
          onchange={(event: Event) => setRule('proficiency_choice_mode', (event.target as HTMLSelectElement).value)}>
          <option value="list">Из выбранного списка</option><option value="all">Все включённые владения</option>
        </select>
      </label>
      {rules.proficiency_choice_mode !== 'all' && picker('proficiencies', 'proficiency_choice_ids')}
      <p className="helpText">Расовый выбор не расходует выборы от характеристик и не требует их уровня.</p>
    </EditorSection>
    <EditorSection title="Перки" icon="sections.perks">
      <h4><FieldLabel label="Фиксированные" icon="actions.select"/></h4>
      {picker('perks', 'fixed_perk_ids', 'perk_choice_ids')}
      <h4><FieldLabel label="На выбор" icon="sections.perks"/></h4>
      {count('perk_choice_count', 'Сколько перков выбрать')}
      {picker('perks', 'perk_choice_ids', 'fixed_perk_ids')}
    </EditorSection>
    <EditorSection title="Заклинания" icon="sections.spells">
      <h4><FieldLabel label="Фиксированные" icon="actions.select"/></h4>
      <p className="helpText">Выдаются сверх стартовых, без требования Магии 3.</p>
      {picker('spells', 'fixed_spell_ids', 'spell_choice_ids')}
      <h4><FieldLabel label="На выбор" icon="sections.spells"/></h4>
      {count('spell_choice_count', 'Сколько заклинаний выбрать')}
      {picker('spells', 'spell_choice_ids', 'fixed_spell_ids')}
      <h4><FieldLabel label="Дополнить выбор путями магии" icon="sections.paths"/></h4>
      <p className="helpText">Пути дополняют список заклинаний на выбор. Эти заклинания не выдаются автоматически.</p>
      {picker('paths', 'spell_choice_path_ids')}
    </EditorSection>
  </div>;
}
