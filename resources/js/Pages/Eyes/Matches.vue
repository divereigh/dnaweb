<script setup>
import { ref, watch } from 'vue';
import { router, Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Pagination from '@/Components/App/Pagination.vue';
import ClusterPill from '@/Components/App/ClusterPill.vue';
import SampleAvatar from '@/Components/App/SampleAvatar.vue';
import AncestryProfileButtons from '@/Components/App/AncestryProfileButtons.vue';
import PersonEditDialog from '@/Components/App/PersonEditDialog.vue';

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

const editing = ref(null);

function openEdit(m) {
    editing.value = {
        sampleId: m.other_id,
        personId: m.person_id || null,
        prefill: m.person_id
            ? {
                  fullName: m.person_name || '',
                  minBirth: m.person_minBirth,
                  maxBirth: m.person_maxBirth,
                  death: m.person_death,
                  gender: m.person_gender,
              }
            : {
                  fullName: m.other_name || '',
                  minBirth: null,
                  maxBirth: null,
                  death: null,
                  gender: m.other_gender || null,
              },
    };
}

function openSampleEdit(s) {
    editing.value = {
        sampleId: s.id,
        personId: s.person_id || null,
        prefill: s.person_id
            ? {
                  fullName: s.person_name || '',
                  minBirth: s.person_minBirth,
                  maxBirth: s.person_maxBirth,
                  death: s.person_death,
                  gender: s.person_gender,
              }
            : {
                  fullName: s.displayName || '',
                  minBirth: null,
                  maxBirth: null,
                  death: null,
                  gender: s.gender || null,
              },
    };
}

function closeEdit() {
    editing.value = null;
}

function ancestryCompareUrl(otherUuid) {
    if (!props.eye?.dnaUUID || !otherUuid) return null;
    const eyeUuid = String(props.eye.dnaUUID).toUpperCase();
    const other = String(otherUuid).toUpperCase();
    return `https://www.ancestry.com.au/discoveryui-matches/compare/${eyeUuid}/with/${other}/matchesofmatches`;
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
            >
                <template #subtitle>
                    <Link
                        v-if="eye.person_id"
                        :href="route('people.show', eye.person_id)"
                        class="inline-flex items-center"
                        :title="`Open ${eye.person_name || 'person'} #${eye.person_id}`"
                    >
                        <img src="/icon-person.png" alt="" class="h-5 w-5" />
                        <span class="sr-only">Open person</span>
                    </Link>
                    <AncestryProfileButtons
                        :user-uuid="eye.userUUID || ''"
                        :admin-user-uuid="eye.admin_userUUID || ''"
                        :label="eye.display_label"
                        :admin-label="eye.display_label"
                    />
                    <span>{{ total.toLocaleString() }} {{ total === 1 ? 'match' : 'matches' }}</span>
                </template>
                <template #titleBefore>
                    <SampleAvatar
                        :photo-url="eye.photoUrl || ''"
                        :alt="eye.display_label"
                        :gender="eye.effective_gender || ''"
                        size="md"
                    />
                </template>
                <template #titleAfter>
                    <button
                        type="button"
                        class="inline-flex items-center rounded p-0.5 text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                        :title="eye.person_id ? 'Edit person' : 'Create person'"
                        @click="openSampleEdit(eye)"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            viewBox="0 0 20 20"
                            fill="currentColor"
                            class="h-4 w-4"
                            aria-hidden="true"
                        >
                            <path d="M13.586 3.586a2 2 0 1 1 2.828 2.828l-.793.793-2.828-2.828.793-.793zM12.379 4.793 3 14.172V17h2.828l9.379-9.379-2.828-2.828z" />
                        </svg>
                        <span class="sr-only">{{ eye.person_id ? 'Edit person' : 'Create person' }}</span>
                    </button>
                </template>
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

        <div class="mb-4">
            <Pagination
                :page="page"
                :pages="pages"
                :total="total"
                :per-page="per_page"
                :per-page-options="allowed_per_page"
                :only="ONLY"
            />
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
                        <th>Predicted</th>
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
                            <div class="flex items-center gap-2">
                                <SampleAvatar
                                    :photo-url="m.other_photoUrl || ''"
                                    :alt="m.display_label"
                                    :gender="m.effective_gender || ''"
                                />
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
                            <button
                                type="button"
                                class="ms-1 inline-flex items-center rounded p-0.5 align-middle text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                                :title="m.person_id ? 'Edit person' : 'Create person'"
                                @click="openEdit(m)"
                            >
                                <svg
                                    xmlns="http://www.w3.org/2000/svg"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    class="h-3.5 w-3.5"
                                    aria-hidden="true"
                                >
                                    <path d="M13.586 3.586a2 2 0 1 1 2.828 2.828l-.793.793-2.828-2.828.793-.793zM12.379 4.793 3 14.172V17h2.828l9.379-9.379-2.828-2.828z" />
                                </svg>
                                <span class="sr-only">{{ m.person_id ? 'Edit' : 'Create' }}</span>
                            </button>
                            <img
                                v-if="m.other_managed"
                                src="/icon-eye.png"
                                alt="Eye"
                                title="Managed eye"
                                class="ms-2 h-4 w-4"
                            />
                            </div>
                        </td>
                        <td class="text-sm text-sepia-700">
                            {{ (m.kinships || []).join(' / ') }}
                        </td>
                        <td class="num">{{ m.sharedCentimorgans }}</td>
                        <td class="num">{{ m.numSharedSegments }}</td>
                        <td class="num">{{ m.meiosis }}</td>
                        <td>
                            <ClusterPill
                                :code="m.matchClusterCode || ''"
                                :paternal-cluster="eye.paternalCluster || ''"
                            />
                        </td>
                        <td class="max-w-xs truncate text-sepia-600" :title="m.notes">
                            {{ m.notes || '' }}
                        </td>
                        <td class="!text-right">
                            <div class="inline-flex items-center gap-2">
                                <Link
                                    v-if="m.person_id"
                                    :href="route('people.show', m.person_id)"
                                    class="inline-flex items-center"
                                    :title="`Open ${m.person_name || 'person'} #${m.person_id}`"
                                >
                                    <img src="/icon-person.png" alt="" class="h-5 w-5" />
                                    <span class="sr-only">Open person</span>
                                </Link>
                                <a
                                    v-if="m.other_uuid"
                                    :href="ancestryCompareUrl(m.other_uuid)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
                                    :title="`Compare on Ancestry: ${eye.display_label} ↔ ${m.display_label}`"
                                >
                                    <img
                                        src="/ancestry-icon.svg"
                                        alt=""
                                        class="h-3.5 w-3.5"
                                    />
                                    DNA
                                </a>
                                <Link
                                    :href="route('common.index', [eye.id, m.other_id])"
                                    class="text-xs text-sepia-500 hover:text-wine-500"
                                >
                                    common ›
                                </Link>
                                <AncestryProfileButtons
                                    :user-uuid="m.other_userUUID || ''"
                                    :admin-user-uuid="m.other_admin_userUUID || ''"
                                    :label="m.display_label"
                                    :admin-label="m.display_label"
                                />
                            </div>
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

        <PersonEditDialog
            :show="!!editing"
            :sample-id="editing?.sampleId ?? 0"
            :person-id="editing?.personId ?? null"
            :prefill="editing?.prefill ?? {}"
            @close="closeEdit"
        />
    </AuthenticatedLayout>
</template>
