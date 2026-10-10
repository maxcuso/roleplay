/** Единое чтение ошибок Flarum для мастера и профиля. Отсутствие ответа означает сетевую ошибку. */
export function requestStatus(error: unknown): number | undefined {
  return error && typeof error === 'object' && 'status' in error ? Number(error.status) : undefined;
}
export function requestMessage(error: unknown, fallback: string): string {
  if (error && typeof error === 'object' && 'response' in error) {
    const response = error.response as {errors?: {detail?: string}[]} | undefined;
    return response?.errors?.[0]?.detail || fallback;
  }
  return fallback;
}