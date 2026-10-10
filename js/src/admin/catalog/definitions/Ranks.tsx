import {definition} from '../../../common/catalog/definitions/base';
import RankEditor from '../../editors/RankEditor';
import RankLabel from '../../../common/character/RankLabel';
import {roman} from '../../../common/presentation';

export default definition({
  key: 'ranks', title: 'Ранги', icon: 'sections.ranks',
  columns: [
    {key: 'level', title: '№', render: row => roman(Number(row.level))},
    {key: 'name', title: 'Ранг', render: row => <strong className="RpCatalog-nameText" title={row.name}><RankLabel rank={row}/></strong>},
  ],
  defaults: {letter: 'א', level: 1}, editor: RankEditor,
});
