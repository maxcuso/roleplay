import app from 'flarum/admin/app';

export interface AttributeIconsView {revision: number; rows: {id: number; name: string; icon: string | null}[]}

/** Отправляем только иконки. Даже переименование в другом окне не будет затёрто этой формой. */
export default class AttributeIconsApi {
  private url(): string {return app.forum.attribute<string>('apiUrl') + '/roleplay/admin/attribute-icons';}
  async read(): Promise<AttributeIconsView> {
    return (await app.request<{data: AttributeIconsView}>({method: 'GET', url: this.url(), errorHandler: () => {}})).data;
  }
  async save(data: AttributeIconsView): Promise<void> {
    await app.request({method: 'PUT', url: this.url(), body: {revision: data.revision,
      icons: data.rows.map(row => ({id: row.id, icon: row.icon}))}, errorHandler: () => {}});
  }
}
