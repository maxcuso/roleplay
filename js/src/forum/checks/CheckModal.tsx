import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../common/components/RoleplayModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Feedback from '../../common/components/Feedback';
import type Mithril from 'mithril';
import {requestMessage} from '../../common/requestError';
import {roman} from '../../common/presentation';
import CheckApi from './CheckApi';
import type {CheckContext, CheckCharacter, CharacterChoice, RequestPayload, RollPayload, Definition} from './types';

interface Attrs extends IInternalModalAttrs {type: 'request' | 'roll'; api: CheckApi; context: CheckContext; character?: CheckCharacter; onInsert: (code: string) => void}
const signed = (value: number): string => (value < 0 ? '−' : '+') + Math.abs(value);

/** Два режима общего окна. Вставка сохраняет настройку, кубики появятся только при публикации. */
export default class CheckModal extends Modal<Attrs> {
  kind: 'attribute' | 'skill' = 'skill'; targetId = 0; difficulty: number | null = 10;
  description = ''; target: 'all' | 'selected' = 'all'; selected: CharacterChoice[] = [];
  character?: CheckCharacter; characterId = 0; modifier = 0; reason = '';
  busy = false; loadingCharacter = false; error = ''; private disposed = false; private characterRevision = 0;
  search = ''; page = 1; total = 0; rows: CharacterChoice[] = []; searching = false; private searchRevision = 0; private timer?: ReturnType<typeof setTimeout>;

  oninit(vnode: Mithril.Vnode<Attrs, this>): void {
    super.oninit(vnode);
    const {context, character} = this.attrs;
    this.kind = context.request?.kind ?? (context.skills.length ? 'skill' : 'attribute');
    this.targetId = context.request?.target_id ?? this.definitions()[0]?.id ?? 0;
    this.difficulty = context.request?.difficulty ?? (this.attrs.type === 'roll' && !context.request ? null : 10);
    this.character = character; this.characterId = character?.id ?? context.defaultCharacterId ?? 0;
  }
  onremove(vnode: Mithril.VnodeDOM<Attrs, this>): void {this.disposed = true; this.characterRevision++; this.searchRevision++; clearTimeout(this.timer); super.onremove(vnode);}
  protected modalClassName(): string {return 'RpCheckModal';}
  protected modalHeading(): ModalHeading {return {label: this.attrs.type === 'request' ? 'Запрос проверки' : 'Бросок проверки', purpose: 'check'};}
  hide(): void {if (!this.busy) super.hide();}
  private definitions(): Definition[] {return this.attrs.context[this.kind === 'skill' ? 'skills' : 'attributes'];}
  private label(icon: string, name: string): Mithril.Children {return <span className="RpCheckModal-label"><Icon name={icon}/>{name}</span>;}
  private selectedCheck() {return this.character?.[this.kind === 'skill' ? 'skills' : 'attributes'].find(row => row.id === this.targetId);}
  private changeKind(value: 'skill' | 'attribute'): void {this.kind = value; this.targetId = this.definitions()[0]?.id ?? 0;}
  private async chooseCharacter(id: number): Promise<void> {
    const version = ++this.characterRevision;
    this.characterId = id; this.character = undefined; this.loadingCharacter = true; this.error = '';
    try {const character = await this.attrs.api.character(id); if (!this.disposed && version === this.characterRevision) this.character = character;}
    catch (error) {if (!this.disposed && version === this.characterRevision) this.error = requestMessage(error, 'Не удалось загрузить персонажа. Попробуйте ещё раз');}
    finally {if (!this.disposed && version === this.characterRevision) {this.loadingCharacter = false; m.redraw();}}
  }
  private async loadTargets(): Promise<void> {
    const version = ++this.searchRevision; this.searching = true;
    try {const data = await this.attrs.api.targets(this.search, this.page); if (!this.disposed && version === this.searchRevision) {this.rows = data.rows; this.total = data.total;}}
    catch (error) {if (!this.disposed && version === this.searchRevision) this.error = requestMessage(error, 'Не удалось загрузить персонажей. Попробуйте ещё раз');}
    finally {if (!this.disposed && version === this.searchRevision) {this.searching = false; m.redraw();}}
  }
  private payload(): RequestPayload | RollPayload {
    const base = {kind: this.kind, target_id: this.targetId, difficulty: this.difficulty};
    return this.attrs.type === 'request' ? {...base, description: this.description, target: this.target, characters: this.selected.map(row => row.id)}
      : {...base, character_id: this.characterId, request_id: this.attrs.context.requestId, modifier: this.modifier, reason: this.reason};
  }
  private async insert(): Promise<void> {
    if (this.busy) return;
    this.error = '';
    if (!this.targetId) this.error = 'Выберите навык или характеристику';
    else if (this.attrs.type === 'request' && this.target === 'selected' && !this.selected.length) this.error = 'Выберите хотя бы одного персонажа';
    else if (this.attrs.type === 'roll' && (!this.character || this.loadingCharacter)) this.error = 'Выберите своего активного персонажа';
    else if (!Number.isSafeInteger(this.modifier)) this.error = 'Укажите целый дополнительный бонус или штраф';
    else if (this.attrs.type === 'roll' && this.modifier !== 0 && !this.reason.trim()) this.error = 'Укажите причину дополнительного бонуса или штрафа';
    if (this.error) return;
    this.busy = true;
    try {
      const code = await this.attrs.api.prepare(this.attrs.type, this.payload());
      if (this.disposed) return;
      this.attrs.onInsert(code); this.busy = false; this.hide();
    } catch (error) {if (!this.disposed) this.error = requestMessage(error, 'Не удалось вставить проверку. Попробуйте ещё раз');}
    finally {if (!this.disposed) {this.busy = false; m.redraw();}}
  }
  content(): Mithril.Children {
    const roll = this.attrs.type === 'roll', context = this.attrs.context, selected = this.selectedCheck();
    const attribute = this.kind === 'skill' ? this.character?.attributes.find(row => row.id === selected?.attribute_id) : selected;
    const bonus = selected?.bonus ?? 0;
    return <form className="RpCheckModal-form" onsubmit={(event: Event) => {event.preventDefault(); void this.insert();}}>
      <div className="Modal-body RpCheckModal-body">
        {roll && <label>{this.label('fas fa-user', 'Персонаж')}<select className="FormControl" disabled={this.busy || this.loadingCharacter} value={this.characterId || ''} onchange={(e: Event) => void this.chooseCharacter(Number((e.target as HTMLSelectElement).value))}>
          <option value="" disabled>Выберите персонажа</option>{context.characters.map(row => <option value={row.id}>{row.name}</option>)}</select></label>}
        {roll && !context.characters.length && <Feedback message="Для броска нужен свой активный персонаж, которому доступна эта проверка" hint/>}
        <fieldset className="RpCheckModal-kind"><legend>{this.label('fas fa-sliders-h', 'Тип проверки')}</legend>{(['skill', 'attribute'] as const).map(value => <label><input type="radio" name="checkKind" checked={this.kind === value} disabled={this.busy} onchange={() => this.changeKind(value)}/>{value === 'skill' ? 'Навык' : 'Характеристика'}</label>)}</fieldset>
        <div className="RpCheckModal-fields">
          <label>{this.label(this.kind === 'skill' ? 'fas fa-graduation-cap' : 'fas fa-chart-bar', this.kind === 'skill' ? 'Навык' : 'Характеристика')}<select className="FormControl" value={this.targetId} disabled={this.busy} onchange={(e: Event) => {this.targetId = Number((e.target as HTMLSelectElement).value);}}>{this.definitions().map(row => <option value={row.id}>{row.name}{roll && this.kind === 'skill' ? ' ' + roman(this.character?.skills.find(s => s.id === row.id)?.level ?? 0) : ''}</option>)}</select></label>
          <label>{this.label('fas fa-tachometer-alt', 'Сложность')}<select className="FormControl" disabled={this.busy} value={this.difficulty ?? ''} onchange={(e: Event) => {const value = (e.target as HTMLSelectElement).value; this.difficulty = value === '' ? null : Number(value);}}>{roll && <option value="">Не задана</option>}{context.difficulties.map(row => <option value={row.value}>{row.name}</option>)}</select></label>
        </div>
        {!roll && <label>{this.label('fas fa-align-left', 'Описание действия')}<textarea className="FormControl" rows={3} maxlength={2000} value={this.description} disabled={this.busy} placeholder="Опишите, что нужно проверить" oninput={(e: Event) => {this.description = (e.target as HTMLTextAreaElement).value;}}/></label>}
        {!roll && <fieldset className="RpCheckModal-targets"><legend>{this.label('fas fa-users', 'Кому')}</legend><div className="RpCheckModal-targetModes">{(['all', 'selected'] as const).map(value => <label><input type="radio" name="checkTarget" checked={this.target === value} disabled={this.busy} onchange={() => {this.target = value; if (value === 'selected') void this.loadTargets();}}/>{value === 'all' ? 'Всем участникам' : 'Выбранным персонажам'}</label>)}</div>
          {this.target === 'selected' && <div className="RpCheckModal-targetPicker"><input type="search" className="FormControl" aria-label="Поиск персонажей" placeholder="Поиск персонажей" disabled={this.busy} value={this.search} oninput={(e: Event) => {this.search = (e.target as HTMLInputElement).value; this.page = 1; this.searchRevision++; clearTimeout(this.timer); this.timer = setTimeout(() => void this.loadTargets(), 250);}}/>
            {this.selected.length > 0 && <div className="RpCheckModal-selected">{this.selected.map(row => <Button type="button" className="Button Button--link" disabled={this.busy} icon="fas fa-times" onclick={() => {this.selected = this.selected.filter(s => s.id !== row.id);}}>{row.name}</Button>)}</div>}
            <div className="RpCheckModal-results" aria-busy={String(this.searching)}>{this.rows.map(row => <label><input type="checkbox" disabled={this.busy || this.searching} checked={this.selected.some(s => s.id === row.id)} onchange={(e: Event) => {this.selected = (e.target as HTMLInputElement).checked ? [...this.selected.filter(s => s.id !== row.id), row] : this.selected.filter(s => s.id !== row.id);}}/>{row.name}</label>)}</div>
            {this.total > 30 && <div className="RpCheckModal-pages"><Button type="button" className="Button Button--icon" icon="fas fa-chevron-left" aria-label="Предыдущая страница" disabled={this.page === 1 || this.searching || this.busy} onclick={() => {this.page--; void this.loadTargets();}}/>{this.page}<Button type="button" className="Button Button--icon" icon="fas fa-chevron-right" aria-label="Следующая страница" disabled={this.page * 30 >= this.total || this.searching || this.busy} onclick={() => {this.page++; void this.loadTargets();}}/></div>}
          </div>}
        </fieldset>}
        {roll && <><div className="RpCheckModal-calculation"><div>{this.label('fas fa-calculator', 'Бонус персонажа')}<strong>{this.loadingCharacter ? '…' : signed(bonus)}</strong></div><p>{attribute ? attribute.name + ' ' + attribute.value + ' − 5' : 'Выберите персонажа'}{this.kind === 'skill' && selected ? ' + ' + selected.name + ' ' + roman(selected.level ?? 0) : ''}{selected?.extra ? ' · Эффекты ' + signed(selected.extra) : ''}</p></div>
          <div className="RpCheckModal-modifier"><label>{this.label('fas fa-plus-minus', 'Дополнительный бонус / штраф')}<input className="FormControl" type="number" step={1} min={-10000} max={10000} disabled={this.busy} value={Number.isNaN(this.modifier) ? '' : this.modifier} oninput={(e: Event) => {const value = (e.target as HTMLInputElement).value; this.modifier = value === '' ? NaN : Number(value);}}/></label><div><span>Бросок</span><strong>2d6{Number.isFinite(this.modifier) && bonus + this.modifier !== 0 ? ' ' + signed(bonus + this.modifier) : ''}</strong></div></div>
          {this.modifier !== 0 && <label>{this.label('fas fa-align-left', 'Причина бонуса или штрафа')}<input className="FormControl" type="text" maxlength={500} disabled={this.busy} value={this.reason} placeholder="Например, хорошее освещение" oninput={(e: Event) => {this.reason = (e.target as HTMLInputElement).value;}}/></label>}
        </>}
        {this.error && <Feedback message={this.error}/>}
      </div>
      <div className="Modal-footer RpCheckModal-footer"><p className="helpText">{roll ? 'Кубики будут брошены при публикации сообщения' : 'Запрос появится после публикации сообщения'}</p><div><Button type="button" className="Button" disabled={this.busy} onclick={() => this.hide()}>Отмена</Button><Button type="submit" className="Button Button--primary" loading={this.busy} disabled={this.busy || this.loadingCharacter || (roll && !this.character)}>Вставить код</Button></div></div>
    </form>;
  }
}
