import EffectAttachmentsEditor from '../catalog/components/EffectAttachmentsEditor';
import type {EditorContext} from '../catalog/types';
import {TextField, TextArea, NumberField, SelectField, CheckField} from '../catalog/components/FormFields';
import CardPreviews from '../catalog/components/CardPreviews';
import DamageEditor from '../catalog/components/DamageEditor';
import ItemPropertiesEditor from './ItemPropertiesEditor';
import ItemCard from '../cards/ItemCard';
import {rarityOptions} from '../presentation';
import {lookup, options} from '../catalog/helpers';
import {itemAttackModes} from '../itemAttackRules';
import EditorSection from '../catalog/components/EditorSection';

/** Назначения независимы: предмет может быть оружием, бронёй и материалом.
 * Ингредиент и литература пока только классифицируют предмет для будущих систем. */
export default function ItemEditor({state, lookups}: EditorContext): JSX.Element {
  return <div>
    <CardPreviews render={(mode: 'compact' | 'expanded') => <ItemCard mode={mode} item={state.values}
      type={lookup(lookups, 'item-types', state.values.item_type_id)}/>}/>
    <div className="RpGrid RpGrid--itemName">
      {TextField({state, field: 'name', label: 'Название', required: true})}
      {SelectField({state, field: 'item_type_id', label: 'Тип предмета', required: true, numeric: true,
        options: {'': 'Выбери тип', ...options(lookups, 'item-types')}})}
      {SelectField({state, field: 'rarity', label: 'Редкость', options: rarityOptions})}
    </div>
    <div className="RpGrid RpGrid--3">
      {NumberField({state, field: 'base_price', label: 'Стоимость', max: 9007199254740991, step: 0.001})}
      {NumberField({state, field: 'weight', label: 'Вес, кг', max: 9999999, step: 0.001})}
      {NumberField({state, field: 'max_stack', label: 'В стопке', min: 1})}
    </div>
    {TextArea({state, field: 'appearance', label: 'Внешность'})}
    <EditorSection title="Применение" icon="fields.equipment">
      <div className="RpItemUsage">
        {CheckField({state, field: 'is_melee_weapon', label: 'Оружие ближнего боя'})}
        {CheckField({state, field: 'is_ranged_weapon', label: 'Дальнобойное / метательное оружие'})}
        {CheckField({state, field: 'is_magic_weapon', label: 'Магическое оружие'})}
        {CheckField({state, field: 'is_armor', label: 'Броня'})}
        {CheckField({state, field: 'is_ingredient', label: 'Ингредиент'})}
        {CheckField({state, field: 'is_literature', label: 'Литература'})}
      </div>
      <div className="RpGrid RpGrid--2">
        {itemAttackModes.filter(usage => state.flag(usage.flag)).map(usage =>
          <div key={usage.mode}>
            {SelectField({state, field: usage.proficiency, label: usage.label + ': владение', icon: 'sections.training',
              required: true, numeric: true, options: {'': 'Выбери владение', ...options(lookups, 'proficiencies')}})}
          </div>)}
      </div>
      <DamageEditor state={state}/>
      {state.flag('is_armor') && <DamageEditor state={state} field="ignored_damage_units" label="Игнорируемый урон"/>}
    </EditorSection>
    <div className="RpItemMagic">
      {CheckField({state, field: 'is_magical', label: 'Магический предмет', disabled: Array.isArray(state.values.effects) && state.values.effects.length > 0})}
      {state.flag('is_magical') && Array.isArray(state.values.effects) && state.values.effects.length > 0 &&
        <p className="helpText">Чтобы снять признак магического предмета, сначала убери привязанные эффекты.</p>}
    </div>
    <ItemPropertiesEditor state={state}/>
    {state.flag('is_magical') && EffectAttachmentsEditor({state, lookups, owner: 'items'})}
    {!state.flag('is_magical') && Array.isArray(state.values.effects) && state.values.effects.length > 0 &&
      <p className="helpText">Верни признак магического предмета и убери эффекты перед сохранением немагического предмета.</p>}
  </div>;
}
