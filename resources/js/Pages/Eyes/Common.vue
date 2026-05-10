<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import Pagination from '@/Components/App/Pagination.vue';
import ClusterPill from '@/Components/App/ClusterPill.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    eye: { type: Object, required: true },
    match: { type: Object, required: true },
    common: { type: Array, required: true },
    page: { type: Number, required: true },
    per_page: { type: Number, required: true },
    total: { type: Number, required: true },
    pages: { type: Number, required: true },
    allowed_per_page: { type: Array, required: true },
});

const ONLY = ['common', 'page', 'pages', 'total', 'per_page'];
</script>

<template>
    <Head :title="`Common · ${eye.display_label} ↔ ${match.other_display_label}`" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                compact
                :title="`${eye.display_label} ↔ ${match.other_display_label}`"
                eyebrow="Common matches"
                :subtitle="`${total.toLocaleString()} mutual ${total === 1 ? 'match' : 'matches'} · pair shares ${match.sharedCentimorgans} cM (${match.numSharedSegments} segments)`"
            >
                <template #actions>
                    <Link :href="route('eyes.matches', eye.id)" class="btn-ghost">
                        ← {{ eye.display_label }}
                    </Link>
                </template>
            </PageHeader>
        </template>

        <div class="card overflow-hidden">
            <table class="ref-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th data-numeric>cM → eye</th>
                        <th data-numeric>cM → match</th>
                        <th>Cluster</th>
                        <th>Tested</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="m in common" :key="m.other_id">
                        <td>
                            <Link
                                v-if="m.person_id"
                                :href="route('people.show', m.person_id)"
                                class="ref-link"
                            >
                                {{ m.display_label }}
                            </Link>
                            <span v-else class="font-medium text-ink-500">
                                {{ m.display_label }}
                            </span>
                            <span
                                v-if="m.managed"
                                class="ms-2 inline-flex items-center rounded bg-marine-500/10 px-1.5 py-0.5 text-[10px] font-medium text-marine-500"
                            >
                                Managed
                            </span>
                        </td>
                        <td class="num">{{ m.cm_to_eye }}</td>
                        <td class="num">{{ m.cm_to_match }}</td>
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
                    </tr>
                    <tr v-if="!common.length">
                        <td colspan="6" class="empty-cell">No common matches.</td>
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
