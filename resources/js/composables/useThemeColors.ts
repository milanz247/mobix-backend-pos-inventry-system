import { ref } from 'vue';

export type ThemeColors = {
    primary: string;
    page: string;
    sidebar: string;
};

export const defaultThemeColors: ThemeColors = {
    primary: '#2457c5',
    page: '#f5f7fb',
    sidebar: '#0f172a',
};

export const themePresets: {
    id: string;
    name: string;
    description: string;
    colors: ThemeColors;
}[] = [
    {
        id: 'classic-blue',
        name: 'Classic blue',
        description: 'Clear, familiar, and focused',
        colors: defaultThemeColors,
    },
    {
        id: 'ocean-teal',
        name: 'Ocean teal',
        description: 'Calm teal with a cool canvas',
        colors: { primary: '#0f766e', page: '#f1f8f7', sidebar: '#12332f' },
    },
    {
        id: 'forest-green',
        name: 'Forest green',
        description: 'Natural green with soft neutrals',
        colors: { primary: '#15803d', page: '#f3f8f3', sidebar: '#152b1e' },
    },
    {
        id: 'violet',
        name: 'Violet',
        description: 'Distinct violet with quiet surfaces',
        colors: { primary: '#6d28d9', page: '#f7f5fc', sidebar: '#24183c' },
    },
    {
        id: 'warm-amber',
        name: 'Warm amber',
        description: 'Warm gold with deep brown navigation',
        colors: { primary: '#a16207', page: '#faf7f0', sidebar: '#30230f' },
    },
];

const storageKey = 'nalakamobile:theme-colors:v1';
const colors = ref<ThemeColors>({ ...defaultThemeColors });

function isHexColor(value: unknown): value is string {
    return typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value);
}

function readColors(): ThemeColors | null {
    try {
        const parsed: unknown = JSON.parse(
            window.localStorage.getItem(storageKey) ?? 'null',
        );
        if (typeof parsed !== 'object' || parsed === null) return null;
        const candidate = parsed as Partial<ThemeColors>;
        if (
            !isHexColor(candidate.primary) ||
            !isHexColor(candidate.page) ||
            !isHexColor(candidate.sidebar)
        ) {
            return null;
        }
        return {
            primary: candidate.primary,
            page: candidate.page,
            sidebar: candidate.sidebar,
        };
    } catch {
        return null;
    }
}

export function contrastText(hex: string): string {
    const channels = [1, 3, 5].map((offset) => {
        const channel =
            Number.parseInt(hex.slice(offset, offset + 2), 16) / 255;
        return channel <= 0.04045
            ? channel / 12.92
            : ((channel + 0.055) / 1.055) ** 2.4;
    });
    const luminance =
        0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
    const blackContrast = (luminance + 0.05) / 0.05;
    const whiteContrast = 1.05 / (luminance + 0.05);

    return blackContrast >= whiteContrast ? '#000000' : '#ffffff';
}

function mixHex(first: string, second: string, firstWeight: number): string {
    const channels = [1, 3, 5].map((offset) => {
        const a = Number.parseInt(first.slice(offset, offset + 2), 16);
        const b = Number.parseInt(second.slice(offset, offset + 2), 16);
        return Math.round(a * firstWeight + b * (1 - firstWeight));
    });
    return `#${channels.map((channel) => channel.toString(16).padStart(2, '0')).join('')}`;
}

export function getDisplayColors(value: ThemeColors, dark: boolean) {
    return {
        ...value,
        page: dark ? mixHex(value.page, '#0b1220', 0.14) : value.page,
        sidebar: dark ? mixHex(value.sidebar, '#05070c', 0.48) : value.sidebar,
    };
}

function setProperty(name: string, value: string): void {
    document.documentElement.style.setProperty(name, value);
}

function applyThemeColors(value: ThemeColors): void {
    const display = getDisplayColors(
        value,
        document.documentElement.classList.contains('dark'),
    );
    const primaryText = contrastText(display.primary);
    const pageText = contrastText(display.page);
    const sidebarText = contrastText(display.sidebar);

    setProperty('--background', display.page);
    setProperty('--foreground', pageText);
    setProperty(
        '--card',
        `color-mix(in srgb, ${display.page} 96%, ${pageText})`,
    );
    setProperty('--card-foreground', pageText);
    setProperty(
        '--popover',
        `color-mix(in srgb, ${display.page} 96%, ${pageText})`,
    );
    setProperty('--popover-foreground', pageText);
    setProperty('--primary', value.primary);
    setProperty('--primary-foreground', primaryText);
    setProperty('--ring', value.primary);
    setProperty(
        '--secondary',
        `color-mix(in srgb, ${display.page} 88%, ${pageText})`,
    );
    setProperty('--secondary-foreground', pageText);
    setProperty(
        '--muted',
        `color-mix(in srgb, ${display.page} 91%, ${pageText})`,
    );
    setProperty('--muted-foreground', pageText);
    setProperty(
        '--accent',
        `color-mix(in srgb, ${display.page} 88%, ${display.primary})`,
    );
    setProperty('--accent-foreground', pageText);
    setProperty(
        '--border',
        `color-mix(in srgb, ${display.page} 78%, ${pageText})`,
    );
    setProperty(
        '--input',
        `color-mix(in srgb, ${display.page} 52%, ${pageText})`,
    );
    setProperty('--sidebar-background', display.sidebar);
    setProperty('--sidebar', display.sidebar);
    setProperty('--sidebar-foreground', sidebarText);
    setProperty('--sidebar-primary', display.primary);
    setProperty('--sidebar-primary-foreground', primaryText);
    setProperty(
        '--sidebar-accent',
        `color-mix(in srgb, ${display.sidebar} 84%, ${sidebarText})`,
    );
    setProperty('--sidebar-accent-foreground', sidebarText);
    setProperty(
        '--sidebar-border',
        `color-mix(in srgb, ${display.sidebar} 76%, ${sidebarText})`,
    );
    setProperty('--sidebar-ring', display.primary);
}

let themeObserver: MutationObserver | null = null;
let customizationEnabled = false;
function observeAppearanceMode(): void {
    themeObserver?.disconnect();
    themeObserver = new MutationObserver(() => {
        if (customizationEnabled) applyThemeColors(colors.value);
    });
    themeObserver.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class'],
    });
}

export function initializeThemeColors(): void {
    if (typeof window === 'undefined') return;
    const stored = readColors();
    if (!stored) return;
    colors.value = stored;
    customizationEnabled = true;
    applyThemeColors(stored);
    observeAppearanceMode();
}

export function useThemeColors() {
    function updateColors(value: ThemeColors): void {
        colors.value = { ...value };
        customizationEnabled = true;
        applyThemeColors(colors.value);
        window.localStorage.setItem(storageKey, JSON.stringify(colors.value));
        if (!themeObserver) observeAppearanceMode();
    }

    function resetColors(): void {
        colors.value = { ...defaultThemeColors };
        customizationEnabled = false;
        window.localStorage.removeItem(storageKey);
        for (const property of [
            '--background',
            '--foreground',
            '--card',
            '--card-foreground',
            '--popover',
            '--popover-foreground',
            '--primary',
            '--primary-foreground',
            '--ring',
            '--secondary',
            '--secondary-foreground',
            '--muted',
            '--muted-foreground',
            '--accent',
            '--accent-foreground',
            '--border',
            '--input',
            '--sidebar-background',
            '--sidebar',
            '--sidebar-foreground',
            '--sidebar-primary',
            '--sidebar-primary-foreground',
            '--sidebar-accent',
            '--sidebar-accent-foreground',
            '--sidebar-border',
            '--sidebar-ring',
        ]) {
            document.documentElement.style.removeProperty(property);
        }
    }

    return { colors, updateColors, resetColors };
}
