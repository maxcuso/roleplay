import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import EmbeddedEventCard from './components/EmbeddedEventCard';
import groupEventCards from './groupEventCards';

export default function mountEventCards() {
  extend(CommentPost.prototype, 'refreshContent', function () {
    this.questEventRoots ||= new Map();
    const roots = new Set(this.element.querySelectorAll('.Post-body .QuestEventEmbed[data-quest-event-id]'));
    for (const [root] of this.questEventRoots) {
      if (!roots.has(root)) {
        m.mount(root, null);
        this.questEventRoots.delete(root);
      }
    }
    if (this.isEditing()) return;
    groupEventCards(roots);
    for (const root of roots) {
      const id = root.getAttribute('data-quest-event-id');
      if (!/^[1-9][0-9]*$/.test(id) || this.questEventRoots.has(root)) continue;
      this.questEventRoots.set(root, id);
      root.classList.add('QuestEventEmbed--interactive');
      m.mount(root, { view: () => m(EmbeddedEventCard, { eventId: id }) });
    }
  });

  extend(CommentPost.prototype, 'onremove', function () {
    for (const root of this.questEventRoots?.keys() || []) m.mount(root, null);
    this.questEventRoots?.clear();
  });
}
