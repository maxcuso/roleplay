import {override} from 'flarum/common/extend';
import ModalManager from 'flarum/common/components/ModalManager';

/** Flarum закрывает окно через менеджер и по крестику, и по Escape/фону.
 * Перед анимацией даём мастеру сохранить черновик. Другие окна не затрагиваем.
 */
export default function guardWizardClose(): void {
  override(ModalManager.prototype, 'animateHide', function(original, closedCallback) {
    const modal = this.attrs.state.modal;
    const beforeClose = modal?.attrs?.beforeRoleplayWizardClose;
    if (typeof beforeClose !== 'function') return original(closedCallback);
    void (beforeClose as () => Promise<boolean>)().then(allowed => {
      // Ответ старого сохранения не должен закрыть уже открытое другое окно.
      if (this.attrs.state.modal !== modal) return;
      if (allowed) original(closedCallback);
      else {
        // CloseWatcher одноразовый. Если сохранение не удалось, возвращаем обработку
        // Escape штатному keyup Flarum, чтобы повторное нажатие тоже работало.
        const manager = this as unknown as {closeWatcher?: {destroy(): void}};
        manager.closeWatcher?.destroy();
        manager.closeWatcher = undefined;
      }
    });
  });
}
