import Component from 'flarum/common/Component';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import {uiIcon} from '../uiIcons';

interface Attrs {name: string; description: Mithril.Children}
let nextDescription = 0;

/** Прокрутка общего описания: колёсико и свайп остаются нативными.
 * Стрелки не передают клик карточке выбора и не запускают экипировку.
 */
export default class CardDescription extends Component<Attrs> {
  private readonly descriptionId = 'rp-card-description-' + ++nextDescription;
  private viewport?: HTMLElement;
  private observer?: ResizeObserver;
  private measurement?: number;
  private repeatDelay?: ReturnType<typeof setTimeout>;
  private repeatFrame?: number;
  private holding: -1 | 0 | 1 = 0;
  private overflow = false;
  private canUp = false;
  private canDown = false;

  oncreate(vnode: Mithril.VnodeDOM<Attrs, this>): void {
    super.oncreate(vnode);
    this.viewport = this.element.querySelector('.RpCard-description') as HTMLElement;
    this.observer = new ResizeObserver(this.scheduleMeasurement);
    this.observer.observe(this.viewport);
    window.addEventListener('blur', this.stopHold);
    // Загрузка шрифта может изменить число строк, не изменив высоту контейнера.
    void document.fonts.ready.then(this.scheduleMeasurement);
    this.scheduleMeasurement();
  }

  onupdate(): void { this.scheduleMeasurement(); }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>): void {
    this.stopHold();
    this.observer?.disconnect();
    window.removeEventListener('blur', this.stopHold);
    if (this.measurement !== undefined) cancelAnimationFrame(this.measurement);
    this.viewport = undefined;
    super.onremove(vnode);
  }

  private scheduleMeasurement = (): void => {
    if (!this.viewport || this.measurement !== undefined) return;
    this.measurement = requestAnimationFrame(() => {
      this.measurement = undefined;
      this.measure();
    });
  };

  private measure(): void {
    const text = this.viewport;
    if (!text) return;
    const overflow = text.scrollHeight > text.clientHeight + 1;
    const up = overflow && text.scrollTop > 1;
    const down = overflow && text.scrollTop < text.scrollHeight - text.clientHeight - 1;
    if ((this.holding < 0 && !up) || (this.holding > 0 && !down)) this.stopHold();
    if (overflow === this.overflow && up === this.canUp && down === this.canDown) return;
    this.overflow = overflow;
    this.canUp = up;
    this.canDown = down;
    m.redraw();
  }

  private lineHeight(): number {
    return this.viewport ? parseFloat(getComputedStyle(this.viewport).lineHeight) || 16 : 16;
  }

  private scroll(direction: -1 | 1, amount = this.lineHeight()): void {
    const text = this.viewport;
    if (!text) return;
    text.scrollTop = Math.max(0, Math.min(text.scrollHeight - text.clientHeight, text.scrollTop + direction * amount));
    this.measure();
  }

  private startHold(event: PointerEvent, direction: -1 | 1): void {
    event.stopPropagation();
    if (event.button !== 0) return;
    event.preventDefault();
    this.stopHold();
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
    this.scroll(direction);
    // Короткий клик сдвигает ровно одну строку; удержание начинает движение позже.
    if ((direction < 0 && !this.canUp) || (direction > 0 && !this.canDown)) return;
    this.holding = direction;
    this.repeatDelay = setTimeout(() => {
      this.repeatDelay = undefined;
      let previous = performance.now();
      const repeat = (now: number): void => {
        this.repeatFrame = undefined;
        if (!this.holding) return;
        const elapsed = Math.min(50, now - previous);
        previous = now;
        this.scroll(this.holding, this.lineHeight() * 6 * elapsed / 1000);
        if (this.holding) this.repeatFrame = requestAnimationFrame(repeat);
      };
      this.repeatFrame = requestAnimationFrame(repeat);
    }, 320);
  }

  private stopHold = (): void => {
    this.holding = 0;
    if (this.repeatDelay !== undefined) clearTimeout(this.repeatDelay);
    if (this.repeatFrame !== undefined) cancelAnimationFrame(this.repeatFrame);
    this.repeatDelay = this.repeatFrame = undefined;
  };

  view(): Mithril.Children {
    const arrow = (direction: -1 | 1, available: boolean): Mithril.Children => <button type="button"
      aria-label={'Прокрутить описание ' + (direction < 0 ? 'вверх: ' : 'вниз: ') + this.attrs.name}
      aria-controls={this.descriptionId} disabled={!available}
      onpointerdown={(event: PointerEvent) => this.startHold(event, direction)}
      onpointerup={this.stopHold} onpointercancel={this.stopHold} onlostpointercapture={this.stopHold}
      onclick={(event: MouseEvent) => {
        event.stopPropagation();
        // Клавиатура и вспомогательные технологии вызывают click без pointerdown.
        if (event.detail === 0) this.scroll(direction);
      }}>
      <Icon name={uiIcon(direction < 0 ? 'actions.moveUp' : 'actions.moveDown')} aria-hidden="true"/>
    </button>;
    return <div className="RpCard-descriptionArea">
      <div className="RpCard-description" id={this.descriptionId}
        onscroll={(event: Event & {redraw?: boolean}) => {event.redraw = false; this.scheduleMeasurement();}}>
        {this.attrs.description}
      </div>
      {this.overflow && <div className="RpCard-scroll" onclick={(event: MouseEvent) => event.stopPropagation()}
        onkeydown={(event: KeyboardEvent) => event.stopPropagation()}>
        {arrow(-1, this.canUp)}{arrow(1, this.canDown)}
      </div>}
    </div>;
  }
}
