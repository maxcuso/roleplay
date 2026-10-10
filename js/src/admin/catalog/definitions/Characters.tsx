import {definition, plainColumn} from '../../../common/catalog/definitions/base';
import CharacterEditor from '../../editors/CharacterEditor';
import {text} from '../../../common/catalog/helpers';
const statuses = {creating: 'Создаётся', active: 'Жив', dead: 'Мёртв', archived: 'В архиве'};
export default definition({
  key: 'characters', title: 'Персонажи', icon: 'sections.characters', endpoint: 'characters',
  create: false, toggle: false, remove: true,
  removeConfirmation: row => 'Удалить персонажа «' + row.name + '» вместе с его предметами, выдачами, ресурсами и историей? '
    + 'Это действие нельзя отменить. Аккаунт игрока и тема литературной анкеты останутся.',
  filters: [{key: 'status', label: 'Все статусы', options: () => statuses}],
  columns: [
    plainColumn('name', 'Имя и фамилия'), plainColumn('owner_name', 'Игрок'), plainColumn('race_name', 'Раса'),
    {key: 'status', title: 'Статус', render: row => statuses[text(row, 'status') as keyof typeof statuses] ?? text(row, 'status')},
    {key: 'discussion', title: 'Литературная анкета', render: row => text(row, 'discussion_link') ?
      <a href={text(row, 'discussion_link')} target="_blank" rel="noopener noreferrer">Открыть</a> : '—'},
  ],
  initial: row => ({first_name: row?.first_name ?? '', last_name: row?.last_name ?? '', discussion_link: row?.discussion_link ?? ''}),
  revision: row => row?.revision ?? 0, editor: CharacterEditor,
});
