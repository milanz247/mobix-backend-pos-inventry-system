<script setup lang="ts">
import type { HTMLAttributes } from "vue"
import { cn } from "@/lib/utils"

const props = defineProps<{
  class?: HTMLAttributes["class"]
}>()
</script>

<template>
  <main
    data-slot="sidebar-inset"
    :class="cn(
      'bg-background relative flex w-full flex-1 flex-col overflow-hidden',
      'md:peer-data-[variant=inset]:m-2 md:peer-data-[variant=inset]:ml-0 md:peer-data-[variant=inset]:rounded-2xl md:peer-data-[variant=inset]:border md:peer-data-[variant=inset]:border-border/70 md:peer-data-[variant=inset]:shadow-sm md:peer-data-[variant=inset]:shadow-slate-950/5 md:peer-data-[variant=inset]:peer-data-[state=collapsed]:ml-2 dark:md:peer-data-[variant=inset]:shadow-black/25',
      props.class,
    )"
  >
    <slot />
  </main>
</template>
