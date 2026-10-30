import Component from 'flarum/common/Component';

interface Attrs {enabled: boolean; label: string; disabled: boolean; onchange: () => void}

export default class CatalogStatusSwitch extends Component<Attrs> {
  view(): JSX.Element {
    return <button type="button" className={'RpStatusSwitch' + (this.attrs.enabled ? ' is-on' : '')}
      role="switch" aria-checked={String(this.attrs.enabled)} aria-label={this.attrs.label}
      disabled={this.attrs.disabled} onclick={this.attrs.onchange}><span/></button>;
  }
}