import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import type CatalogEditorState from '../CatalogEditorState';
import {requestMessage} from '../../requestError';
import {uiIcon} from '../../uiIcons';
import {FieldLabel} from './FormFields';

interface Attrs {state: CatalogEditorState}

/** Файл загружается отдельно; сохранение формы ждёт окончания обработки изображения. */
export default class ImageUpload extends Component<Attrs> {
  input?: HTMLInputElement;
  error = '';

  async upload(file: File): Promise<void> {
    const state = this.attrs.state;
    if (state.uploading) return;
    this.error = '';
    if (file.size > 5 * 1024 * 1024) {this.error = 'Размер изображения — не более 5 МБ.'; return;}
    state.uploading = true;
    m.redraw();
    try {
      const body = new FormData();
      body.append('image', file);
      const response = await app.request<{data: {image: string}}>({
        method: 'POST', url: app.forum.attribute<string>('apiUrl') + state.imageEndpoint,
        body, serialize: data => data, errorHandler: () => {},
      });
      state.set('image', response.data.image);
    } catch (error) {
      this.error = requestMessage(error, 'Не удалось загрузить изображение. Попробуй ещё раз.');
    } finally {
      state.uploading = false;
      if (this.input) this.input.value = '';
      m.redraw();
    }
  }

  view(): JSX.Element {
    const state = this.attrs.state;
    return <div className="RpImageUpload">
      <label className="RpField"><FieldLabel label="Изображение" field="image"/><input className="FormControl" maxlength={1000}
        value={state.text('image')} disabled={state.uploading}
        oninput={(event: Event) => state.set('image', (event.target as HTMLInputElement).value)}/></label>
      <input className="sr-only" type="file" accept=".svg,.png,.webp,image/svg+xml,image/png,image/webp"
        aria-label="Файл изображения перка" disabled={state.uploading}
        oncreate={(vnode) => this.input = vnode.dom as HTMLInputElement}
        onchange={(event: Event) => {
          const file = (event.target as HTMLInputElement).files?.[0];
          if (file) void this.upload(file);
        }}/>
      <Button className="Button" icon={uiIcon('actions.upload')} loading={state.uploading} onclick={() => this.input?.click()}>Файл</Button>
      <Button className="Button Button--icon" icon={uiIcon('actions.close')} aria-label="Убрать изображение"
        disabled={state.uploading || !state.text('image')} onclick={() => state.set('image', '')}/>
      {this.error && <p role="alert">{this.error}</p>}
    </div>;
  }
}
