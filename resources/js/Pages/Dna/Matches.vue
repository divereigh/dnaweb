<script setup>
import { ref, computed, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Pagination from '@/Components/App/Pagination.vue';
import ClusterPill from '@/Components/App/ClusterPill.vue';
import SampleAvatar from '@/Components/App/SampleAvatar.vue';
import PersonEditDialog from '@/Components/App/PersonEditDialog.vue';

const props = defineProps({
    sample: { type: Object, required: true },
    matches: { type: Array, required: true },
    page: { type: Number, required: true },
    pages: { type: Number, required: true },
    total: { type: Number, required: true },
    per_page: { type: Number, required: true },
    eyes: { type: Array, required: true },
    eye_id: { type: Number, default: null },
    selected_eye: { type: Object, default: null },
});

const ONLY = ['matches', 'page', 'pages', 'total', 'eye_id', 'selected_eye'];

function ancestryCompareUrl(otherUuid) {
    if (!props.selected_eye?.dnaUUID || !otherUuid) return null;
    const eye = String(props.selected_eye.dnaUUID).toUpperCase();
    const other = String(otherUuid).toUpperCase();
    return `https://www.ancestry.com.au/discoveryui-matches/compare/${eye}/with/${other}/matchesofmatches`;
}

// When the page sample is itself an eye, A↔B compare links use A's UUID.
function sampleCompareUrl(otherUuid) {
    if (!props.sample?.dnaUUID || !otherUuid) return null;
    const sample = String(props.sample.dnaUUID).toUpperCase();
    const other = String(otherUuid).toUpperCase();
    return `https://www.ancestry.com.au/discoveryui-matches/compare/${sample}/with/${other}/matchesofmatches`;
}

function ancestryHeaderUrl() {
    if (!props.selected_eye?.dnaUUID || !props.sample?.dnaUUID) return null;
    const eye = String(props.selected_eye.dnaUUID).toUpperCase();
    const sample = String(props.sample.dnaUUID).toUpperCase();
    return `https://www.ancestry.com.au/discoveryui-matches/compare/${eye}/with/${sample}/matchesofmatches`;
}

// When the page sample is NOT an eye but the row is, view the compare
// page from the row-eye's perspective (it has the Ancestry session).
function rowEyeCompareUrl(otherUuid) {
    if (!props.sample?.dnaUUID || !otherUuid) return null;
    const eye = String(otherUuid).toUpperCase();
    const sample = String(props.sample.dnaUUID).toUpperCase();
    return `https://www.ancestry.com.au/discoveryui-matches/compare/${eye}/with/${sample}/matchesofmatches`;
}

const sampleIsEye = computed(() => !!props.sample?.managed);
const selectedEyeIsSample = computed(
    () => !!props.selected_eye && Number(props.selected_eye.id) === Number(props.sample.id),
);

const selectedEye = ref(props.eye_id ?? '');
const loading = ref(false);

watch(selectedEye, (val) => {
    router.reload({
        only: ONLY,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        data: { eye: val || undefined, page: 1 },
        onStart: () => { loading.value = true; },
        onFinish: () => { loading.value = false; },
    });
});

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
</script>

<template>
    <Head :title="`Matches · ${sample.display_label}`" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                compact
                :title="sample.display_label"
                :eyebrow="`Sample #${sample.id}`"
            >
                <template #subtitle>
                    <Link
                        v-if="sample.person_id"
                        :href="route('people.show', sample.person_id)"
                        class="inline-flex items-center"
                        :title="`Open ${sample.person_name || 'person'} #${sample.person_id}`"
                    >
                        <img src="/icon-person.png" alt="" class="h-5 w-5" />
                        <span class="sr-only">Open person</span>
                    </Link>
                    <a
                        v-if="selected_eye"
                        :href="ancestryHeaderUrl()"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
                        :title="`Compare on Ancestry: ${selected_eye.display_label} ↔ ${sample.display_label}`"
                    >
                        <img src="/ancestry-icon.svg" alt="" class="h-3.5 w-3.5" />
                        DNA
                    </a>
                    <span>{{ total.toLocaleString() }} {{ total === 1 ? 'match' : 'matches' }}</span>
                </template>
                <template #titleBefore>
                    <SampleAvatar
                        :photo-url="sample.photoUrl || ''"
                        :alt="sample.display_label"
                        :gender="sample.effective_gender || ''"
                        size="md"
                    />
                </template>
                <template #titleAfter>
                    <span
                        v-if="sampleIsEye"
                        class="inline-flex items-center rounded bg-red-600/10 px-1.5 py-0.5 text-[10px] font-medium text-red-600"
                    >
                        eye
                    </span>
                    <button
                        type="button"
                        class="inline-flex items-center rounded p-0.5 text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                        :title="sample.person_id ? 'Edit person' : 'Create person'"
                        @click="openSampleEdit(sample)"
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
                        <span class="sr-only">{{ sample.person_id ? 'Edit person' : 'Create person' }}</span>
                    </button>
                </template>
                <template #actions>
                    <Link :href="route('dna.index')" class="btn-ghost">← DNA search</Link>
                </template>
            </PageHeader>
        </template>

        <div class="filter-bar mb-4 sm:grid-cols-[auto_minmax(0,1fr)]">
            <label for="eye-filter" class="!whitespace-normal">
                Show only matches in common with:
            </label>
            <select id="eye-filter" v-model="selectedEye" class="text-sm">
                <option value="">All</option>
                <option v-for="e in eyes" :key="e.id" :value="e.id">
                    {{ e.display_label }}
                </option>
            </select>
        </div>

        <div
            v-if="loading"
            class="card flex items-center justify-center gap-2 py-16 text-sm text-sepia-500"
        >
            <svg class="h-4 w-4 animate-spin text-sepia-400" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity="0.25" />
                <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
            </svg>
            Loading matches…
        </div>

        <template v-else>
        <div class="mb-4">
            <Pagination :page="page" :pages="pages" :total="total" :only="ONLY" />
        </div>

        <div class="card overflow-hidden">
            <table class="ref-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th data-numeric>cM</th>
                        <th>Cluster</th>
                        <th>Tested</th>
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
                                    :href="route('dna.matches', m.other_id)"
                                    class="ref-link"
                                    :class="m.ignored ? 'line-through decoration-sepia-400/60' : ''"
                                >
                                    {{ m.display_label }}
                                </Link>
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
                            <span
                                v-if="m.other_managed"
                                class="ms-2 inline-flex items-center rounded bg-red-600/10 px-1.5 py-0.5 text-[10px] font-medium text-red-600"
                                >eye</span
                            >
                            </div>
                        </td>
                        <td class="num">{{ m.sharedCentimorgans }}</td>
                        <td>
                            <ClusterPill
                                :code="m.matchClusterCode || ''"
                                :paternal-cluster="sample.paternalCluster || ''"
                            />
                        </td>
                        <td class="ident">{{ m.created_fmt }}</td>
                        <td class="!text-right">
                            <div class="inline-flex items-center gap-1">
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
                                    v-if="sampleIsEye && m.other_uuid && !selectedEyeIsSample"
                                    :href="sampleCompareUrl(m.other_uuid)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
                                    :title="`Compare on Ancestry: ${sample.display_label} ↔ ${m.display_label}`"
                                >
                                    <img src="/ancestry-icon.svg" alt="" class="h-3.5 w-3.5" />
                                    DNA
                                </a>
                                <a
                                    v-if="!sampleIsEye && m.other_managed && m.other_uuid"
                                    :href="rowEyeCompareUrl(m.other_uuid)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
                                    :title="`Compare on Ancestry: ${m.display_label} ↔ ${sample.display_label}`"
                                >
                                    <img src="/ancestry-icon.svg" alt="" class="h-3.5 w-3.5" />
                                    DNA
                                </a>
                                <a
                                    v-if="selected_eye && m.other_uuid"
                                    :href="ancestryCompareUrl(m.other_uuid)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
                                    :title="`Compare on Ancestry: ${selected_eye.display_label} ↔ ${m.display_label}`"
                                >
                                    <img src="/ancestry-icon.svg" alt="" class="h-3.5 w-3.5" />
                                    DNA
                                </a>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!matches.length">
                        <td colspan="5" class="empty-cell">No matches.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <Pagination :page="page" :pages="pages" :total="total" :only="ONLY" />
        </div>
        </template>

        <PersonEditDialog
            :show="!!editing"
            :sample-id="editing?.sampleId ?? 0"
            :person-id="editing?.personId ?? null"
            :prefill="editing?.prefill ?? {}"
            @close="closeEdit"
        />
    </AuthenticatedLayout>
</template>
