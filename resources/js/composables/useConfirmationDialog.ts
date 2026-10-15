import { computed, ref, shallowRef } from 'vue';

export type ConfirmationOptions = {
    title: string;
    description: string;
    confirmLabel: string;
    destructive?: boolean;
    onConfirm: () => void;
};

export function useConfirmationDialog() {
    const isOpen = ref(false);
    const options = shallowRef<ConfirmationOptions | null>(null);
    const title = computed(() => options.value?.title ?? '');
    const description = computed(() => options.value?.description ?? '');
    const confirmLabel = computed(
        () => options.value?.confirmLabel ?? 'Confirm',
    );
    const destructive = computed(() => options.value?.destructive ?? false);

    const request = (confirmation: ConfirmationOptions): void => {
        options.value = confirmation;
        isOpen.value = true;
    };

    const setOpen = (open: boolean): void => {
        isOpen.value = open;

        if (!open) {
            options.value = null;
        }
    };

    const confirm = (): void => {
        const onConfirm = options.value?.onConfirm;

        setOpen(false);
        onConfirm?.();
    };

    return {
        isOpen,
        title,
        description,
        confirmLabel,
        destructive,
        request,
        setOpen,
        confirm,
    };
}
