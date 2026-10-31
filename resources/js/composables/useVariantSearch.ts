import { ref, watch, onBeforeUnmount, type Ref } from 'vue';
export function useVariantSearch<T extends { id: number }>(
    initial: T[],
    search: Ref<string>,
    purchase = false,
    allTracking = false,
    retainIds: () => number[] = () => [],
) {
    const variants = ref<T[]>([...initial]) as Ref<T[]>;
    const error = ref('');
    let timer: ReturnType<typeof setTimeout>;
    let controller: AbortController | undefined;
    watch(
        search,
        () => {
            clearTimeout(timer);
            controller?.abort();
            timer = setTimeout(async () => {
                controller = new AbortController();
                try {
                    const response = await fetch(
                        `/catalog/variant-search?purchase=${purchase ? 1 : 0}&all_tracking=${allTracking ? 1 : 0}&search=${encodeURIComponent(search.value)}`,
                        {
                            headers: { Accept: 'application/json' },
                            signal: controller.signal,
                        },
                    );
                    if (!response.ok)
                        throw new Error(
                            'Product search unavailable. Please try again.',
                        );
                    const rows = (await response.json()) as T[];
                    const pinned = new Set(retainIds());
                    const cache = new Map(
                        variants.value
                            .filter((variant) => pinned.has(variant.id))
                            .map((variant) => [variant.id, variant]),
                    );
                    rows.forEach((v) => cache.set(v.id, v));
                    const options = [...cache.values()];
                    const selected = options.filter((variant) =>
                        pinned.has(variant.id),
                    );
                    const suggestions = options.filter(
                        (variant) => !pinned.has(variant.id),
                    );
                    const remaining = Math.max(0, 100 - selected.length);
                    variants.value = [
                        ...selected,
                        ...(remaining ? suggestions.slice(-remaining) : []),
                    ];
                    error.value = '';
                } catch (e) {
                    if (!(e instanceof DOMException && e.name === 'AbortError'))
                        error.value =
                            'Product search unavailable. Please try again.';
                }
            }, 250);
        },
        { immediate: true },
    );
    onBeforeUnmount(() => {
        clearTimeout(timer);
        controller?.abort();
    });
    return { variants, searchError: error };
}
