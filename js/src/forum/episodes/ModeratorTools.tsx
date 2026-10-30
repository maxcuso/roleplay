import app from 'flarum/forum/app';
import {extend} from 'flarum/common/extend';
import type ComposerState from 'flarum/forum/states/ComposerState';
import type Discussion from 'flarum/common/models/Discussion';
import type Mithril from 'mithril';
import ModeratorMenu from './ModeratorMenu';

export interface ModeratorContext {visible: boolean; discussion?: Discussion; canComplete: boolean; reason: string}

/** Возможности пользователя и текущей темы, без проверки названий групп. */
export function moderatorContext(composer: ComposerState): ModeratorContext {
  const discussion = (composer.body?.attrs?.discussion ?? composer.body?.attrs?.post?.discussion?.()) as Discussion | undefined;
  const visible = Boolean(app.session.user && (app.forum.attribute('canManageQuestEvents')
    || app.forum.attribute('roleplayCanUseWorkspace') || discussion?.attribute('canLock')));
  const reply = composer.bodyMatches('flarum/forum/components/ReplyComposer');
  const canComplete = reply && Boolean(discussion?.attribute('roleplayCanCompleteEpisode'));
  const reason = canComplete ? '' : !discussion
    ? 'Откройте тему и начните ответ, чтобы выдать награды'
    : !reply ? 'Награды выдаются новым сообщением — откройте ответ в теме'
    : discussion.attribute('isLocked') ? 'Эта тема уже закрыта'
    : !discussion.attribute('canLock') ? 'Для выдачи наград требуется право закрыть эту тему'
    : 'Завершение доступно в активном эпизоде, связанном с карточкой квеста';
  return {visible, discussion, canComplete, reason};
}

/** Добавляем только кнопку нижней панели. Вкладки и текст редактора не меняются. */
export function moderatorMenu(composer: ComposerState, disabled = false): Mithril.Children {
  const context = moderatorContext(composer);
  return context.visible ? m(ModeratorMenu, {composer, context, disabled, label: '', helperText: '', className: 'RpModeratorTools'}) : null;
}

export function registerModeratorTools(): void {
  extend('flarum/common/components/TextEditor', 'toolbarItems', function (items) {
    const composer = this.attrs.composer as ComposerState | undefined;
    if (!composer) return;
    const menu = moderatorMenu(composer, Boolean(this.disabled));
    if (menu) items.add('roleplay-moderator', menu, 4);
  });
}
