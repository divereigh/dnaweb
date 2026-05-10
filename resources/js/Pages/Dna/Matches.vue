<script setup>
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Pagination from '@/Components/App/Pagination.vue';
import ClusterPill from '@/Components/App/ClusterPill.vue';
import PersonEditDialog from '@/Components/App/PersonEditDialog.vue';

defineProps({
    sample: { type: Object, required: true },
    matches: { type: Array, required: true },
    page: { type: Number, required: true },
    pages: { type: Number, required: true },
    total: { type: Number, required: true },
    per_page: { type: Number, required: true },
});

const ONLY = ['matches', 'page', 'pages', 'total'];

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
                :subtitle="`${total.toLocaleString()} ${total === 1 ? 'match' : 'matches'}`"
            >
                <template #actions>
                    <Link :href="route('dna.index')" class="btn-ghost">← DNA search</Link>
                </template>
            </PageHeader>
        </template>

        <div class="card overflow-hidden">
            <table class="ref-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th data-numeric>cM</th>
                        <th data-numeric>Segs</th>
                        <th data-numeric>Meiosis</th>
                        <th>Cluster</th>
                        <th>DNA path</th>
                        <th>Tested</th>
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
                                class="ms-2 inline-flex items-center rounded bg-marine-500/10 px-1.5 py-0.5 text-[10px] font-medium text-marine-500"
                                >Managed</span
                            >
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
                        <td class="ident">{{ m.dnapath || '' }}</td>
                        <td class="ident">{{ m.created_fmt }}</td>
                    </tr>
                    <tr v-if="!matches.length">
                        <td colspan="7" class="empty-cell">No matches.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <Pagination :page="page" :pages="pages" :total="total" :only="ONLY" />
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
