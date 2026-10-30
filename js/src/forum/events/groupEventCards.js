// Group only consecutive event blocks: ordinary post text stays in place.
export default function groupEventCards(roots) {
  const isGap = node => (node.nodeType === 3 && !node.textContent.trim()) || node.nodeName === 'BR';
  for (const root of roots) {
    if (root.parentElement?.classList.contains('QuestEventGrid')) continue;
    const group = [root];
    const gaps = [];
    let cursor = root.nextSibling;
    while (cursor) {
      const pending = [];
      while (cursor && isGap(cursor)) { pending.push(cursor); cursor = cursor.nextSibling; }
      if (!cursor || !roots.has(cursor)) break;
      gaps.push(...pending);
      group.push(cursor);
      cursor = cursor.nextSibling;
    }
    if (group.length < 2) continue;
    const grid = root.ownerDocument.createElement('div');
    grid.className = 'QuestEventGrid';
    root.before(grid);
    gaps.forEach(node => node.remove());
    group.forEach(node => grid.appendChild(node));
  }
}
