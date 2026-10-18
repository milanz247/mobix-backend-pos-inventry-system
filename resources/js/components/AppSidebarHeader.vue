<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Calculator, ShoppingCart } from '@lucide/vue';
import { computed, ref } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { getInitials } from '@/composables/useInitials';
import { index as posIndex } from '@/routes/pos';
import type { Auth, BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const page = usePage<{ auth: Auth }>();
const user = computed(() => page.props.auth.user);
const canAccessPos = computed(() =>
    page.props.auth.permissions.includes('sales.pos'),
);
const calculatorOpen = ref(false);
const calculatorDisplay = ref('0');
const storedValue = ref<number | null>(null);
const pendingOperation = ref<'+' | '−' | '×' | '÷' | null>(null);
const replaceDisplay = ref(true);

function enterDigit(digit: string): void {
    calculatorDisplay.value =
        replaceDisplay.value || calculatorDisplay.value === 'Error'
            ? digit
            : `${calculatorDisplay.value}${digit}`.slice(0, 14);
    replaceDisplay.value = false;
}

function enterDecimal(): void {
    if (replaceDisplay.value || calculatorDisplay.value === 'Error') {
        calculatorDisplay.value = '0.';
        replaceDisplay.value = false;

        return;
    }

    if (!calculatorDisplay.value.includes('.')) {
        calculatorDisplay.value += '.';
    }
}

function formatCalculatorValue(value: number): string {
    if (!Number.isFinite(value)) {
        return 'Error';
    }

    return String(Number(value.toPrecision(12))).slice(0, 14);
}

function calculateResult(): void {
    if (storedValue.value === null || pendingOperation.value === null) {
        return;
    }

    const currentValue = Number(calculatorDisplay.value);
    const result = {
        '+': storedValue.value + currentValue,
        '−': storedValue.value - currentValue,
        '×': storedValue.value * currentValue,
        '÷': currentValue === 0 ? Number.NaN : storedValue.value / currentValue,
    }[pendingOperation.value];

    calculatorDisplay.value = formatCalculatorValue(result);
    storedValue.value = null;
    pendingOperation.value = null;
    replaceDisplay.value = true;
}

function chooseOperation(operation: '+' | '−' | '×' | '÷'): void {
    if (storedValue.value !== null && !replaceDisplay.value) {
        calculateResult();
    }

    storedValue.value = Number(calculatorDisplay.value);
    pendingOperation.value = operation;
    replaceDisplay.value = true;
}

function clearCalculator(): void {
    calculatorDisplay.value = '0';
    storedValue.value = null;
    pendingOperation.value = null;
    replaceDisplay.value = true;
}

function backspaceCalculator(): void {
    if (replaceDisplay.value || calculatorDisplay.value === 'Error') {
        return;
    }

    calculatorDisplay.value = calculatorDisplay.value.slice(0, -1) || '0';
}
</script>

<template>
    <div>
        <header
            class="sticky top-0 z-20 flex h-16 shrink-0 items-center gap-2 border-b border-border/70 bg-card/95 px-3 shadow-sm shadow-slate-950/5 backdrop-blur-md sm:px-4"
        >
            <div class="flex min-w-0 items-center gap-2">
                <SidebarTrigger
                    class="-ml-1 size-9 rounded-lg border border-transparent hover:border-border hover:bg-accent"
                />
                <template v-if="breadcrumbs && breadcrumbs.length > 0">
                    <Breadcrumbs :breadcrumbs="breadcrumbs" />
                </template>
            </div>

            <div class="ml-auto flex shrink-0 items-center gap-2 sm:gap-3">
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    class="size-9 rounded-lg"
                    aria-label="Open calculator"
                    title="Calculator"
                    @click="calculatorOpen = true"
                >
                    <Calculator class="size-4" />
                </Button>
                <Button
                    v-if="canAccessPos"
                    as-child
                    size="sm"
                    class="h-9 px-3 shadow-md shadow-primary/15"
                >
                    <Link :href="posIndex()" prefetch>
                        <ShoppingCart />
                        <span class="hidden sm:inline">Point of sale</span>
                        <span class="sm:hidden">POS</span>
                    </Link>
                </Button>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="outline"
                            class="h-10 gap-2 rounded-full border-border/70 bg-background py-1 pr-2 pl-1 shadow-xs hover:bg-accent sm:gap-2.5 sm:pr-3"
                            :aria-label="`Open ${user.name} profile menu`"
                        >
                            <Avatar
                                class="size-8 shrink-0 rounded-full ring-2 ring-primary/10"
                            >
                                <AvatarImage
                                    v-if="user.avatar"
                                    :src="user.avatar"
                                    :alt="user.name"
                                />
                                <AvatarFallback
                                    class="rounded-full bg-primary/10 text-xs font-semibold text-primary"
                                >
                                    {{ getInitials(user.name) }}
                                </AvatarFallback>
                            </Avatar>
                            <span
                                class="hidden max-w-36 truncate text-sm font-medium sm:inline"
                            >
                                {{ user.name }}
                            </span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-60">
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>

        <Dialog v-model:open="calculatorOpen">
            <DialogContent class="gap-4 sm:max-w-xs">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <Calculator class="size-5 text-primary" /> Calculator
                    </DialogTitle>
                    <DialogDescription>
                        Quick calculations without leaving the current screen.
                    </DialogDescription>
                </DialogHeader>

                <div
                    class="rounded-xl border bg-slate-950 px-4 py-4 text-right text-white shadow-inner"
                >
                    <p class="h-4 text-xs text-slate-400">
                        {{ storedValue ?? '' }} {{ pendingOperation ?? '' }}
                    </p>
                    <output
                        class="block min-h-10 overflow-hidden text-3xl font-semibold tracking-tight tabular-nums"
                    >
                        {{ calculatorDisplay }}
                    </output>
                </div>

                <div class="grid grid-cols-4 gap-2">
                    <Button variant="secondary" @click="clearCalculator"
                        >C</Button
                    >
                    <Button variant="secondary" @click="backspaceCalculator"
                        >⌫</Button
                    >
                    <Button
                        variant="secondary"
                        @click="
                            calculatorDisplay = formatCalculatorValue(
                                Number(calculatorDisplay) / 100,
                            )
                        "
                        >%</Button
                    >
                    <Button variant="outline" @click="chooseOperation('÷')"
                        >÷</Button
                    >
                    <Button
                        v-for="digit in ['7', '8', '9']"
                        :key="digit"
                        variant="outline"
                        @click="enterDigit(digit)"
                        >{{ digit }}</Button
                    >
                    <Button variant="outline" @click="chooseOperation('×')"
                        >×</Button
                    >
                    <Button
                        v-for="digit in ['4', '5', '6']"
                        :key="digit"
                        variant="outline"
                        @click="enterDigit(digit)"
                        >{{ digit }}</Button
                    >
                    <Button variant="outline" @click="chooseOperation('−')"
                        >−</Button
                    >
                    <Button
                        v-for="digit in ['1', '2', '3']"
                        :key="digit"
                        variant="outline"
                        @click="enterDigit(digit)"
                        >{{ digit }}</Button
                    >
                    <Button variant="outline" @click="chooseOperation('+')"
                        >+</Button
                    >
                    <Button
                        variant="outline"
                        class="col-span-2"
                        @click="enterDigit('0')"
                        >0</Button
                    >
                    <Button variant="outline" @click="enterDecimal">.</Button>
                    <Button
                        class="shadow-md shadow-primary/20"
                        @click="calculateResult"
                        >=</Button
                    >
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
