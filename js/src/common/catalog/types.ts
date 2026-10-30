import type Mithril from 'mithril';
import type {UiIconName} from '../uiIcons';
import type CatalogEditorState from './CatalogEditorState';

import type {CatalogRow, Fields, Lookups} from '../catalogData';
export type {CatalogRow, Fields, Lookups} from '../catalogData';

export interface CatalogResult {
  rows: CatalogRow[];
  total: number;
  page: number;
  per_page: number;
  revision: number;
  lookups: Lookups;
  capabilities?: {view: boolean; create: boolean; edit: boolean; delete: boolean};
}
export interface CatalogColumn {
  key: string;
  title: string;
  render: (row: CatalogRow, lookups: Lookups) => Mithril.Children;
}
export interface CatalogFilter {
  key: string;
  label: string;
  options: (lookups: Lookups) => Record<string, string>;
}
export interface EditorContext {
  state: CatalogEditorState;
  lookups: Lookups;
}
export interface CatalogDefinition {
  key: string;
  title: string;
  icon: UiIconName;
  endpoint: string;
  columns: CatalogColumn[];
  filters: CatalogFilter[];
  create: boolean;
  toggle: boolean;
  remove: boolean;
  removeConfirmation?: (row: CatalogRow) => string;
  template?: boolean;
  initial: (row: CatalogRow | null) => Fields;
  editor: (context: EditorContext) => Mithril.Children;
  revision: (row: CatalogRow | null, result: CatalogResult) => number;
}
export interface CatalogDataSource {
  imageEndpoint?: string;
  list(params: Record<string, string | number>): Promise<CatalogResult>;
  save(id: number | null, revision: number, fields: Fields): Promise<void>;
  toggle(id: number, revision: number, enabled: boolean): Promise<void>;
  remove(id: number, revision: number): Promise<void>;
}
