<script setup>
import { ref, watch } from 'vue';
import { router, Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Pagination from '@/Components/App/Pagination.vue';
import ClusterPill from '@/Components/App/ClusterPill.vue';

const props = defineProps({
    eye: { type: Object, required: true },
    matches: { type: Array, required: true },
    clusters: { type: Array, required: true },
    filters: { type: Object, required: true },
    sort: { type: String, required: true },
    dir: { type: String, required: true },
    page: { type: Number, required: true },
    per_page: { type: Number, required: true },
    total: { type: Number, required: true },
    pages: { type: Number, required: true },
    allowed_per_page: { type: Array, required: true },
});

const ONLY = ['matches', 'total', 'pages', 'page', 'sort', 'dir', 'per_page', 'filters', 'clusters'];

const q = ref(props.filters.q || '');
const hasNotes = ref(!!props.filters.has_notes);
const hideIgnored = ref(!!props.filters.hide_ignored);
const onlyEyes = ref(!!props.filters.only_eyes);
const cluster = ref(props.filters.cluster || '');

let qTimer = null;

function reload(extra = {}) {
    router.reload({
        only: ONLY,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        data: {
            q: q.value || undefined,
            has_notes: hasNotes.value ? 1 : undefined,
            hide_ignored: hideIgnored.value ? 1 : undefined,
            only_eyes: onlyEyes.value ? 1 : undefined,
            cluster: cluster.value || undefined,
            sort: extra.sort ?? props.sort,
            dir: extra.dir ?? props.dir,
            per_page: props.per_page,
            page: extra.page ?? 1,
        },
    });
}

watch(q, () => {
    if (qTimer) clearTimeout(qTimer);
    qTimer = setTimeout(() => reload(), 280);
});

watch([hasNotes, hideIgnored, onlyEyes, cluster], () => reload());

function setSort(col) {
    let dir = 'desc';
    if (props.sort === col) dir = props.dir === 'asc' ? 'desc' : 'asc';
    else if (col === 'name' || col === 'cluster') dir = 'asc';
    reload({ sort: col, dir, page: props.page });
}

function sortMark(col) {
    if (props.sort !== col) return '';
    return props.dir === 'asc' ? '↑' : '↓';
}

function isActive(col) {
    return props.sort === col;
}
</script>

<template>
    <Head :title="`Matches · ${eye.display_label}`" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                compact
                :title="eye.display_label"
                :eyebrow="`Eye #${eye.id}`"
                :subtitle="`${total.toLocaleString()} ${total === 1 ? 'match' : 'matches'}`"
            >
                <template #actions>
                    <Link :href="route('eyes.index')" class="btn-ghost">← All eyes</Link>
                </template>
            </PageHeader>
        </template>

        <!-- Filter bar -->
        <div
            class="filter-bar mb-4 sm:grid-cols-[minmax(0,1fr)_auto_auto_auto_auto]"
        >
            <input
                v-model="q"
                type="search"
                placeholder="Search by name…"
                class="text-sm"
            />
            <select v-model="cluster" class="text-sm">
                <option value="">All clusters</option>
                <option v-for="c in clusters" :key="c" :value="c">{{ c }}</option>
            </select>
            <label>
                <input v-model="hasNotes" type="checkbox" />
                Has notes
            </label>
            <label>
                <input v-model="hideIgnored" type="checkbox" />
                Hide ignored
            </label>
            <label>
                <input v-model="onlyEyes" type="checkbox" />
                Only managed
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
                            data-numeric
                            :class="['sortable', isActive('cm') ? 'is-active' : '']"
                            @click="setSort('cm')"
                        >
                            cM <span class="sort-mark">{{ sortMark('cm') }}</span>
                        </th>
                        <th
                            data-numeric
                            :class="['sortable', isActive('segments') ? 'is-active' : '']"
                            @click="setSort('segments')"
                        >
                            Segs <span class="sort-mark">{{ sortMark('segments') }}</span>
                        </th>
                        <th
                            data-numeric
                            :class="['sortable', isActive('meiosis') ? 'is-active' : '']"
                            @click="setSort('meiosis')"
                        >
                            Meiosis <span class="sort-mark">{{ sortMark('meiosis') }}</span>
                        </th>
                        <th
                            :class="['sortable', isActive('cluster') ? 'is-active' : '']"
                            @click="setSort('cluster')"
                        >
                            Cluster <span class="sort-mark">{{ sortMark('cluster') }}</span>
                        </th>
                        <th
                            :class="['sortable', isActive('created') ? 'is-active' : '']"
                            @click="setSort('created')"
                        >
                            Tested <span class="sort-mark">{{ sortMark('created') }}</span>
                        </th>
                        <th>Notes</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="m in matches"
                        :key="m.other_id"
                        :class="m.ignored ? 'opacity-50' : ''"
                    >
                        <td>
                            <Link
                                v-if="m.person_id"
                                :href="route('people.show', m.person_id)"
                                class="ref-link"
                                :class="m.ignored ? 'line-through decoration-sepia-400/60' : ''"
                            >
                                {{ m.display_label }}
                            </Link>
                            <span
                                v-else
                                class="font-medium text-ink-500"
                                :class="m.ignored ? 'line-through decoration-sepia-400/60' : ''"
                            >
                                {{ m.display_label }}
                            </span>
                            <span
                                v-if="m.other_managed"
                                class="ms-2 inline-flex items-center rounded bg-marine-500/10 px-1.5 py-0.5 text-[10px] font-medium text-marine-500"
                            >
                                Managed
                            </span>
                        </td>
                        <td class="num">{{ m.sharedCentimorgans }}</td>
                        <td class="num">{{ m.numSharedSegments }}</td>
                        <td class="num">{{ m.meiosis }}</td>
                        <td>
                            <ClusterPill
                                :code="m.matchClusterCode || ''"
                                :cls="m.cluster_class || ''"
                            />
                        </td>
                        <td class="ident">{{ m.created_fmt }}</td>
                        <td class="max-w-xs truncate text-sepia-600" :title="m.notes">
                            {{ m.notes || '' }}
                        </td>
                        <td class="!text-right">
                            <Link
                                :href="route('common.index', [eye.id, m.other_id])"
                                class="text-xs text-sepia-500 hover:text-wine-500"
                            >
                                common ›
                            </Link>
                        </td>
                    </tr>
                    <tr v-if="!matches.length">
                        <td colspan="8" class="empty-cell">No matches.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <Pagination
                :page="page"
                :pages="pages"
                :total="total"
                :per-page="per_page"
                :per-page-options="allowed_per_page"
                :only="ONLY"
            />
        </div>
    </AuthenticatedLayout>
</template>
