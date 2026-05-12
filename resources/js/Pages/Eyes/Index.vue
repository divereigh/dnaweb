<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import { Head, Link } from '@inertiajs/vue3';
import SampleAvatar from '@/Components/App/SampleAvatar.vue';

defineProps({
    eyes: { type: Array, required: true },
});
</script>

<template>
    <Head title="Eyes" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                title="Eyes"
                :subtitle="
                    eyes.length
                        ? `${eyes.length} managed DNA test ${eyes.length === 1 ? 'kit' : 'kits'}`
                        : 'No managed kits'
                "
            />
        </template>

        <div class="card overflow-hidden">
            <table class="ref-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Person</th>
                        <th data-numeric>Matches</th>
                        <th>Sex</th>
                        <th>UUID</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="e in eyes" :key="e.id">
                        <td>
                            <div class="flex items-center gap-2">
                                <SampleAvatar
                                    :photo-url="e.photoUrl || ''"
                                    :alt="e.displayName || ''"
                                    :gender="e.effective_gender || ''"
                                />
                                <Link :href="route('eyes.matches', e.id)" class="ref-link">
                                    {{ e.displayName || `Eye #${e.id}` }}
                                </Link>
                                <span class="font-mono text-[11px] text-sepia-400">#{{ e.id }}</span>
                            </div>
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
                        <td colspan="5" class="empty-cell">No eyes.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
