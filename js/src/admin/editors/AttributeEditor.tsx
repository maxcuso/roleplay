import type {EditorContext} from '../../common/catalog/types';
import {TextField, TextArea} from '../../common/catalog/components/FormFields';
import Icon from 'flarum/common/components/Icon';
import {catalogueIcon} from '../../common/uiIcons';

/** Иконка характеристики меняется в справочнике и наследуется зависимыми источниками. */
export default function AttributeEditor({state}: EditorContext): JSX.Element {
  return <div className="RpIconEditor">
    <div className="RpIconEditor-preview"><Icon name={catalogueIcon(state.text('icon'))}/></div>
    <div>
      {TextField({state, field: 'name', label: 'Название', required: true})}
      {TextField({state, field: 'icon', label: 'Иконка'})}
      {TextArea({state, field: 'description', label: 'Описание'})}
    </div>
  </div>;
}
