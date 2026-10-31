<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Banknote,
    ChartNoAxesCombined,
    CircleDollarSign,
    ReceiptText,
    TrendingUp,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

type TrendRow = {
    date: string;
    sales_count: number;
    net_sales: string;
    gross_profit: string | null;
};

const props = defineProps<{
    overview: {
        sales_total: string;
        completed_sales: number;
        cash_received: string;
        gross_profit_today: string | null;
    };
    performance: {
        net_sales_30_days: string;
        gross_profit_30_days: string | null;
    };
    trend: TrendRow[];
    currency: string;
    generatedAt: string;
    canViewCosts: boolean;
    locations: { id: number; name: string }[];
    filters: { location_id: number | '' };
}>();

const trendDays = ref<7 | 30>(7);
const chartWidth = 720;
const chartHeight = 220;
const chartPadding = 16;

const overviewCards = computed(() => [
    {
        label: "Today's sales",
        value: money(props.overview.sales_total),
        detail: 'Gross value posted today, including tax',
        icon: CircleDollarSign,
        tone: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
    },
    {
        label: 'Completed sales',
        value: props.overview.completed_sales.toLocaleString(),
        detail: 'Transactions posted today',
        icon: ReceiptText,
        tone: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
    },
    {
        label: 'Cash received',
        value: money(props.overview.cash_received),
        detail: 'Cash payments applied today',
        icon: Banknote,
        tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    },
    ...(props.canViewCosts && props.overview.gross_profit_today !== null
        ? [
              {
                  label: "Today's gross profit",
                  value: money(props.overview.gross_profit_today),
                  detail: 'Item margin after returns; excludes operating expenses',
                  icon: TrendingUp,
                  tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
              },
          ]
        : []),
]);

const visibleTrend = computed(() => props.trend.slice(-trendDays.value));
const selectedNetSales = computed(() =>
    trendDays.value === 30
        ? Number(props.performance.net_sales_30_days)
        : visibleTrend.value.reduce(
              (total, row) => total + Number(row.net_sales),
              0,
          ),
);
const selectedGrossProfit = computed(() =>
    trendDays.value === 30
        ? Number(props.performance.gross_profit_30_days)
        : visibleTrend.value.reduce(
              (total, row) => total + Number(row.gross_profit),
              0,
          ),
);
const averageDailySales = computed(
    () => selectedNetSales.value / Math.max(1, visibleTrend.value.length),
);
const bestSalesDay = computed(() =>
    visibleTrend.value.reduce<TrendRow | null>(
        (best, row) =>
            !best || Number(row.net_sales) > Number(best.net_sales)
                ? row
                : best,
        null,
    ),
);
const chartBounds = computed(() => {
    const values = visibleTrend.value.flatMap((row) => [
        Number(row.net_sales),
        ...(props.canViewCosts ? [Number(row.gross_profit)] : []),
    ]);
    const minimum = Math.min(0, ...values);
    const maximum = Math.max(0, ...values);

    return {
        minimum,
        maximum: maximum === minimum ? minimum + 1 : maximum,
    };
});
const zeroLineY = computed(() => chartY(0));
const slotWidth = computed(
    () =>
        (chartWidth - chartPadding * 2) /
        Math.max(1, visibleTrend.value.length),
);
const barWidth = computed(() =>
    Math.min(14, slotWidth.value * (props.canViewCosts ? 0.28 : 0.5)),
);
const generatedTime = computed(() =>
    new Intl.DateTimeFormat(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(props.generatedAt)),
);

function money(value: string | number): string {
    const amount = Number(value);

    return (
        props.currency +
        ' ' +
        new Intl.NumberFormat(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(Number.isFinite(amount) ? amount : 0)
    );
}

function barX(index: number, series: 0 | 1): number {
    const seriesCount = props.canViewCosts ? 2 : 1;
    const groupWidth = barWidth.value * seriesCount + (seriesCount - 1) * 2;

    return (
        chartPadding +
        index * slotWidth.value +
        (slotWidth.value - groupWidth) / 2 +
        series * (barWidth.value + 2)
    );
}

function barY(value: number): number {
    return Math.min(chartY(value), zeroLineY.value);
}

function barHeight(value: number): number {
    return Math.max(1, Math.abs(chartY(value) - zeroLineY.value));
}

function chartY(value: number): number {
    const usableHeight = chartHeight - chartPadding * 2;
    const range = chartBounds.value.maximum - chartBounds.value.minimum;

    return (
        chartHeight -
        chartPadding -
        ((value - chartBounds.value.minimum) / range) * usableHeight
    );
}

function shortDate(date: string): string {
    return new Intl.DateTimeFormat(undefined, {
        month: 'short',
        day: 'numeric',
    }).format(new Date(date + 'T00:00:00'));
}

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <section
        class="page-shell min-h-0 gap-3 overflow-y-auto xl:h-0 xl:flex-1 xl:overflow-hidden"
    >
        <div class="flex flex-wrap items-end justify-between gap-3">
            <Heading
                title="Sales dashboard"
                description="A live view of today's trading position and the last 30 days of sales performance."
            />
            <div class="flex items-center gap-3">
                <select
                    :value="filters.location_id"
                    aria-label="Dashboard location"
                    @change="
                        router.get(
                            dashboard().url,
                            {
                                location_id: (
                                    $event.target as HTMLSelectElement
                                ).value,
                            },
                            { preserveScroll: true },
                        )
                    "
                >
                    <option value="">All authorized locations</option>
                    <option
                        v-for="location in locations"
                        :key="location.id"
                        :value="location.id"
                    >
                        {{ location.name }}
                    </option>
                </select>
                <p class="hidden text-xs text-muted-foreground sm:block">
                    Updated {{ generatedTime }}
                </p>
            </div>
        </div>

        <section aria-labelledby="sales-overview-heading" class="space-y-2">
            <div>
                <h2 id="sales-overview-heading" class="font-semibold">
                    Sales overview
                </h2>
                <p class="text-sm text-muted-foreground">
                    Today’s sales, cash received, and recorded item margin.
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <article
                    v-for="card in overviewCards"
                    :key="card.label"
                    class="surface-panel min-w-0 p-3"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{ card.label }}
                            </p>
                            <p
                                class="mt-1 truncate text-xl font-semibold tracking-tight tabular-nums"
                                :title="card.value"
                            >
                                {{ card.value }}
                            </p>
                        </div>
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-lg"
                            :class="card.tone"
                        >
                            <component
                                :is="card.icon"
                                class="size-4"
                                aria-hidden="true"
                            />
                        </span>
                    </div>
                    <p class="mt-2 text-xs leading-4 text-muted-foreground">
                        {{ card.detail }}
                    </p>
                </article>
            </div>
        </section>

        <section
            aria-labelledby="sales-performance-heading"
            class="min-h-0 space-y-2 xl:flex xl:flex-1 xl:flex-col"
        >
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="sales-performance-heading" class="font-semibold">
                        {{
                            canViewCosts
                                ? 'Sales & profit'
                                : 'Sales performance'
                        }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            canViewCosts
                                ? 'Sales include tax. Gross profit excludes included tax and returned margin, but excludes operating expenses; it is not net business profit.'
                                : 'Net sales after returns for your authorized locations.'
                        }}
                    </p>
                </div>
                <div
                    class="inline-flex rounded-lg border bg-muted/40 p-1"
                    aria-label="Sales trend period"
                >
                    <button
                        v-for="days in [7, 30] as const"
                        :key="days"
                        type="button"
                        class="rounded-md px-3 py-1 text-xs font-semibold transition-colors"
                        :class="
                            trendDays === days
                                ? 'bg-card text-foreground shadow-xs'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="trendDays = days"
                    >
                        {{ days }} days
                    </button>
                </div>
            </div>

            <div
                class="grid gap-3 xl:min-h-0 xl:flex-1 xl:grid-cols-[minmax(0,1.7fr)_minmax(240px,0.6fr)]"
            >
                <article class="surface-panel overflow-hidden">
                    <div
                        class="flex flex-wrap items-center justify-between gap-3 border-b px-4 py-3"
                    >
                        <div>
                            <h3 class="font-semibold">Sales by day</h3>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    canViewCosts
                                        ? 'Daily net sales and gross profit'
                                        : 'Daily net sales'
                                }}
                            </p>
                        </div>
                        <div class="flex items-center gap-4 text-xs">
                            <span class="flex items-center gap-1.5">
                                <span class="size-2 rounded-full bg-blue-500" />
                                Net sales
                            </span>
                            <span
                                v-if="canViewCosts"
                                class="flex items-center gap-1.5"
                            >
                                <span
                                    class="size-2 rounded-full bg-emerald-500"
                                />
                                Gross profit
                            </span>
                        </div>
                    </div>
                    <div class="p-3">
                        <svg
                            class="h-40 w-full overflow-visible 2xl:h-48"
                            :viewBox="'0 0 ' + chartWidth + ' ' + chartHeight"
                            role="img"
                            :aria-label="
                                trendDays +
                                (canViewCosts
                                    ? '-day net sales and gross profit trend'
                                    : '-day net sales trend')
                            "
                            preserveAspectRatio="none"
                        >
                            <line
                                v-for="line in [0.25, 0.5, 0.75]"
                                :key="line"
                                :x1="chartPadding"
                                :x2="chartWidth - chartPadding"
                                :y1="
                                    chartPadding +
                                    line * (chartHeight - chartPadding * 2)
                                "
                                :y2="
                                    chartPadding +
                                    line * (chartHeight - chartPadding * 2)
                                "
                                stroke="currentColor"
                                class="text-border"
                                stroke-dasharray="4 6"
                            />
                            <line
                                :x1="chartPadding"
                                :x2="chartWidth - chartPadding"
                                :y1="zeroLineY"
                                :y2="zeroLineY"
                                stroke="currentColor"
                                class="text-muted-foreground/50"
                            />
                            <g
                                v-for="(row, index) in visibleTrend"
                                :key="row.date"
                            >
                                <rect
                                    :x="barX(index, 0)"
                                    :y="barY(Number(row.net_sales))"
                                    :width="barWidth"
                                    :height="barHeight(Number(row.net_sales))"
                                    rx="2"
                                    fill="rgb(59 130 246)"
                                >
                                    <title>
                                        {{ shortDate(row.date) }} · Net sales
                                        {{ money(row.net_sales) }}
                                    </title>
                                </rect>
                                <rect
                                    v-if="canViewCosts"
                                    :x="barX(index, 1)"
                                    :y="barY(Number(row.gross_profit))"
                                    :width="barWidth"
                                    :height="
                                        barHeight(Number(row.gross_profit))
                                    "
                                    rx="2"
                                    fill="rgb(16 185 129)"
                                >
                                    <title>
                                        {{ shortDate(row.date) }} · Gross profit
                                        {{ money(row.gross_profit ?? '0') }}
                                    </title>
                                </rect>
                            </g>
                        </svg>
                        <div
                            v-if="visibleTrend.length"
                            class="mt-2 flex justify-between text-xs text-muted-foreground"
                        >
                            <span>{{ shortDate(visibleTrend[0].date) }}</span>
                            <span>{{
                                shortDate(
                                    visibleTrend[
                                        Math.floor(visibleTrend.length / 2)
                                    ].date,
                                )
                            }}</span>
                            <span>{{
                                shortDate(
                                    visibleTrend[visibleTrend.length - 1].date,
                                )
                            }}</span>
                        </div>
                        <div
                            v-if="bestSalesDay"
                            class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1 border-t pt-2 text-xs"
                        >
                            <p class="text-muted-foreground">
                                Daily average:
                                <span class="font-semibold text-foreground">
                                    {{ money(averageDailySales) }}
                                </span>
                            </p>
                            <p class="text-muted-foreground">
                                Best day:
                                <span class="font-semibold text-foreground">
                                    {{ shortDate(bestSalesDay.date) }}
                                    · {{ money(bestSalesDay.net_sales) }}
                                </span>
                            </p>
                        </div>
                    </div>
                </article>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                    <article class="surface-panel relative overflow-hidden p-3">
                        <div
                            class="absolute -top-8 -right-8 size-24 rounded-full bg-blue-500/10"
                            aria-hidden="true"
                        />
                        <ChartNoAxesCombined
                            class="size-5 text-blue-600 dark:text-blue-400"
                            aria-hidden="true"
                        />
                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ trendDays }}-day net sales
                        </p>
                        <p
                            class="mt-1 text-2xl font-semibold tracking-tight tabular-nums"
                        >
                            {{ money(selectedNetSales) }}
                        </p>
                    </article>
                    <article
                        v-if="canViewCosts"
                        class="surface-panel relative overflow-hidden p-3"
                    >
                        <div
                            class="absolute -top-8 -right-8 size-24 rounded-full bg-emerald-500/10"
                            aria-hidden="true"
                        />
                        <TrendingUp
                            class="size-5 text-emerald-600 dark:text-emerald-400"
                            aria-hidden="true"
                        />
                        <p class="mt-2 text-xs text-muted-foreground">
                            {{ trendDays }}-day gross profit
                        </p>
                        <p
                            class="mt-1 text-2xl font-semibold tracking-tight tabular-nums"
                        >
                            {{ money(selectedGrossProfit) }}
                        </p>
                        <p class="mt-2 text-xs text-muted-foreground">
                            Revenue less recorded item cost, adjusted for
                            returns.
                        </p>
                    </article>
                </div>
            </div>
        </section>
    </section>
</template>
