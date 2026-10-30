// The order matches EventAppearance::PALETTE on the server.
export const questColors = ['#b8b8b8', '#80b77e', '#45698d', '#6b4e71', '#ffcb7f', '#a4243b'];
export const defaultColor = '#d1d5db';

export function cardStyle(color) {
  const safeColor = /^#[0-9a-f]{6}$/i.test(color || '') ? color : defaultColor;
  const channels = [1, 3, 5].map((offset) => parseInt(safeColor.slice(offset, offset + 2), 16) / 255);
  const linear = channels.map((value) => value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4);
  const luminance = linear[0] * 0.2126 + linear[1] * 0.7152 + linear[2] * 0.0722;
  return { '--quest-color': safeColor, '--quest-ink': luminance > 0.179 ? '#000000' : '#ffffff' };
}

export function countdown(event, now = Date.now()) {
  if (event.status !== 'recruiting' || event.participantCount < event.minParticipants || !event.scheduledStartAt) return null;
  const remaining = Date.parse(event.scheduledStartAt) - now;
  if (!Number.isFinite(remaining)) return null;
  if (remaining <= 0) return 'Ожидаем запуск';
  const minutes = Math.ceil(remaining / 60000);
  const hours = Math.floor(minutes / 60);
  const days = Math.floor(hours / 24);
  if (days > 0) return `${days} дн`;
  if (hours > 0) return `${hours} ч`;
  return `${minutes} мин`;
}
