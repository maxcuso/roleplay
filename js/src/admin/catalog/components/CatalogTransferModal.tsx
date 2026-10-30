import Feedback from '../../../common/components/Feedback';
import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../../common/components/RoleplayModal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import type {CatalogDefinition} from '../../../common/catalog/types';
import CatalogListState from '../CatalogListState';
import CatalogTransferState, {ExportScope} from '../CatalogTransferState';
import {sections, attributeSettings} from '../definitions';
import {uiIcon} from '../../../common/uiIcons';

interface Attrs extends IInternalModalAttrs {
  kind: 'import' | 'export'; definition: CatalogDefinition; list: CatalogListState;
  onImported?: () => Promise<void>;
}
const actions = {create: 'Новая', update: 'Обновить', skip: 'Пропустить'};
const labels = Object.fromEntries([...sections.flatMap(section => section.children ?? [section]), attributeSettings]
  .map(section => [section.key, section.title]));

/** Компактное окно обмена. Исходный список обновляется только после успешного применения. */
export default class CatalogTransferModal extends Modal<Attrs> {
  transfer = new CatalogTransferState();
  scope: ExportScope = 'section';
  input?: HTMLInputElement;

  protected modalClassName(): string { return 'RpCatalogEditor RpCatalogTransfer'; }
  protected modalHeading(): ModalHeading {
    const importing = this.attrs.kind === 'import';
    return {label: importing ? 'Импорт JSON' : 'Экспорт JSON', purpose: this.attrs.kind};
  }
  protected wrapper(children: Mithril.Children): Mithril.Children { return <div>{children}</div>; }
  protected get dismissibleOptions() {
    return this.transfer.busy ? {viaCloseButton: false, viaEscKey: false, viaBackdropClick: false} : super.dismissibleOptions;
  }

  async check(): Promise<void> {
    // Перед проверкой получаем текущую версию. Применение привязано уже к версии отчёта.
    await this.attrs.list.load();
    if (this.attrs.list.error) {this.transfer.error = this.attrs.list.error; return;}
    await this.transfer.check(this.attrs.list.data.revision);
  }

  async apply(): Promise<void> {
    if (!await this.transfer.apply()) return;
    this.hide();
    await this.attrs.list.load();
    await this.attrs.onImported?.();
  }

  async download(): Promise<void> {
    const list = this.attrs.list;
    const document = await this.transfer.export(this.attrs.definition.key, this.scope, {search: list.search, ...list.filters});
    if (!document) return;
    const file = new Blob([JSON.stringify(document, null, 2) + '\n'], {type: 'application/json;charset=utf-8'});
    const url = URL.createObjectURL(file);
    const link = window.document.createElement('a');
    link.href = url;
    link.download = 'roleplay-' + (this.scope === 'all' ? 'catalogs' : this.attrs.definition.key) + '-' + new Date().toISOString().slice(0, 10) + '.json';
    window.document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    this.hide();
  }

  content(): Mithril.Children {
    const state = this.transfer;
    return <div className="Modal-body">
      <p className="RpTransfer-section"><Icon name={uiIcon(this.attrs.definition.icon)}/> {this.attrs.definition.title}</p>
      {state.error && <Feedback message={state.error}/>}
      {this.attrs.kind === 'export' ? this.exportFields() : this.importFields()}
    </div>;
  }

  private exportFields(): Mithril.Children {
    const state = this.transfer;
    const options: {value: ExportScope; title: string; note: string; icon: 'fields.parameter' | 'actions.search' | 'fields.stack'}[] = [
      {value: 'section', title: 'Весь раздел', note: this.attrs.definition.title, icon: 'fields.parameter'},
      {value: 'filtered', title: 'По текущим фильтрам', note: 'Со всех страниц таблицы', icon: 'actions.search'},
      {value: 'all', title: 'Все справочники и сущности', note: 'Один общий файл', icon: 'fields.stack'},
    ];
    return <div>
      <div className="RpTransfer-options" role="group" aria-label="Объём экспорта">
        {options.map(option => <button key={option.value} type="button" className="RpTransfer-option"
          aria-pressed={String(this.scope === option.value)} disabled={state.busy} onclick={() => this.scope = option.value}>
          <Icon name={uiIcon(option.icon)}/><span><strong>{option.title}</strong><small>{option.note}</small></span>
          <Icon name={uiIcon(this.scope === option.value ? 'wizard.selected' : 'profile.selectChevron')}/>
        </button>)}
      </div>
      <p className="RpTransfer-note"><Icon name={uiIcon('fields.link')}/> Связанные записи включаются автоматически.</p>
      <div className="RpEditorActions"><Button className="Button Button--primary" icon={uiIcon('actions.export')}
        loading={state.busy} onclick={() => void this.download()}>Скачать JSON</Button></div>
    </div>;
  }

  private importFields(): Mithril.Children {
    const state = this.transfer;
    const report = state.report;
    return <div>
      <input className="sr-only" type="file" accept=".json,application/json" aria-label="JSON-файл"
        disabled={state.busy} oncreate={vnode => this.input = vnode.dom as HTMLInputElement}
        onchange={(event: Event) => {
          const input = event.target as HTMLInputElement;
          const file = input.files?.[0];
          if (file) void state.readFile(file);
          input.value = '';
        }}/>
      <div className="RpTransfer-file"><Icon name={uiIcon('transfer.file')}/>
        <span><strong>{state.filename || 'Выбери JSON-файл'}</strong><small>До 10 МБ · до 5000 записей</small></span>
        <Button className="Button Button--icon" icon={uiIcon('actions.upload')} title="Выбрать файл" aria-label="Выбрать файл"
          disabled={state.busy} onclick={() => this.input?.click()}/>
      </div>
      <div className="RpTransfer-modes" role="group" aria-label="Совпадающие ключи">
        <Button className="Button" aria-pressed={String(state.mode === 'skip')} disabled={state.busy}
          onclick={() => state.setMode('skip')}>Пропустить существующие</Button>
        <Button className="Button" aria-pressed={String(state.mode === 'update')} disabled={state.busy}
          onclick={() => state.setMode('update')}>Обновить существующие</Button>
      </div>
      {report && <div className="RpTransfer-report" aria-live="polite">
        <div className="RpTransfer-totals">
          <span><Icon name={uiIcon('actions.create')}/><b>{report.new}</b><small>Новых</small></span>
          <span><Icon name={uiIcon('actions.refresh')}/><b>{report.updated}</b><small>Обновлений</small></span>
          <span><Icon name={uiIcon('actions.select')}/><b>{report.skipped}</b><small>Пропусков</small></span>
          <span className={report.errors.length ? 'hasErrors' : ''}><Icon name={uiIcon('review.warning')}/><b>{report.errors.length}</b><small>Ошибок</small></span>
        </div>
        {!!report.errors.length && <Feedback message={report.errors} kind="input"/>}
        {!!report.warnings.length && <Feedback message={report.warnings} kind="input"/>}
        <div className="RpTransfer-records">
          {report.rows.map(row => <div key={row.catalog + '/' + row.key} className={'RpTransfer-record RpTransfer-record--' + row.action}>
            <span><strong>{row.name}</strong><small>{labels[row.catalog] ?? row.catalog} · {row.key}</small></span>
            <small className="RpTransfer-badge">{actions[row.action]}</small>
          </div>)}
          {!report.rows.length && <p className="RpTransfer-note">В файле нет записей.</p>}
        </div>
      </div>}
      <div className="RpEditorActions">
        {report && !report.errors.length && report.new + report.updated > 0
          ? <Button className="Button Button--primary" icon={uiIcon('actions.import')} loading={state.busy}
              onclick={() => void this.apply()}>Применить импорт</Button>
          : <Button className="Button Button--primary" icon={uiIcon('actions.search')}
              disabled={!state.document || this.attrs.list.loading} loading={state.busy} onclick={() => void this.check()}>Проверить файл</Button>}
      </div>
    </div>;
  }
}
