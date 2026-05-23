<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import SampleAvatar from '@/Components/App/SampleAvatar.vue';
import AncestryProfileButtons from '@/Components/App/AncestryProfileButtons.vue';

const props = defineProps({
    samples: { type: Array, required: true },
    q: { type: String, required: true },
    page: { type: Number, required: true },
    has_next: { type: Boolean, required: true },
});

const ONLY = ['samples', 'q', 'page', 'has_next'];
const term = ref(props.q || '');
const loading = ref(false);

function search() {
    router.reload({
        only: ONLY,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        data: { q: term.value.trim() || undefined, page: 1 },
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}

function go(page) {
    router.reload({
        only: ONLY,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        data: { q: term.value.trim() || undefined, page },
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
}
</script>

<template>
    <Head title="DNA search" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                title="DNA search"
                subtitle="Find any kit by display name or linked person."
            />
        </template>

        <form @submit.prevent="search" class="filter-bar mb-4 sm:grid-cols-[minmax(0,1fr)_auto]">
            <input
                v-model="term"
                type="search"
                placeholder="Type a name…"
                autofocus
                class="text-sm"
            />
            <button type="submit" class="btn-primary" :disabled="loading">
                {{ loading ? 'Searching…' : 'Search' }}
            </button>
        </form>

        <div
            v-if="loading"
            class="card flex items-center justify-center gap-2 py-12 text-sm text-sepia-500"
        >
            <svg class="h-4 w-4 animate-spin text-sepia-400" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" />
                <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
            </svg>
            Searching…
        </div>

        <div
            v-else-if="!q"
            class="card flex items-center justify-center px-5 py-12 text-sm text-sepia-500"
        >
            Type a name and click Search.
        </div>

        <div v-else class="card overflow-hidden">
            <table class="ref-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Person</th>
                        <th>Tested</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="s in samples" :key="s.id">
                        <td>
                            <div class="flex items-center gap-2">
                                <SampleAvatar
                                    :photo-url="s.photoUrl || ''"
                                    :alt="s.displayName || ''"
                                    :gender="s.effective_gender || ''"
                                />
                            <Link :href="route('dna.matches', s.id)" class="ref-link">
                                {{ s.displayName || `Sample #${s.id}` }}
                            </Link>
                            <span class="font-mono text-[11px] text-sepia-400"
                                >#{{ s.id }}</span
                            >
                            </div>
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
                            <img
                                v-if="s.managed"
                                src="/icon-eye.png"
                                alt="Eye"
                                title="Managed eye"
                                class="h-6 w-6"
                            />
                            <span v-else class="text-xs text-sepia-400">—</span>
                        </td>
                        <td class="!text-right">
                            <div class="inline-flex items-center gap-1">
                                <Link
                                    v-if="s.person_id"
                                    :href="route('people.show', s.person_id)"
                                    class="inline-flex items-center"
                                    :title="`Open ${s.person_name || 'person'} #${s.person_id}`"
                                >
                                    <img src="/icon-person.png" alt="" class="h-5 w-5" />
                                    <span class="sr-only">Open person</span>
                                </Link>
                                <AncestryProfileButtons
                                    :user-uuid="s.userUUID || ''"
                                    :admin-user-uuid="s.admin_userUUID || ''"
                                    :label="s.display_label"
                                    :admin-label="s.display_label"
                                />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!samples.length">
                        <td colspan="5" class="empty-cell">No matches.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="q && !loading"
            class="mt-4 flex items-center justify-between text-sm text-sepia-500"
        >
            <span>Page {{ page }}</span>
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
