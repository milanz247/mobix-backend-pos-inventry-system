<script setup lang="ts">
import { Head, Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import ShiftSummaryPanel from '@/components/shifts/ShiftSummary.vue';
import ShiftPayments from '@/components/shifts/ShiftPayments.vue';
import ShiftPagination from '@/components/shifts/ShiftPagination.vue';
import { shiftMoney, shiftDate } from '@/lib/shift';
import { index, create, show, movements, closing } from '@/routes/shifts';
import type { Auth } from '@/types';
import type { Paginated, Shift, ShiftSummary } from '@/types/shift';
const props = defineProps<{
    shifts: Paginated<Shift>;
    activeShift: Shift | null;
    activeSummary: ShiftSummary | null;
    locations: { id: number; name: string }[];
    cashiers: { id: number; name: string }[];
    filters: Record<string, string>;
    currency: string;
    timezone: string;
}>();
const page = usePage<{ auth: Auth }>();
const can = (permission: string): boolean =>
    page.props.auth.permissions.includes(permission);
const filters = reactive({
    date_from: '',
    date_to: '',
    location_id: '',
    cashier_id: '',
    status: '',
    variance: '',
    ...props.filters,
});
function filter(): void {
    router.get(index().url, filters, {
        preserveState: true,
        replace: true,
        preserveScroll: true,
    });
}
usePoll(15000, { only: ['activeShift', 'activeSummary', 'shifts'] });
</script>
<template>
    <section class="page-shell space-y-6">
        <Head title="Cashier shifts" />
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Cashier shifts"
                description="Live drawer balances, physical cash reconciliation, and daily closing history."
            /><Button v-if="!activeShift && can('shifts.open')" as-child
                ><Link :href="create()">Open shift</Link></Button
            >
        </div>
        <template v-if="activeShift && activeSummary">
            <div
                class="surface-panel flex flex-wrap items-center justify-between gap-4 p-4"
            >
                <div>
                    <p class="text-xs font-semibold text-primary uppercase">
                        Your active shift
                    </p>
                    <h2 class="mt-1 font-semibold">
                        {{ activeShift.location.name }} ·
                        {{ activeShift.reference }}
                    </h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Opened {{ shiftDate(activeShift.opened_at, timezone) }}
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" as-child
                        ><Link :href="show(activeShift.id)"
                            >Details</Link
                        ></Button
                    ><Button
                        v-if="can('shifts.movements')"
                        variant="outline"
                        as-child
                        ><Link :href="movements(activeShift.id)"
                            >Cash movement</Link
                        ></Button
                    ><Button v-if="can('shifts.close')" as-child
                        ><Link :href="closing(activeShift.id)"
                            >Close shift</Link
                        ></Button
                    >
                </div>
            </div>
            <div class="grid gap-5 lg:grid-cols-2">
                <ShiftSummaryPanel
                    :summary="activeSummary"
                    :currency="currency"
                /><ShiftPayments
                    :summary="activeSummary"
                    :currency="currency"
                />
            </div>
        </template>
        <p v-else class="surface-panel p-5 text-sm text-muted-foreground">
            You have no active shift. Open a shift to track new payments in your
            drawer. Transactions recorded without a shift are not added
            retrospectively.
        </p>
        <section class="table-panel">
            <h2 class="border-b p-4 font-semibold">
                Shift history and location status
            </h2>
            <form
                class="grid gap-3 border-b p-4 sm:grid-cols-2 lg:grid-cols-4"
                @submit.prevent="filter"
            >
                <label class="grid gap-1 text-xs font-medium"
                    >From<Input v-model="filters.date_from" type="date"
                /></label>
                <label class="grid gap-1 text-xs font-medium"
                    >To<Input v-model="filters.date_to" type="date"
                /></label>
                <label class="grid gap-1 text-xs font-medium"
                    >Location<select
                        v-model="filters.location_id"
                        class="h-9 rounded-lg border bg-background px-2"
                    >
                        <option value="">All accessible locations</option>
                        <option
                            v-for="location in locations"
                            :key="location.id"
                            :value="location.id"
                        >
                            {{ location.name }}
                        </option>
                    </select></label
                >
                <label
                    v-if="can('shifts.view-all')"
                    class="grid gap-1 text-xs font-medium"
                    >Cashier<select
                        v-model="filters.cashier_id"
                        class="h-9 rounded-lg border bg-background px-2"
                    >
                        <option value="">All cashiers</option>
                        <option
                            v-for="cashier in cashiers"
                            :key="cashier.id"
                            :value="cashier.id"
                        >
                            {{ cashier.name }}
                        </option>
                    </select></label
                >
                <label class="grid gap-1 text-xs font-medium"
                    >Status<select
                        v-model="filters.status"
                        class="h-9 rounded-lg border bg-background px-2"
                    >
                        <option value="">All statuses</option>
                        <option value="open">Open</option>
                        <option value="closed">Closed · balanced</option>
                        <option value="pending_approval">
                            Pending approval
                        </option>
                        <option value="approved">Approved</option>
                    </select></label
                >
                <label class="grid gap-1 text-xs font-medium"
                    >Variance<select
                        v-model="filters.variance"
                        class="h-9 rounded-lg border bg-background px-2"
                    >
                        <option value="">All variances</option>
                        <option value="balanced">Balanced</option>
                        <option value="shortage">Shortage</option>
                        <option value="surplus">Surplus</option>
                    </select></label
                >
                <Button type="submit" class="self-end">Apply filters</Button>
            </form>
            <p
                v-for="(error, key) in page.props.errors"
                :key="key"
                class="px-4 py-1 text-sm text-destructive"
            >
                {{ error }}
            </p>
            <div class="overflow-x-auto">
                <table class="data-table min-w-[860px]">
                    <thead>
                        <tr>
                            <th>Shift</th>
                            <th>Cashier / location</th>
                            <th>Opened</th>
                            <th>Status</th>
                            <th class="text-right">Opening</th>
                            <th class="text-right">Variance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="shift in shifts.data" :key="shift.id">
                            <td>
                                <Link
                                    :href="show(shift.id)"
                                    class="font-medium text-primary hover:underline"
                                    >{{ shift.reference }}</Link
                                >
                            </td>
                            <td>
                                {{ shift.cashier.name
                                }}<span
                                    class="block text-xs text-muted-foreground"
                                    >{{ shift.location.name }}</span
                                >
                            </td>
                            <td>{{ shiftDate(shift.opened_at, timezone) }}</td>
                            <td class="capitalize">
                                {{ shift.status.replaceAll('_', ' ') }}
                            </td>
                            <td class="text-right tabular-nums">
                                {{ shiftMoney(shift.opening_cash, currency) }}
                            </td>
                            <td
                                class="text-right tabular-nums"
                                :class="
                                    shift.variance?.startsWith('-')
                                        ? 'text-destructive'
                                        : ''
                                "
                            >
                                {{
                                    shift.variance === null
                                        ? '—'
                                        : shiftMoney(shift.variance, currency)
                                }}
                            </td>
                        </tr>
                        <tr v-if="!shifts.data.length">
                            <td
                                colspan="6"
                                class="py-10 text-center text-muted-foreground"
                            >
                                No shifts match your filters.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <ShiftPagination :links="shifts.links" />
        </section>
    </section>
</template>
