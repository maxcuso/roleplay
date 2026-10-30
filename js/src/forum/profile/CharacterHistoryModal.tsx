import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../common/components/RoleplayModal';
import type Mithril from 'mithril';
import type {Lookups} from '../../common/catalogData';
import CharacterHistory, {type HistoryEvent} from './CharacterHistory';

interface Attrs extends IInternalModalAttrs {events: HistoryEvent[]; lookups?: Lookups}

/** Показываем уже разрешённую сервером историю; окно не делает новых запросов. */
export default class CharacterHistoryModal extends Modal<Attrs> {
  protected modalClassName(): string {return 'Modal--small RpHistoryModal RpCharacterHistoryModal';}
  protected modalHeading(): ModalHeading {return {label: 'История персонажа', purpose: 'history'};}
  content(): Mithril.Children {
    return <div className="Modal-body"><CharacterHistory events={this.attrs.events} lookups={this.attrs.lookups}/></div>;
  }
}
