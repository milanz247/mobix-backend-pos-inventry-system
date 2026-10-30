<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { index, show, movements, closing, report } from '@/routes/shifts';
import type { Auth } from '@/types';
import type { Shift } from '@/types/shift';
defineProps<{ shift: Shift; canManage: boolean }>();
const page = usePage<{ auth: Auth }>();
</script>
<template>
    <nav class="flex flex-wrap gap-2" aria-label="Shift">
        <Button variant="outline" as-child
            ><Link :href="index()">All shifts</Link></Button
        >
        <Button variant="outline" as-child
            ><Link :href="show(shift.id)">Overview</Link></Button
        >
        <Button variant="outline" as-child
            ><Link :href="movements(shift.id)">Cash movements</Link></Button
        >
        <Button
            v-if="
                canManage &&
                page.props.auth.permissions.includes('shifts.close')
            "
            as-child
            ><Link :href="closing(shift.id)">Close shift</Link></Button
        >
        <Button
            v-if="
                shift.closed_at &&
                page.props.auth.permissions.includes('shifts.print')
            "
            as-child
            ><Link :href="report(shift.id)">Closing report</Link></Button
        >
    </nav>
</template>
