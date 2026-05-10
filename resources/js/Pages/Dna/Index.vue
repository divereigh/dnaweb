<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';

const props = defineProps({
    samples: { type: Array, required: true },
    q: { type: String, required: true },
    page: { type: Number, required: true },
    has_next: { type: Boolean, required: true },
});

const ONLY = ['samples', 'q', 'page', 'has_next'];
const term = ref(props.q || '');
let timer = null;

watch(term, (val) => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => {
        router.reload({
            only: ONLY,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            data: { q: val || undefined, page: 1 },
        });
    }, 280);
});

function go(page) {
    router.reload({
        only: ONLY,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        data: { q: term.value || undefined, page },
    });
}
</script>

<template>
    <Head title="DNA search" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                title="DNA Search"
                eyebrow="Section III — kit lookup"
                subtitle="Find any kit by display name or by its linked person."
            />
        </template>

        <div class="card mb-5 p-4">
            <input
                v-model="term"
                type="search"
                placeholder="Type a name…"
                autofocus
                class="w-full text-base"
            />
        </div>

        <div
            v-if="!q"
            class="card flex items-center justify-center gap-3 px-5 py-12 text-center"
        >
            <svg
                aria-hidden="true"
                viewBox="0 0 24 24"
                class="h-6 w-6 text-sepia-400"
                fill="none"
                stroke="currentColor"
                stroke-width="1.5"
            >
                <circle cx="11" cy="11" r="6" />
                <path stroke-linecap="round" d="m21 21-4.5-4.5" />
            </svg>
            <p class="font-display text-lg italic text-sepia-500">
                Begin with a name to consult the register.
            </p>
        </div>

        <div v-else class="card overflow-hidden">
            <table class="ref-table">
                <thead>
                    <tr>
                        <th>Display name</th>
                        <th>Linked person</th>
                        <th>Tested</th>
                        <th>Status</th>
                        <th>DNA UUID</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in samples" :key="s.id">
                        <td>
                            <Link :href="route('dna.matches', s.id)" class="ref-link">
                                {{ s.displayName || `Sample №${s.id}` }}
                            </Link>
                            <span class="ms-2 font-mono text-[10px] text-sepia-400"
                                >№{{ s.id }}</span
                            >
                        </td>
                        <td>
                            <Link
                                v-if="s.person_id"
                                :href="route('people.show', s.person_id)"
                                class="ref-link"
                            >
                                {{ s.person_name }}
                            </Link>
                            <span v-else class="text-sepia-400">—</span>
                        </td>
                        <td class="ident">{{ s.created_fmt }}</td>
                        <td>
                            <span
                                v-if="s.managed"
                                class="font-sans text-[10px] uppercase tracking-eyebrow text-marine-500"
                                >◆ Managed</span
                            >
                            <span v-else class="font-sans text-[10px] uppercase tracking-eyebrow text-sepia-400"
                                >· match</span
                            >
                        </td>
                        <td class="ident">{{ s.dnaUUID }}</td>
                    </tr>
                    <tr v-if="!samples.length">
                        <td colspan="5" class="empty-cell">
                            No kits answer to that name.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="q"
            class="mt-5 flex items-center justify-between font-sans text-xs uppercase tracking-eyebrow text-sepia-500"
        >
            <span>Folio {{ page }}</span>
            <div class="flex items-center gap-2">
                <button
                    @click="go(page - 1)"
                    :disabled="page <= 1"
                    class="btn-ghost disabled:opacity-30"
                >
                    ← Prev
                </button>
                <button
                    @click="go(page + 1)"
                    :disabled="!has_next"
                    class="btn-ghost disabled:opacity-30"
                >
                    Next →
                </button>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
