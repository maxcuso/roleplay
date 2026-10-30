/** Только автономный стенд: адреса относительные, обращения к форуму запрещены. */
export default {
  forum: {attribute<T>(name: string): T {return (name === 'apiUrl' ? '/preview-api-disabled' : '') as T;}},
  request<T>(_options: {serialize?: (value: unknown) => unknown; [key: string]: unknown}): Promise<T> {
    return Promise.reject(new Error('Загрузка файлов в визуальном стенде недоступна'));
  },
  alerts: {
    show(_attrs: unknown, message: unknown): number {console.warn('Предпросмотр:', message); return 1;},
    dismiss(_id: number): void {},
  },
};
