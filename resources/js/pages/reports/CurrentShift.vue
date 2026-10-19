<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import ReportFilters from '@/components/reports/ReportFilters.vue';
import { Button } from '@/components/ui/button';
import { currentShift } from '@/routes/reports';
import { index as shiftHistory } from '@/routes/shifts';
import { index as pos } from '@/routes/pos';
defineProps<{
    locations: { id: number; name: string }[];
    locationId: string | number;
}>();
</script>
<template>
    <section class="page-shell space-y-5">
        <Head title="Current Shift report" /><Heading
            title="Current Shift"
            description="Trace drawer totals to their posted sales, payments, refunds, expenses and cash movements."
        />
        <ReportFilters
            :action="currentShift().url"
            :filters="{ location_id: locationId }"
            :fields="[
                {
                    key: 'location_id',
                    label: 'Location',
                    type: 'select',
                    emptyLabel: 'Your current shift at any authorized location',
                    options: locations.map((item) => ({
                        value: item.id,
                        label: item.name,
                    })),
                },
            ]"
        />
        <div class="surface-panel space-y-4 p-6">
            <p class="text-sm text-muted-foreground">
                You have no active shift in this context. Open a shift inside
                POS, or select an authorized historical shift from shift
                history.
            </p>
            <div class="flex flex-wrap gap-2">
                <Button as-child
                    ><Link
                        :href="
                            pos({
                                query: { location_id: locationId || undefined },
                            })
                        "
                        >Open POS</Link
                    ></Button
                ><Button variant="outline" as-child
                    ><Link :href="shiftHistory()">Shift history</Link></Button
                >
            </div>
        </div>
    </section>
</template>
