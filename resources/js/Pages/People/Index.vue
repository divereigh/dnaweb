<script setup>
import { ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Pagination from '@/Components/App/Pagination.vue';

const props = defineProps({
    people: { type: Array, required: true },
    filters: { type: Object, required: true },
    sort: { type: String, required: true },
    page: { type: Number, required: true },
    pages: { type: Number, required: true },
    total: { type: Number, required: true },
    per_page: { type: Number, required: true },
});

const ONLY = ['people', 'page', 'pages', 'total', 'sort', 'filters'];

const q = ref(props.filters.q || '');
const linked = ref(!!props.filters.linked);
const hasMatches = ref(!!props.filters.has_matches);

let qTimer = null;

function reload(extra = {}) {
    router.reload({
        only: ONLY,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        data: {
            q: q.value || undefined,
            linked: linked.value ? 1 : undefined,
            has_matches: hasMatches.value ? 1 : undefined,
            sort: extra.sort ?? props.sort,
            page: extra.page ?? 1,
        },
    });
}

watch(q, () => {
    if (qTimer) clearTimeout(qTimer);
    qTimer = setTimeout(() => reload(), 280);
});
watch([linked, hasMatches], () => reload());

function setSort(col) {
    reload({ sort: col, page: 1 });
}

function isActive(col) {
    return props.sort === col;
}
function sortMark(col) {
    if (!isActive(col)) return '';
    if (col === 'eyes' || col === 'maxcm') return '↓';
    return '↑';
}
</script>

<template>
    <Head title="People" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                title="People"
                :subtitle="`${total.toLocaleString()} ${total === 1 ? 'person' : 'people'}`"
            />
        </template>

        <div class="filter-bar mb-4 sm:grid-cols-[minmax(0,1fr)_auto_auto]">
            <input
                v-model="q"
                type="search"
                placeholder="Search by name or kit name…"
                class="text-sm"
            />
            <label>
                <input v-model="linked" type="checkbox" />
                Linked to DNA
            </label>
            <label>
                <input v-model="hasMatches" type="checkbox" />
                Has matches
            </label>
        </div>

        <div class="card overflow-hidden">
            <table class="ref-table">
                <thead>
                    <tr>
                        <th
                            :class="['sortable', isActive('name') ? 'is-active' : '']"
                            @click="setSort('name')"
                        >
                            Name <span class="sort-mark">{{ sortMark('name') }}</span>
                        </th>
                        <th
                            :class="['sortable', isActive('dna') ? 'is-active' : '']"
                            @click="setSort('dna')"
                        >
                            DNA kit <span class="sort-mark">{{ sortMark('dna') }}</span>
                        </th>
                        <th
                            data-numeric
                            :class="['sortable', isActive('eyes') ? 'is-active' : '']"
                            @click="setSort('eyes')"
                        >
                            Eyes <span class="sort-mark">{{ sortMark('eyes') }}</span>
                        </th>
                        <th
                            data-numeric
                            :class="['sortable', isActive('maxcm') ? 'is-active' : '']"
                            @click="setSort('maxcm')"
                        >
                            Max cM <span class="sort-mark">{{ sortMark('maxcm') }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in people" :key="p.id">
                        <td>
                            <Link :href="route('people.show', p.id)" class="ref-link">
                                {{ p.display_label }}
                            </Link>
                        </td>
                        <td class="text-sepia-600">{{ p.dnaName || '—' }}</td>
                        <td class="num">
                            <span v-if="p.eye_count">{{ p.eye_count }}</span>
                            <span v-else class="text-sepia-400">—</span>
                        </td>
                        <td class="num">
                            <span v-if="p.max_cm">{{ p.max_cm }}</span>
                            <span v-else class="text-sepia-400">—</span>
                        </td>
                    </tr>
                    <tr v-if="!people.length">
                        <td colspan="4" class="empty-cell">No people match.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <Pagination :page="page" :pages="pages" :total="total" :only="ONLY" />
        </div>
    </AuthenticatedLayout>
</template>
