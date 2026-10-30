import Modal, {type IInternalModalAttrs, type ModalHeading} from '../../../common/components/RoleplayModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import type {CatalogRow} from '../../../common/catalog/types';

interface Attrs extends IInternalModalAttrs {rows: CatalogRow[]; select: (row: CatalogRow) => void}

/** Шаблон — копия существующего определения перка; отдельного типа или признака не появляется. */
export default class CatalogTemplateModal extends Modal<Attrs> {
  selected = '';
  protected modalClassName(): string { return 'RpCatalogTemplate'; }
  protected modalHeading(): ModalHeading { return {label: 'Создание перка из шаблона', purpose: 'create'}; }
  content(): Mithril.Children {
    return <div className="Modal-body">
      <label>Перк для копирования<select className="FormControl" value={this.selected}
        onchange={(event: Event) => this.selected = (event.target as HTMLSelectElement).value}>
        {[<option key="empty" value="">Выбери перк</option>,
        ...this.attrs.rows.map(row => <option key={row.id} value={row.id}>{row.name}</option>)]}
      </select></label>
      <Button className="Button Button--primary" disabled={!this.selected} onclick={() => {
        const row = this.attrs.rows.find(item => item.id === Number(this.selected));
        if (row) this.attrs.select(row);
      }}>Продолжить</Button>
    </div>;
  }
}
