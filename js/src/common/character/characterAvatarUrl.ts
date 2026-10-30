import app from 'flarum/common/app';

/** API передаёт имя обработанного файла; внешний адрес и пути с ../ не допускаются. */
export default function characterAvatarUrl(filename: unknown): string {
  return typeof filename === 'string' && /^avatar-[1-9][0-9]*-[0-9a-f]{32}\.webp$/.test(filename)
    ? app.forum.attribute<string>('baseUrl') + '/assets/roleplay/avatars/' + filename : '';
}
