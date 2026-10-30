import type {EditorContext} from '../catalog/types';
import {TextField, TextArea, NumberField, SelectField, CheckField} from '../catalog/components/FormFields';
import CardPreviews from '../catalog/components/CardPreviews';
import TimedFields from '../catalog/components/TimedFields';
import DamageEditor from '../catalog/components/DamageEditor';
import SpellCard from '../cards/SpellCard';
import {lookup, options} from '../catalog/helpers';

export default function SpellEditor({state, lookups}: EditorContext): JSX.Element {
  return <div>
    <CardPreviews render={(mode: 'compact' | 'expanded') => <SpellCard mode={mode} spell={state.values}
      path={lookup(lookups, 'paths', state.values.magic_path_id)}/>}/>
    <div className="RpGrid RpGrid--name">
      {TextField({state, field: 'name', label: 'Название', required: true})}
      {NumberField({state, field: 'level', label: 'Круг', min: 1, max: 100})}
      {SelectField({state, field: 'magic_path_id', label: 'Путь магии', required: true, numeric: true,
        options: {'': 'Выбери путь', ...options(lookups, 'paths')}})}
    </div>
    {CheckField({state, field: 'is_starting', label: 'Стартовое', icon: 'wizard.spells'})}
    <p className="helpText">Доступно в обычном стартовом выборе заклинаний I круга. Расовые выдачи настраиваются отдельно</p>
    <div className="RpGrid RpGrid--3">
      <div>
        {SelectField({state, field: 'distance_mode', label: 'Дистанция',
          options: {self: 'На себя', touch: 'Касание', distance: 'Дистанция'}})}
        {state.text('distance_mode') === 'distance' && NumberField({state, field: 'distance_value', label: 'Расстояние, м', step: 0.001})}
      </div>
      {NumberField({state, field: 'radius_value', label: 'Радиус, м'})}
      <TimedFields state={state} lookups={lookups} prefix="duration" label="Длительность"/>
    </div>
    <div className="RpGrid RpGrid--3">
      <DamageEditor state={state}/>
      <div>
        {SelectField({state, field: 'cast_mode', label: 'Накладывание', options: {instant: 'Мгновенно', timed: 'За время'}})}
        {state.text('cast_mode') === 'timed' && <TimedFields state={state} lookups={lookups} prefix="cast" label="Время накладывания" min={1}/>}
      </div>
      <TimedFields state={state} lookups={lookups} prefix="cooldown" label="Перезарядка"/>
    </div>
    <div className="RpGrid RpGrid--3">
      {NumberField({state, field: 'mana_cost', label: 'Мана'})}
      {NumberField({state, field: 'stamina_cost', label: 'Выносливость'})}
      {TextField({state, field: 'other_cost', label: 'Другие затраты', max: 500})}
    </div>
    {TextArea({state, field: 'description', label: 'Описание'})}
  </div>;
}
