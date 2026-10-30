import app from 'flarum/common/app';
import type Modal from 'flarum/common/components/Modal';
import type {IInternalModalAttrs} from 'flarum/common/components/Modal';

/** Flarum 2 теряет тип attrs в ModalManager.show. Адаптация только на этой границе;
 * внутри наших окон и в местах вызова поля остаются строго типизированными.
 * Классы импортируются напрямую: окно не зависит от совпадения номеров чанков с другими расширениями.
 */
export default function showModal<Attrs extends IInternalModalAttrs>(
  component: new (...args: never[]) => Modal<Attrs>,
  attrs: Omit<Attrs, keyof IInternalModalAttrs>,
  stack = false,
): void {
  void app.modal.show(component as unknown as Parameters<typeof app.modal.show>[0], attrs, stack);
}
