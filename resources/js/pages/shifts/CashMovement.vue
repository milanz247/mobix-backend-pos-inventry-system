<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { show, movement as movementDetail } from '@/routes/shifts';
import { shiftDate, shiftMoney } from '@/lib/shift';
import type { Movement, Shift } from '@/types/shift';
type Detail = Movement & {
    updated_at: string;
    reversal: Movement | null;
    original: Movement | null;
};
defineProps<{
    shift: Shift;
    movement: Detail;
    currency: string;
    timezone: string;
    events: {
        id: number;
        type: string;
        created_at: string;
        actor: { name: string };
    }[];
}>();
</script>
<template>
    <section class="page-shell max-w-5xl space-y-5">
        <Head title="Cash movement details" /><Heading
            :title="movement.reference"
            :description="
                movement.type === 'expense'
                    ? 'Cash expense details'
                    : 'Cash movement details'
            "
        />
        <Button variant="outline" as-child
            ><Link
                :href="
                    show(shift.id, {
                        query: {
                            section:
                                movement.type === 'expense'
                                    ? 'expenses'
                                    : movement.type === 'drop'
                                      ? 'drops'
                                      : 'cash_movements',
                        },
                    })
                "
                >Linked shift report</Link
            ></Button
        >
        <dl class="surface-panel grid gap-5 p-5 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-muted-foreground">Amount</dt>
                <dd class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ shiftMoney(movement.amount, currency) }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Status</dt>
                <dd class="mt-1 font-semibold">
                    {{
                        movement.reverses_id
                            ? 'Reversal'
                            : movement.reversal
                              ? 'Reversed'
                              : 'Completed'
                    }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Location</dt>
                <dd>{{ shift.location.name }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Shift / cashier</dt>
                <dd>{{ shift.reference }} · {{ shift.cashier.name }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Payment source</dt>
                <dd>Cash drawer</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Category</dt>
                <dd>Not categorized</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Created by</dt>
                <dd>{{ movement.creator.name }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Created</dt>
                <dd>{{ shiftDate(movement.created_at, timezone) }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Last stored timestamp</dt>
                <dd>{{ shiftDate(movement.updated_at, timezone) }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-muted-foreground">Reason / description</dt>
                <dd class="mt-1 whitespace-pre-wrap">{{ movement.reason }}</dd>
            </div>
            <div class="sm:col-span-2">
                <dt class="text-muted-foreground">
                    Reference / supporting note
                </dt>
                <dd class="mt-1 whitespace-pre-wrap">
                    {{ movement.supporting_reference ?? '—' }}
                </dd>
            </div>
        </dl>
        <section
            v-if="movement.reversal || movement.original"
            class="surface-panel space-y-3 p-5"
        >
            <h2 class="font-semibold">Linked reversal / original record</h2>
            <template
                v-for="linked in [movement.reversal, movement.original]"
                :key="linked?.id"
                ><div v-if="linked" class="border-t pt-3 text-sm">
                    <Link
                        :href="
                            movementDetail({
                                shift: shift.id,
                                movement: linked.id,
                            })
                        "
                        class="font-semibold text-primary hover:underline"
                        >{{ linked.reference }}</Link
                    >
                    <p class="mt-1">
                        {{ linked.reason }} ·
                        {{ shiftMoney(linked.amount, currency) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ linked.creator.name }} ·
                        {{ shiftDate(linked.created_at, timezone) }}
                    </p>
                </div></template
            >
        </section>
        <section class="surface-panel p-5">
            <h2 class="font-semibold">Audit trail</h2>
            <ol class="mt-3 divide-y">
                <li
                    v-for="event in events"
                    :key="event.id"
                    class="py-3 text-sm"
                >
                    <span class="capitalize">{{
                        event.type.replaceAll('_', ' ')
                    }}</span>
                    · {{ event.actor.name
                    }}<span class="block text-xs text-muted-foreground">{{
                        shiftDate(event.created_at, timezone)
                    }}</span>
                </li>
            </ol>
        </section>
    </section>
</template>
