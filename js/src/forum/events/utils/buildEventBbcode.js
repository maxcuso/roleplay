export default function buildEventBbcode(values) {
  const title = values.title.trim();
  const description = values.description.trim();
  if (title.length < 3 || title.length > 80) throw new Error('Название должно содержать от 3 до 80 символов.');
  if (description.length > 1000) throw new Error('Описание должно содержать не больше 1000 символов.');
  if (/\[\/?quest(?:\s|=|\])/i.test(description)) throw new Error('Описание не должно содержать другой код события.');

  const integer = (value, label) => {
    if (!/^[1-9][0-9]*$/.test(String(value)) || Number(value) > 4294967295) {
      throw new Error(`${label}: укажи целое число от 1 до 4294967295.`);
    }
    return Number(value);
  };
  const minimum = integer(values.minimum, 'Минимум участников');
  const maximum = integer(values.maximum, 'Максимум участников');
  const delay = integer(values.delay, 'Интервал');
  if (maximum < minimum) throw new Error('Максимум участников не может быть меньше минимума.');

  const quote = (value) => '"' + value.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';
  const attributes = [`title=${quote(title)}`, `min=${minimum}`, `max=${maximum}`, `delay=${delay}`];
  const type = values.eventType || 'forum';
  if (!['quest', 'forum'].includes(type)) throw new Error('Выбери тип события.');
  attributes.push(`type=${type}`);
  if (type === 'quest') {
    const difficulty = integer(values.difficulty, 'Сложность');
    if (difficulty > 6) throw new Error('Сложность должна быть от 1 до 6.');
    attributes.push(`difficulty=${difficulty}`);
    for (const [field, attribute] of [['rewardExperience', 'xp'], ['rewardGold', 'gold'], ['rewardItems', 'items']]) {
      const value = values[field] ?? '0';
      if (!/^(0|[1-9][0-9]*)$/.test(String(value)) || Number(value) > 4294967295) throw new Error('Награда должна быть целым числом от 0 до 4294967295.');
      if (Number(value) > 0) attributes.push(`${attribute}=${value}`);
    }
  } else {
    const color = values.customColor || '#d1d5db';
    if (!/^#[0-9a-f]{6}$/i.test(color)) throw new Error('Укажи цвет в формате #caffbf.');
    attributes.push(`color=${quote(color)}`);
  }
  if (values.leader) attributes.push(`leader=${integer(values.leader, 'ID ведущего')}`);
  if (values.draft) attributes.push(`draft=${integer(values.draft, 'Черновик')}`);
  if (values.start) {
    const date = new Date(values.start);
    if (Number.isNaN(date.getTime())) throw new Error('Укажи корректную дату старта.');
    attributes.push(`start=${quote(date.toISOString().replace(/\.\d{3}Z$/, '+00:00'))}`);
  }
  return `[quest ${attributes.join(' ')}]\n${description}\n[/quest]`;
}
