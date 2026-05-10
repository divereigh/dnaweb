<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    eyes: { type: Array, required: true },
});
</script>

<template>
    <Head title="Eyes" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                title="The Register of Eyes"
                eyebrow="Section I — managed kits"
                :subtitle="
                    eyes.length
                        ? `Currently observing ${eyes.length} test ${eyes.length === 1 ? 'kit' : 'kits'}.`
                        : 'No managed kits found in the register.'
                "
            />
        </template>

        <div class="card overflow-hidden">
            <table class="ref-table">
                <thead>
                    <tr>
                        <th>Display name</th>
                        <th>Linked person</th>
                        <th class="!text-right" data-numeric>Matches</th>
                        <th>Sex</th>
                        <th>DNA UUID</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in eyes" :key="e.id">
                        <td>
                            <Link :href="route('eyes.matches', e.id)" class="ref-link">
                                {{ e.displayName || `Eye №${e.id}` }}
                            </Link>
                            <span class="ms-2 align-baseline font-mono text-[10px] text-sepia-400"
                                >№{{ e.id }}</span
                            >
                        </td>
                        <td>
                            <Link
                                v-if="e.person_id"
                                :href="route('people.show', e.person_id)"
                                class="ref-link"
                            >
                                {{ e.person_name }}
                            </Link>
                            <span v-else class="text-sepia-400">—</span>
                        </td>
                        <td class="num">{{ Number(e.match_count).toLocaleString() }}</td>
                        <td class="font-mono text-xs text-sepia-500">
                            {{ e.gender || '—' }}
                        </td>
                        <td class="ident">{{ e.dnaUUID }}</td>
                    </tr>
                    <tr v-if="!eyes.length">
                        <td colspan="5" class="empty-cell">
                            The register is empty.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
