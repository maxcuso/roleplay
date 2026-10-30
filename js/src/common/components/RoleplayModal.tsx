import Modal, {type IInternalModalAttrs} from 'flarum/common/components/Modal';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import {uiIcon, type UiIconName} from '../uiIcons';

export type {IInternalModalAttrs} from 'flarum/common/components/Modal';
export type ModalPurpose = 'create' | 'edit' | 'grant' | 'learn' | 'history' | 'effects' | 'approve' | 'return' | 'import' | 'export' | 'complete' | 'check';
export interface ModalHeading {label: string; purpose: ModalPurpose}

/** Общая связь назначения окна со служебной иконкой. Сами значки по-прежнему
 * задаются в resources/presentation/icons.json, включая локальные замены Pro-иконок.
 */
const headingIcons: Record<ModalPurpose, UiIconName> = {
  create: 'actions.create', edit: 'actions.edit', grant: 'management.title',
  learn: 'sections.spells',
  history: 'actions.history', effects: 'profile.effects', approve: 'wizard.review',
  return: 'review.return', import: 'actions.import', export: 'actions.export', complete: 'fields.icon', check: 'effects.allChecks',
};

/** Базовое окно расширения. Конкретное окно задаёт название, назначение и классы
 * своего содержимого. Заголовок и общий класс формируются здесь, оформление — в modals.css.
 * Для новых окон наследуем этот класс, сохраняя обычные события и закрытие Modal ядра.
 */
export default abstract class RoleplayModal<Attrs extends IInternalModalAttrs> extends Modal<Attrs> {
  protected abstract modalClassName(): string;
  protected abstract modalHeading(): ModalHeading;

  className(): string {return 'RpModal ' + this.modalClassName();}

  title(): Mithril.Children {
    const heading = this.modalHeading();
    return <span className="RpModalHeading">
      <span className="RpModalHeading-icon" aria-hidden="true"><Icon name={uiIcon(headingIcons[heading.purpose])}/></span>
      <span className="RpModalHeading-text">{heading.label}</span>
    </span>;
  }
}
