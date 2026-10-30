import Feedback from '../../components/Feedback';
import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../components/RoleplayModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import CatalogEditorState from '../CatalogEditorState';
import type {CatalogDefinition, CatalogDataSource, CatalogRow, CatalogResult} from '../types';
import {uiIcon} from '../../uiIcons';
import {clearInputFeedback} from '../../messageKind';

export interface EditorAttrs extends IInternalModalAttrs {
  definition: CatalogDefinition;
  row: CatalogRow | null;
  data: CatalogResult;
  api: CatalogDataSource;
  onSaved: () => Promise<void>;
}

// Родительный падеж для общего заголовка редактора в админке и анкете.
const entityNames: Record<string, string> = {
  attributes: 'характеристики', races: 'расы', paths: 'пути магии', 'item-types': 'типа предметов',
  sources: 'источника', skills: 'навыка', proficiencies: 'владения', perks: 'перка',
  spells: 'заклинания', items: 'предмета', ranks: 'ранга', characters: 'персонажа',
};

/** Общее окно отвечает за форму и сохранение. Поля рисует редактор конкретной сущности. */
export default class CatalogEditModal extends Modal<EditorAttrs> {
  editor!: CatalogEditorState;

  oninit(vnode: Mithril.Vnode<EditorAttrs, this>): void {
    super.oninit(vnode);
    const {definition, row, data, api} = this.attrs;
    this.editor = new CatalogEditorState(definition.initial(row), row?.id ?? null, definition.revision(row, data), api);
  }

  protected modalClassName(): string {
    // Только сущности с карточками используют 620 px. Справочники — 480 px.
    const cards = ['perks', 'items', 'spells'].includes(this.attrs.definition.key);
    return 'RpCatalogEditor' + (cards ? ' RpCatalogEditor--cards' : '');
  }

  protected modalHeading(): ModalHeading {
    const editing = Boolean(this.attrs.row);
    return {label: (editing ? 'Редактирование ' : 'Создание ') + (entityNames[this.attrs.definition.key] ?? 'записи'),
      purpose: editing ? 'edit' : 'create'};
  }

  protected wrapper(children: Mithril.Children): Mithril.Children {
    // Flarum 2 не отправляет форму за редактор: задаём настоящий submit явно.
    return <form oninput={() => clearInputFeedback(this.editor)} onchange={() => clearInputFeedback(this.editor)}
      onsubmit={(event: SubmitEvent) => {event.preventDefault(); void this.save();}}>{children}</form>;
  }

  async save(): Promise<void> {
    if (!await this.editor.save()) return;
    this.hide();
    await this.attrs.onSaved();
  }

  content(): Mithril.Children {
    return <div className="Modal-body">
      {this.editor.error && <Feedback message={this.editor.error}/>}
      <fieldset className="RpCatalogEditor-fields" disabled={this.editor.saving}>
        {this.attrs.definition.editor({state: this.editor, lookups: this.attrs.data.lookups})}
      </fieldset>
      <div className="RpEditorActions"><Button type="submit" className="Button Button--primary" icon={uiIcon('actions.save')}
        loading={this.editor.saving} disabled={this.editor.uploading}>Сохранить изменения</Button></div>
    </div>;
  }
}
