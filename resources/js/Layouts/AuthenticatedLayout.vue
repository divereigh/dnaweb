<script setup>
import { ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import { Link } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);

function isActive(...patterns) {
    return patterns.some((p) => route().current(p));
}
</script>

<template>
    <div class="min-h-screen">
        <header class="border-b border-paper-300 bg-paper-50/85 backdrop-blur-[2px]">
            <!-- Masthead -->
            <div class="mx-auto max-w-7xl px-6 sm:px-8">
                <div
                    class="flex flex-col gap-1 pb-2 pt-5 sm:flex-row sm:items-end sm:justify-between"
                >
                    <Link :href="route('eyes.index')" class="group block">
                        <div class="eyebrow text-sepia-500">A genealogy & DNA register</div>
                        <h1
                            class="font-display text-3xl font-medium leading-none tracking-tight text-ink-600 sm:text-4xl"
                        >
                            DNAWeb
                            <span
                                class="ms-1 align-baseline font-display text-base italic text-sepia-500"
                                >est. 2024</span
                            >
                        </h1>
                    </Link>

                    <!-- User menu -->
                    <div class="hidden sm:block">
                        <Dropdown align="right" width="48">
                            <template #trigger>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-2 rounded-sm border border-paper-300 bg-paper-50 px-3 py-1.5 font-sans text-xs font-semibold tracking-eyebrow text-sepia-500 uppercase transition hover:border-sepia-400 hover:text-ink-500"
                                >
                                    <span aria-hidden="true">§</span>
                                    {{ $page.props.auth.user.name }}
                                    <svg
                                        class="h-3 w-3"
                                        viewBox="0 0 20 20"
                                        fill="currentColor"
                                    >
                                        <path
                                            fill-rule="evenodd"
                                            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                            clip-rule="evenodd"
                                        />
                                    </svg>
                                </button>
                            </template>
                            <template #content>
                                <DropdownLink :href="route('profile.edit')">
                                    Profile
                                </DropdownLink>
                                <DropdownLink
                                    :href="route('logout')"
                                    method="post"
                                    as="button"
                                >
                                    Log out
                                </DropdownLink>
                            </template>
                        </Dropdown>
                    </div>

                    <!-- Mobile menu trigger -->
                    <button
                        @click="showingNavigationDropdown = !showingNavigationDropdown"
                        class="absolute right-4 top-4 inline-flex items-center justify-center rounded p-2 text-sepia-500 hover:bg-paper-100 sm:hidden"
                    >
                        <svg
                            class="h-5 w-5"
                            stroke="currentColor"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <path
                                v-if="!showingNavigationDropdown"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M4 6h16M4 12h16M4 18h16"
                            />
                            <path
                                v-else
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M6 18L18 6M6 6l12 12"
                            />
                        </svg>
                    </button>
                </div>

                <!-- Decorative double rule -->
                <div class="rule-double mt-2"></div>

                <!-- Section nav -->
                <nav class="hidden flex-wrap items-center gap-7 sm:flex">
                    <Link
                        :href="route('eyes.index')"
                        :class="['nav-link', isActive('eyes.*', 'common.*') ? 'is-active' : '']"
                    >
                        I · Eyes
                    </Link>
                    <Link
                        :href="route('people.index')"
                        :class="['nav-link', isActive('people.*') ? 'is-active' : '']"
                    >
                        II · People
                    </Link>
                    <Link
                        :href="route('dna.index')"
                        :class="['nav-link', isActive('dna.*') ? 'is-active' : '']"
                    >
                        III · DNA
                    </Link>
                </nav>
            </div>

            <!-- Mobile nav -->
            <div
                v-if="showingNavigationDropdown"
                class="border-t border-paper-300 bg-paper-50/95 px-6 py-3 sm:hidden"
            >
                <Link :href="route('eyes.index')" class="nav-link block">
                    I · Eyes
                </Link>
                <Link :href="route('people.index')" class="nav-link block">
                    II · People
                </Link>
                <Link :href="route('dna.index')" class="nav-link block">
                    III · DNA
                </Link>
                <div class="mt-3 border-t border-paper-300 pt-3">
                    <div class="text-sm font-medium text-ink-500">
                        {{ $page.props.auth.user.name }}
                    </div>
                    <div class="text-xs text-sepia-500">
                        {{ $page.props.auth.user.email }}
                    </div>
                    <div class="mt-2 flex gap-3">
                        <Link :href="route('profile.edit')" class="nav-link"
                            >Profile</Link
                        >
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="nav-link"
                            >Log out</Link
                        >
                    </div>
                </div>
            </div>
        </header>

        <!-- Page heading slot -->
        <header v-if="$slots.header" class="border-b border-paper-300 bg-paper-100/30">
            <div class="mx-auto max-w-7xl px-6 py-7 sm:px-8">
                <slot name="header" />
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-6 py-8 sm:px-8">
            <slot />
        </main>

        <footer class="mx-auto max-w-7xl px-6 pb-10 pt-6 sm:px-8">
            <div class="flex items-center justify-between border-t border-paper-300 pt-4">
                <p
                    class="font-display text-xs italic tracking-wide text-sepia-500"
                >
                    DNAWeb · a private register of kin & kit
                </p>
                <p class="font-mono text-[10px] uppercase tracking-eyebrow text-sepia-400">
                    folio · vol. i
                </p>
            </div>
        </footer>
    </div>
</template>
