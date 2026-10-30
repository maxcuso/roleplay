import type {EditorContext} from '../../common/catalog/types';
import {TextField, TextArea, SelectField} from '../../common/catalog/components/FormFields';
import {lookup, options, text} from '../../common/catalog/helpers';
import Icon from 'flarum/common/components/Icon';
import {catalogueIcon} from '../../common/uiIcons';

/** Поля навыка и владения одинаковы. Иконка всегда берётся из выбранной характеристики. */
export default function LearningDefinitionFields({state, lookups}: EditorContext): JSX.Element {
  const attribute = lookup(lookups, 'attributes', state.values.attribute_id);
  return <div>
    <div className="RpGrid">
      {TextField({state, field: 'name', label: 'Название', required: true})}
      {SelectField({state, field: 'attribute_id', label: 'Характеристика', required: true, numeric: true,
        options: {'': 'Выбери характеристику', ...options(lookups, 'attributes')}})}
    </div>
    <p className="RpLearning-icon">
      <Icon name={catalogueIcon(text(attribute ?? {}, 'icon'))}/>
      {' Иконка наследуется от характеристики'}
    </p>
    {TextArea({state, field: 'description', label: 'Описание'})}
  </div>;
}