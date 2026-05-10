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
        <header class="border-b border-paper-300 bg-paper-50">
            <div class="mx-auto flex h-14 max-w-7xl items-center px-6 sm:px-8">
                <Link
                    :href="route('eyes.index')"
                    class="text-[15px] font-semibold tracking-tight text-ink-600"
                >
                    DNAWeb
                </Link>

                <nav class="ms-8 hidden gap-6 sm:flex">
                    <Link
                        :href="route('eyes.index')"
                        :class="['nav-link', isActive('eyes.*', 'common.*') ? 'is-active' : '']"
                    >
                        Eyes
                    </Link>
                    <Link
                        :href="route('people.index')"
                        :class="['nav-link', isActive('people.*') ? 'is-active' : '']"
                    >
                        People
                    </Link>
                    <Link
                        :href="route('dna.index')"
                        :class="['nav-link', isActive('dna.*') ? 'is-active' : '']"
                    >
                        DNA
                    </Link>
                </nav>

                <div class="ms-auto hidden sm:block">
                    <Dropdown align="right" width="48">
                        <template #trigger>
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-md px-2 py-1.5 text-sm text-ink-300 transition hover:bg-paper-100 hover:text-ink-500"
                            >
                                {{ $page.props.auth.user.name }}
                                <svg
                                    class="h-3.5 w-3.5 text-sepia-400"
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
                            <DropdownLink :href="route('profile.edit')">Profile</DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">
                                Log out
                            </DropdownLink>
                        </template>
                    </Dropdown>
                </div>

                <button
                    @click="showingNavigationDropdown = !showingNavigationDropdown"
                    class="ms-auto inline-flex items-center justify-center rounded-md p-2 text-sepia-500 hover:bg-paper-100 sm:hidden"
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

            <!-- Mobile nav -->
            <div
                v-if="showingNavigationDropdown"
                class="border-t border-paper-300 bg-paper-50 px-6 py-3 sm:hidden"
            >
                <Link :href="route('eyes.index')" class="nav-link block">Eyes</Link>
                <Link :href="route('people.index')" class="nav-link block">People</Link>
                <Link :href="route('dna.index')" class="nav-link block">DNA</Link>
                <div class="mt-3 border-t border-paper-300 pt-3">
                    <div class="text-sm font-medium text-ink-500">
                        {{ $page.props.auth.user.name }}
                    </div>
                    <div class="text-xs text-sepia-500">{{ $page.props.auth.user.email }}</div>
                    <div class="mt-2 flex gap-3">
                        <Link :href="route('profile.edit')" class="nav-link">Profile</Link>
                        <Link :href="route('logout')" method="post" as="button" class="nav-link">
                            Log out
                        </Link>
                    </div>
                </div>
            </div>
        </header>

        <header v-if="$slots.header" class="border-b border-paper-300 bg-paper-50">
            <div class="mx-auto max-w-7xl px-6 py-5 sm:px-8">
                <slot name="header" />
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-6 py-6 sm:px-8">
            <slot />
        </main>
    </div>
</template>
