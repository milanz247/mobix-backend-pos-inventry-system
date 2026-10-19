import type { Directive } from 'vue';
import type { Auth } from '@/types/auth';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        flashDataType: {
            toast?: { type: 'success' | 'error'; message: string };
            closedShift?: { id: number; reference: string };
            completedSale?: {
                id: number;
                sale_number: string;
                customer: string;
                total: string;
                cash_tendered: string | null;
                change_given: string;
            };
        };
        sharedPageProps: {
            name: string;
            auth: Auth;
            businessPreferences: {
                name: string | null;
                logoUrl: string | null;
                timezone: string;
                timeFormat: '12' | '24';
            };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}

declare module 'vue' {
    interface GlobalDirectives {
        vFocus: Directive<HTMLElement, boolean | undefined>;
    }

    interface ComponentCustomProperties {
        $inertia: typeof Router;
        $page: Page;
        $headManager: ReturnType<typeof createHeadManager>;
    }
}
