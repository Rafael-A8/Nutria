<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard, login, register } from '@/routes';

withDefaults(
    defineProps<{
        canRegister: boolean;
    }>(),
    {
        canRegister: true,
    },
);
</script>

<template>
    <Head title="Nutria — Seu diário nutricional com IA" />

    <div
        class="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]"
    >
        <!-- Header -->
        <header
            class="flex w-full items-center justify-between px-6 py-4 lg:px-12"
        >
            <div class="flex items-center gap-2">
                <span class="text-xl">🥗</span>
                <span class="text-lg font-bold tracking-tight">Nutria</span>
            </div>
            <nav class="flex items-center gap-3">
                <Link
                    v-if="$page.props.auth.user"
                    :href="dashboard()"
                    class="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                >
                    Meu painel
                </Link>
                <template v-else>
                    <Link
                        :href="login()"
                        class="rounded-lg px-5 py-2 text-sm font-medium transition hover:bg-[#f5f5f4] dark:hover:bg-[#1a1a19]"
                    >
                        Entrar
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="register()"
                        class="rounded-lg bg-emerald-600 px-5 py-2 text-sm font-medium text-white transition hover:bg-emerald-700"
                    >
                        Criar conta
                    </Link>
                </template>
            </nav>
        </header>

        <!-- Hero -->
        <main class="flex flex-1 items-center px-6 lg:px-12">
            <div
                class="mx-auto grid w-full max-w-6xl gap-12 lg:grid-cols-2 lg:items-center lg:gap-16"
            >
                <!-- Lado esquerdo: Copy -->
                <div class="order-2 lg:order-1">
                    <span
                        class="mb-4 inline-block rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold tracking-wide text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-400"
                    >
                        Nutricionista virtual com IA
                    </span>

                    <h1
                        class="mb-5 text-4xl leading-tight font-extrabold tracking-tight lg:text-5xl"
                    >
                        Seu diário nutricional<br />
                        <span class="text-emerald-600 dark:text-emerald-400"
                            >inteligente</span
                        >
                    </h1>

                    <p
                        class="mb-4 max-w-lg text-lg leading-relaxed text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        Registre suas refeições de forma natural — por texto,
                        voz ou foto. A
                        <strong class="text-[#1b1b18] dark:text-[#EDEDEC]"
                            >Nutri</strong
                        >, sua assistente com IA, estima calorias, acompanha seu
                        progresso e dá dicas personalizadas.
                    </p>

                    <ul
                        class="mb-8 space-y-2.5 text-sm text-[#706f6c] dark:text-[#A1A09A]"
                    >
                        <li class="flex items-center gap-2.5">
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400"
                                >✓</span
                            >
                            Registre refeições por texto, áudio ou foto
                        </li>
                        <li class="flex items-center gap-2.5">
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400"
                                >✓</span
                            >
                            Estimativa automática de calorias e macros
                        </li>
                        <li class="flex items-center gap-2.5">
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400"
                                >✓</span
                            >
                            Acompanhamento diário com dicas da Nutri
                        </li>
                        <li class="flex items-center gap-2.5">
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 dark:bg-emerald-900/40 dark:text-emerald-400"
                                >✓</span
                            >
                            100% gratuito e open source
                        </li>
                    </ul>

                    <div class="flex flex-wrap gap-3">
                        <Link
                            v-if="!$page.props.auth.user && canRegister"
                            :href="register()"
                            class="rounded-lg bg-emerald-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                        >
                            Começar agora — é grátis
                        </Link>
                        <Link
                            v-if="!$page.props.auth.user"
                            :href="login()"
                            class="rounded-lg border border-[#e3e3e0] px-6 py-2.5 text-sm font-semibold transition hover:bg-[#f5f5f4] dark:border-[#3E3E3A] dark:hover:bg-[#1a1a19]"
                        >
                            Já tenho conta
                        </Link>
                        <Link
                            v-if="$page.props.auth.user"
                            :href="dashboard()"
                            class="rounded-lg bg-emerald-600 px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700"
                        >
                            Ir para o painel
                        </Link>
                    </div>
                </div>

                <!-- Lado direito: Ilustração -->
                <div
                    class="order-1 flex items-center justify-center lg:order-2"
                >
                    <div class="relative w-full max-w-md">
                        <!-- Fundo decorativo -->
                        <div
                            class="absolute -inset-4 rounded-3xl bg-gradient-to-br from-emerald-100 to-emerald-50 opacity-60 blur-2xl dark:from-emerald-900/20 dark:to-emerald-800/10"
                        ></div>

                        <!-- Card mockup do chat -->
                        <div
                            class="relative rounded-2xl border border-[#e3e3e0] bg-white p-5 shadow-xl dark:border-[#3E3E3A] dark:bg-[#161615]"
                        >
                            <!-- Header do chat -->
                            <div
                                class="mb-4 flex items-center gap-2.5 border-b border-[#e3e3e0] pb-3 dark:border-[#3E3E3A]"
                            >
                                <div
                                    class="flex size-8 items-center justify-center rounded-full bg-emerald-600 text-xs font-bold text-white"
                                >
                                    N
                                </div>
                                <div>
                                    <p class="text-sm font-semibold">Nutri</p>
                                    <p class="text-xs text-[#A1A09A]">
                                        Sua nutricionista virtual
                                    </p>
                                </div>
                            </div>

                            <!-- Mensagens -->
                            <div class="space-y-3">
                                <div class="flex gap-2">
                                    <div
                                        class="flex size-6 shrink-0 items-center justify-center self-end rounded-full bg-emerald-600 text-[10px] font-bold text-white"
                                    >
                                        N
                                    </div>
                                    <div
                                        class="rounded-2xl rounded-bl-sm bg-[#f5f5f4] px-3.5 py-2 text-sm dark:bg-[#1a1a19]"
                                    >
                                        Olá! 💚 Me conta o que você comeu hoje?
                                    </div>
                                </div>

                                <div class="flex justify-end">
                                    <div
                                        class="rounded-2xl rounded-br-sm bg-emerald-600 px-3.5 py-2 text-sm text-white"
                                    >
                                        Almocei arroz, feijão e frango grelhado
                                    </div>
                                </div>

                                <div class="flex gap-2">
                                    <div
                                        class="flex size-6 shrink-0 items-center justify-center self-end rounded-full bg-emerald-600 text-[10px] font-bold text-white"
                                    >
                                        N
                                    </div>
                                    <div
                                        class="rounded-2xl rounded-bl-sm bg-[#f5f5f4] px-3.5 py-2 text-sm dark:bg-[#1a1a19]"
                                    >
                                        <p>Registrado! Estimativa:</p>
                                        <p
                                            class="mt-1 text-xs text-[#706f6c] dark:text-[#A1A09A]"
                                        >
                                            Arroz branco — 200g —
                                            <strong>260 kcal</strong><br />
                                            Feijão carioca — 100g —
                                            <strong>77 kcal</strong><br />
                                            Frango grelhado — 150g —
                                            <strong>248 kcal</strong>
                                        </p>
                                        <p
                                            class="mt-1.5 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                                        >
                                            Total: 585 kcal (32% da meta)
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Input mockup -->
                            <div
                                class="mt-4 flex items-center gap-2 rounded-xl border border-[#e3e3e0] px-3 py-2 dark:border-[#3E3E3A]"
                            >
                                <span class="text-xs text-[#A1A09A]"
                                    >O que você comeu hoje?</span
                                >
                                <div
                                    class="ml-auto flex gap-1.5 text-[#A1A09A]"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="size-4"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    >
                                        <path
                                            d="M15 2H9a1 1 0 0 0-1 1v2c0 .6.4 1 1 1h6c.6 0 1-.4 1-1V3c0-.6-.4-1-1-1Z"
                                        />
                                        <path d="M20 16v-6a8 8 0 1 0-16 0v6" />
                                        <path d="M2 16h20" />
                                    </svg>
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="size-4"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    >
                                        <path
                                            d="M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z"
                                        />
                                        <path d="M19 10v2a7 7 0 0 1-14 0v-2" />
                                        <line x1="12" x2="12" y1="19" y2="22" />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <footer class="px-6 py-4 text-center text-xs text-[#A1A09A] lg:px-12">
            Nutria &copy; {{ new Date().getFullYear() }} — Open source com 💚
        </footer>
    </div>
</template>
