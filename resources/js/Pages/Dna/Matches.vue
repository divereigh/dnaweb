<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Pagination from '@/Components/App/Pagination.vue';
import ClusterPill from '@/Components/App/ClusterPill.vue';

defineProps({
    sample: { type: Object, required: true },
    matches: { type: Array, required: true },
    page: { type: Number, required: true },
    pages: { type: Number, required: true },
    total: { type: Number, required: true },
    per_page: { type: Number, required: true },
});

const ONLY = ['matches', 'page', 'pages', 'total'];
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
    </AuthenticatedLayout>
</template>
