import Modal, {type IInternalModalAttrs} from 'flarum/common/components/Modal';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import type {Lookups} from '../../common/catalogData';
import {uiIcon} from '../../common/uiIcons';
import CharacterHistory, {type HistoryEvent} from './CharacterHistory';

interface Attrs extends IInternalModalAttrs {events: HistoryEvent[]; lookups?: Lookups}

/** Показываем уже разрешённую сервером историю; окно не делает новых запросов. */
export default class CharacterHistoryModal extends Modal<Attrs> {
  className(): string {return 'Modal--small RpHistoryModal RpCharacterHistoryModal';}
  title(): Mithril.Children {return <><Icon name={uiIcon('actions.history')}/> История персонажа</>;}
  content(): Mithril.Children {
    return <div className="Modal-body"><CharacterHistory events={this.attrs.events} lookups={this.attrs.lookups}/></div>;
  }
}
