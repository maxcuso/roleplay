import Component from 'flarum/common/Component';
import type Mithril from 'mithril';

/** Один и тот же компонент карточки используется для обоих режимов и будущих экранов. */
function renderCard({render}: {render: (mode: 'compact' | 'expanded') => Mithril.Children}): JSX.Element {
  return <div className="RpCardPreviews">
    <figure>{render('compact')}<figcaption>Сокращённая форма</figcaption></figure>
    <figure>{render('expanded')}<figcaption>Полная форма</figcaption></figure>
  </div>;
}
export default class CardPreviews extends Component<{render: (mode: 'compact' | 'expanded') => Mithril.Children}> {
  view() { return renderCard(this.attrs); }
}
