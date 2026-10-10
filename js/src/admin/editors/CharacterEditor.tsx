import type {EditorContext} from '../../common/catalog/types';
import {TextField} from '../../common/catalog/components/FormFields';

export default function CharacterEditor({state}: EditorContext): JSX.Element {
  return <div>
    <div className="RpGrid">
      {TextField({state, field: 'first_name', label: 'Имя', required: true})}
      {TextField({state, field: 'last_name', label: 'Фамилия'})}
    </div>
    {TextField({state, field: 'discussion_link', label: 'Ссылка на литературную анкету', max: 1000})}
    <p className="helpText">Изменение ссылки сохраняет одобрение литературной части и статус персонажа.</p>
  </div>;
}