import type {EditorContext} from '../../common/catalog/types';
import {TextField, TextArea, NumberField} from '../../common/catalog/components/FormFields';
import RankLabel from '../../common/character/RankLabel';

/** Компактный редактор использует общую форму и то же отображение ранга, что анкета. */
export default function RankEditor({state}: EditorContext): JSX.Element {
  return <div>
    <div className="RpRank-preview"><RankLabel rank={state.values}/></div>
    {TextField({state, field: 'name', label: 'Название', required: true})}
    <div className="RpGrid RpGrid--2">
      {TextField({state, field: 'letter', label: 'Буква', icon: 'fields.letter', required: true, max: 1})}
      {NumberField({state, field: 'level', label: 'Номер ранга', min: 1, max: 10})}
    </div>
    {TextArea({state, field: 'description', label: 'Описание'})}
  </div>;
}
