import { router } from '@inertiajs/vue3';
import { onMounted, onBeforeUnmount } from 'vue';
export function useUnsavedChanges(
    dirty: () => boolean,
    submitting: () => boolean = () => false,
) {
    const warn = (event: BeforeUnloadEvent) => {
        if (dirty() && !submitting()) {
            event.preventDefault();
            event.returnValue = '';
        }
    };
    let remove: (() => void) | undefined;
    onMounted(() => {
        window.addEventListener('beforeunload', warn);
        remove = router.on('before', (event) => {
            if (
                event.detail.visit.method === 'get' &&
                dirty() &&
                !submitting() &&
                !window.confirm(
                    'Leave this page? Unsaved changes will be lost.',
                )
            )
                event.preventDefault();
        });
    });
    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', warn);
        remove?.();
    });
}
