import type Mithril from 'mithril';
import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../../common/components/RoleplayModal';
import EventForm from './EventForm';

interface Attrs extends IInternalModalAttrs {oninsert: (code: string) => void}

/** Форма находится вне прокручиваемой панели редактора и не обрезается ею. */
export default class EventCreateModal extends Modal<Attrs> {
  protected modalClassName(): string {return 'Modal--large QuestEventCreateModal';}
  protected modalHeading(): ModalHeading {return {label: 'Создание события', purpose: 'create'};}
  content(): Mithril.Children {
    return <EventForm oninsert={this.attrs.oninsert} oncancel={() => this.hide()}/>;
  }
}
