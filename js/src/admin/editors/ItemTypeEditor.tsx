import Icon from 'flarum/common/components/Icon';
import type {EditorContext} from '../../common/catalog/types';
import {TextField, TextArea, NumberField} from '../../common/catalog/components/FormFields';
import {catalogueIcon} from '../../common/uiIcons';

export default function ItemTypeEditor({state}: EditorContext): JSX.Element {
  return <div className="RpIconEditor">
    <div className="RpIconEditor-preview"><Icon name={catalogueIcon(state.text('icon'))}/></div>
    <div>
      {TextField({state, field: 'name', label: 'Название', required: true})}
      <div className="RpGrid RpGrid--2">
        {TextField({state, field: 'icon', label: 'Иконка'})}
        {NumberField({state, field: 'equipment_limit', label: 'В экипировке', min: -1})}
      </div>
      <p className="helpText">−1 — нельзя экипировать; 0 — без лимита; положительное число — максимум предметов этого типа.</p>
      {TextArea({state, field: 'description', label: 'Описание'})}

    </div>
  </div>;
}
