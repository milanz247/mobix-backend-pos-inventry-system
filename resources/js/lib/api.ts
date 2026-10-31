export async function api<T>(
    url: string,
    options: RequestInit = {},
): Promise<T> {
    const headers = new Headers(options.headers);
    headers.set('Accept', 'application/json');
    headers.set('Content-Type', 'application/json');
    headers.set(
        'X-CSRF-TOKEN',
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? '',
    );
    const response = await fetch(url, {
        ...options,
        credentials: 'same-origin',
        headers,
    });
    if (!response.ok) {
        const body = await response.json().catch(() => null);
        const errors = body?.errors
            ? Object.values(body.errors).flat().join(' ')
            : null;
        throw new Error(
            errors || body?.message || 'The request failed. Please try again.',
        );
    }
    return response.json() as Promise<T>;
}
