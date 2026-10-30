function chest() {
  return m('svg.QuestReward-chest', { viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.8, 'stroke-linejoin': 'round', 'aria-hidden': 'true', focusable: 'false' }, [
    m('path', { d: 'M3 11V8a5 5 0 0 1 5-5h8a5 5 0 0 1 5 5v3M3 11h18v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zM8 3v8m8-8v8M3 14h7m4 0h7' }),
    m('rect', { x: 10, y: 11, width: 4, height: 6, rx: 1 }),
  ]);
}

export default function renderRewards(event) {
  if (event.eventType !== 'quest') return null;
  const reward = event.reward || {};
  const entries = [
    ['experience', 'Опыт', () => m('i.icon.fas.fa-star', { 'aria-hidden': 'true' }), ' XP'],
    ['gold', 'Золото', () => m('i.icon.fas.fa-coins', { 'aria-hidden': 'true' }), ''],
    ['items', 'Предметы', chest, ''],
  ].filter(([key]) => Number.isInteger(reward[key]) && reward[key] > 0);
  if (!entries.length) return null;
  return m('.QuestWidget-rewardRow', m('.QuestWidget-rewards', { role: 'group', 'aria-label': 'Награда квеста' }, entries.map(([key, label, symbol, suffix]) =>
    m('span.QuestWidget-reward', { key, role: 'img', title: `${label}: ${reward[key]}`, 'aria-label': `${label}: ${reward[key]}` }, [symbol(), m('span', `${reward[key]}${suffix}`)])
  )));
}
