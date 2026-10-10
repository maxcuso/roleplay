import Modal, {IInternalModalAttrs} from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import CatalogEditorState from '../CatalogEditorState';
import type {CatalogDefinition, CatalogDataSource, CatalogRow, CatalogResult} from '../types';
import {uiIcon} from '../../uiIcons';

export interface EditorAttrs extends IInternalModalAttrs {
  definition: CatalogDefinition;
  row: CatalogRow | null;
  data: CatalogResult;
  api: CatalogDataSource;
  onSaved: () => Promise<void>;
}

/** Общее окно отвечает за форму и сохранение. Поля рисует редактор конкретной сущности. */
export default class CatalogEditModal extends Modal<EditorAttrs> {
  editor!: CatalogEditorState;

  oninit(vnode: Mithril.Vnode<EditorAttrs, this>): void {
    super.oninit(vnode);
    const {definition, row, data, api} = this.attrs;
    this.editor = new CatalogEditorState(definition.initial(row), row?.id ?? null, definition.revision(row, data), api);
  }

  className(): string {
    // Только сущности с карточками используют 620 px. Справочники — 480 px.
    const cards = ['perks', 'items', 'spells'].includes(this.attrs.definition.key);
    return 'RpCatalogEditor' + (cards ? ' RpCatalogEditor--cards' : '');
  }

  title(): Mithril.Children {
    return <span><Icon name={uiIcon('actions.edit')}/> {this.attrs.row?.name || this.attrs.definition.title}</span>;
  }

  protected wrapper(children: Mithril.Children): Mithril.Children {
    // Flarum 2 не отправляет форму за редактор: задаём настоящий submit явно.
    return <form onsubmit={(event: SubmitEvent) => {event.preventDefault(); void this.save();}}>{children}</form>;
  }

  async save(): Promise<void> {
    if (!await this.editor.save()) return;
    this.hide();
    await this.attrs.onSaved();
  }

  content(): Mithril.Children {
    return <div className="Modal-body">
      {this.editor.error && <div className="RpCatalog-error" role="alert">{this.editor.error}</div>}
      <fieldset className="RpCatalogEditor-fields" disabled={this.editor.saving}>
        {this.attrs.definition.editor({state: this.editor, lookups: this.attrs.data.lookups})}
      </fieldset>
      <div className="RpEditorActions"><Button type="submit" className="Button Button--primary" icon={uiIcon('actions.save')}
        loading={this.editor.saving} disabled={this.editor.uploading}>Сохранить изменения</Button></div>
    </div>;
  }
}
