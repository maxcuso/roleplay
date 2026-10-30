import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import CatalogSection from '../catalog/components/CatalogSection';
import ApplicationSettingsPanel from '../application/ApplicationSettingsPanel';
import CreationSettingsPanel from '../settings/CreationSettingsPanel';
import AttributeIconsPanel from '../settings/AttributeIconsPanel';
import DevelopmentSettingsPanel from '../settings/development/DevelopmentSettingsPanel';
import {sections, attributeSettings} from '../catalog/definitions';
import {uiIcon} from '../../common/uiIcons';
import app from 'flarum/admin/app';
import type Mithril from 'mithril';
import EventsPage from '../../forum/events/components/EventsPage';

/** Только навигация. Таблицы, формы и запросы живут в своих модулях. */
export default class RoleplayPage extends ExtensionPage {
  selected = 'races';
  content(vnode: Mithril.VnodeDOM<any, this>): JSX.Element {
    const entries = sections.flatMap(value => value.children ?? [value]);
    const section = [...entries, attributeSettings].find(value => value.key === this.selected) ?? entries[0];
    const applicationSelected = this.selected === 'application-settings';
    const creationSelected = this.selected === 'creation-settings';
    const iconsSelected = this.selected === attributeSettings.key;
    const developmentSelected = this.selected === 'development-settings';
    const settingsSelected = applicationSelected || creationSelected || iconsSelected || developmentSelected;
    const eventsSelected = this.selected === 'events';
    const group = sections.find(value => value.children?.some(child => child.key === section.key));
    return <div className="container RpAdmin">
      <div key="navigation" className="RpAdmin-navigation">
        <nav className="RpAdmin-tabs" aria-label="Разделы Roleplay">
          {[...sections.map(value => <Button key={value.key}
            className={'Button ' + (!settingsSelected && !eventsSelected && (section.key === value.key || group?.key === value.key) ? 'active' : '')}
            icon={uiIcon(value.icon)} onclick={() => {this.selected = value.children?.[0].key ?? value.key;}}>{value.title}</Button>),
          app.forum.attribute('roleplayEventsAvailable') && <Button key="events" className={'Button ' + (eventsSelected ? 'active' : '')}
            icon="fas fa-calendar-alt" onclick={() => {this.selected = 'events';}}>События</Button>,
          <Button key="settings" className={'Button ' + (settingsSelected ? 'active' : '')}
            icon={uiIcon('sections.settings')} onclick={() => {if (!settingsSelected) this.selected = attributeSettings.key;}}>Настройки</Button>]}
        </nav>
        {/* Подразделы настроек появляются только внутри общей вкладки, рядом с ними нет справочников. */}
        {settingsSelected && <nav className="RpAdmin-tabs RpAdmin-settingsTabs" aria-label="Настройки Roleplay">
          <Button className={'Button RpAdmin-settings ' + (iconsSelected ? 'active' : '')} icon={uiIcon('fields.attribute')}
            onclick={() => {this.selected = attributeSettings.key;}}>Иконки характеристик</Button>
          <Button className={'Button RpAdmin-settings ' + (creationSelected ? 'active' : '')}
            icon={uiIcon('wizard.title')} onclick={() => {this.selected = 'creation-settings';}}>Создание персонажей</Button>
          <Button className={'Button RpAdmin-settings ' + (applicationSelected ? 'active' : '')}
            icon={uiIcon('review.application')} onclick={() => {this.selected = 'application-settings';}}>Анкеты и проверка</Button>
          <Button className={'Button RpAdmin-settings ' + (developmentSelected ? 'active' : '')}
            icon={uiIcon('settings.development')} onclick={() => {this.selected = 'development-settings';}}>Развитие</Button>
        </nav>}
        {!settingsSelected && !eventsSelected && group && <nav className="RpAdmin-tabs RpAdmin-subtabs" aria-label={group.title}>
          {group.children!.map(value => <Button key={value.key} className={'Button ' + (section.key === value.key ? 'active' : '')}
            onclick={() => {this.selected = value.key;}}>{value.title}</Button>)}
        </nav>}
      </div>
      {eventsSelected ? <div key="events">{super.content(vnode)}<EventsPage/></div>
        : creationSelected ? <CreationSettingsPanel key="creation-settings"/>
        : developmentSelected ? <DevelopmentSettingsPanel key="development-settings"/>
        : applicationSelected ? <ApplicationSettingsPanel key="application-settings"/>
        : iconsSelected ? <AttributeIconsPanel key="attribute-icons"/>
        : section.catalogue ? <CatalogSection key={section.key} definition={section.catalogue}/>
        : <div key={section.key} className="RpAdmin-placeholder"><Icon name={uiIcon(section.icon)}/>
          <h3>{section.title}</h3><p>Этот раздел будет реализован следующим отдельным этапом.</p></div>}
    </div>;
  }
}
