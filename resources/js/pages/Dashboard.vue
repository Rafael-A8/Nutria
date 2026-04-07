<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { LayoutGrid } from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';
import { index as chatIndex } from '@/routes/chat';
import AppLayout from '@/layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-col items-center justify-center gap-6 p-8">
            <div class="flex size-16 items-center justify-center rounded-full bg-primary/10">
                <LayoutGrid class="size-8 text-primary" />
            </div>
            <div class="text-center">
                <h1 class="text-2xl font-semibold">Bem-vindo ao Nutria</h1>
                <p class="mt-2 text-muted-foreground">Seu diário nutricional inteligente com IA.</p>
            </div>
            <Link :href="chatIndex()">
                <Button size="lg" class="rounded-full">Abrir Chat Nutricional</Button>
            </Link>
        </div>
    </AppLayout>
</template>
