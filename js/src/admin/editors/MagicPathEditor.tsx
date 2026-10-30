import Icon from 'flarum/common/components/Icon';
import type {EditorContext} from '../../common/catalog/types';
import {TextField, TextArea} from '../../common/catalog/components/FormFields';
import {catalogueIcon} from '../../common/uiIcons';
import {pathColor} from '../../common/presentation';

export default function MagicPathEditor({state}: EditorContext): JSX.Element {
  return <div className="RpIconEditor">
    <div className="RpIconEditor-preview" style={{color: pathColor('#' + state.text('color').replace(/^#/, ''))}}>
      <Icon name={catalogueIcon(state.text('icon'))}/>
    </div>
    <div>
      {TextField({state, field: 'name', label: 'Название', required: true})}
      <div className="RpGrid RpGrid--2">
        {TextField({state, field: 'icon', label: 'Иконка'})}
        {TextField({state, field: 'color', label: 'Цвет (HEX)', max: 7})}
      </div>
      {TextArea({state, field: 'description', label: 'Описание'})}
    </div>
  </div>;
}
