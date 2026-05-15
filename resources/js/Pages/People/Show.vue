<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import ClusterPill from '@/Components/App/ClusterPill.vue';
import SampleAvatar from '@/Components/App/SampleAvatar.vue';

defineProps({
    person: { type: Object, required: true },
    matches: { type: Array, required: true },
    linked_sample_missing: { type: Boolean, default: false },
    family: { type: Array, required: true },
    siblings: { type: Object, required: true },
    ancestry_trees: { type: Array, required: true },
});
</script>

<template>
    <Head :title="person.display_label" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                compact
                :title="person.display_label"
                :eyebrow="`Person #${person.id}`"
                :subtitle="
                    [
                        person.years,
                        person.gender ? `(${person.gender})` : null,
                        person.dnaName ? `kit ${person.dnaName}` : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')
                "
            >
                <template #actions>
                    <Link :href="route('people.tree', person.id)" class="btn-ghost">
                        Ancestor tree →
                    </Link>
                    <Link :href="route('people.index')" class="btn-ghost">← People</Link>
                </template>
            </PageHeader>
        </template>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <section class="space-y-4 lg:col-span-2">
                <!-- Parents -->
                <div class="card p-4">
                    <p class="eyebrow mb-2">Parents</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <p class="text-xs text-sepia-500">Father</p>
                            <Link
                                v-if="person.father_id"
                                :href="route('people.show', person.father_id)"
                                class="ref-link"
                            >
                                {{ person.father_display_label }}
                            </Link>
                            <span v-else class="text-sepia-400">—</span>
                            <p
                                v-if="person.father_id"
                                class="mt-0.5 font-mono text-xs text-sepia-500"
                            >
                                {{ person.father_years }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-sepia-500">Mother</p>
                            <Link
                                v-if="person.mother_id"
                                :href="route('people.show', person.mother_id)"
                                class="ref-link"
                            >
                                {{ person.mother_display_label }}
                            </Link>
                            <span v-else class="text-sepia-400">—</span>
                            <p
                                v-if="person.mother_id"
                                class="mt-0.5 font-mono text-xs text-sepia-500"
                            >
                                {{ person.mother_years }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Children -->
                <div v-if="family.length" class="card p-4">
                    <p class="eyebrow mb-2">Children</p>
                    <div v-for="(s, idx) in family" :key="idx" class="mb-4 last:mb-0">
                        <p class="text-sm text-sepia-600">
                            with
                            <Link
                                v-if="s.spouse_id"
                                :href="route('people.show', s.spouse_id)"
                                class="font-medium text-ink-500 hover:text-wine-500"
                            >
                                {{ s.spouse_display_label }}
                            </Link>
                            <span v-else class="text-sepia-400">unknown</span>
                            <span
                                v-if="s.spouse_years"
                                class="font-mono text-xs text-sepia-500"
                                >· {{ s.spouse_years }}</span
                            >
                        </p>
                        <ul class="mt-1 space-y-0.5 ps-4">
                            <li
                                v-for="c in s.children"
                                :key="c.child_id"
                                class="text-sm"
                            >
                                <span class="me-2 text-sepia-400">·</span>
                                <Link
                                    :href="route('people.show', c.child_id)"
                                    class="ref-link"
                                >
                                    {{ c.display_label }}
                                </Link>
                                <span
                                    v-if="c.years"
                                    class="ms-2 font-mono text-xs text-sepia-500"
                                    >{{ c.years }}</span
                                >
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Siblings -->
                <div
                    v-if="
                        siblings.full.length ||
                        siblings.half_father.length ||
                        siblings.half_mother.length
                    "
                    class="card p-4"
                >
                    <p class="eyebrow mb-2">Siblings</p>

                    <div v-if="siblings.full.length" class="mb-3">
                        <p class="mb-1 text-xs text-sepia-500">Full</p>
                        <ul class="space-y-0.5 ps-4">
                            <li v-for="s in siblings.full" :key="s.id" class="text-sm">
                                <span class="me-2 text-sepia-400">·</span>
                                <Link :href="route('people.show', s.id)" class="ref-link">
                                    {{ s.display_label }}
                                </Link>
                                <span
                                    v-if="s.years"
                                    class="ms-2 font-mono text-xs text-sepia-500"
                                    >{{ s.years }}</span
                                >
                            </li>
                        </ul>
                    </div>

                    <div v-if="siblings.half_father.length" class="mb-3">
                        <p class="mb-1 text-xs text-sepia-500">Paternal half</p>
                        <ul class="space-y-0.5 ps-4">
                            <li v-for="s in siblings.half_father" :key="s.id" class="text-sm">
                                <span class="me-2 text-sepia-400">·</span>
                                <Link :href="route('people.show', s.id)" class="ref-link">
                                    {{ s.display_label }}
                                </Link>
                                <span
                                    v-if="s.years"
                                    class="ms-2 font-mono text-xs text-sepia-500"
                                    >{{ s.years }}</span
                                >
                            </li>
                        </ul>
                    </div>

                    <div v-if="siblings.half_mother.length">
                        <p class="mb-1 text-xs text-sepia-500">Maternal half</p>
                        <ul class="space-y-0.5 ps-4">
                            <li v-for="s in siblings.half_mother" :key="s.id" class="text-sm">
                                <span class="me-2 text-sepia-400">·</span>
                                <Link :href="route('people.show', s.id)" class="ref-link">
                                    {{ s.display_label }}
                                </Link>
                                <span
                                    v-if="s.years"
                                    class="ms-2 font-mono text-xs text-sepia-500"
                                    >{{ s.years }}</span
                                >
                            </li>
                        </ul>
                    </div>
                </div>
            </section>

            <!-- Sidebar -->
            <aside class="space-y-4">
                <div class="card p-4">
                    <p class="eyebrow mb-2">DNA</p>
                    <div v-if="person.dnaSampleId">
                        <p class="text-sm">
                            Sample
                            <Link
                                :href="route('dna.matches', person.dnaSampleId)"
                                class="font-mono text-ink-600 hover:text-wine-500"
                            >
                                #{{ person.dnaSampleId }}
                            </Link>
                        </p>
                        <p v-if="person.dnaName" class="mt-0.5 text-sm text-sepia-600">
                            {{ person.dnaName }}
                        </p>
                        <p
                            v-if="person.is_managed_sample"
                            class="mt-2 inline-flex items-center rounded bg-red-600/10 px-1.5 py-0.5 text-[10px] font-medium text-red-600"
                        >
                            eye
                        </p>
                    </div>
                    <p v-else class="text-sm text-sepia-400">No sample linked.</p>
                </div>

                <div v-if="ancestry_trees.length" class="card p-4">
                    <p class="eyebrow mb-2">Ancestry trees</p>
                    <ul class="space-y-1 text-sm text-sepia-600">
                        <li v-for="t in ancestry_trees" :key="t.atreeid">
                            <span class="text-ink-500">{{ t.name }}</span>
                            <span class="ms-1 font-mono text-[11px] text-sepia-400"
                                >#{{ t.ancestryid }}</span
                            >
                        </li>
                    </ul>
                </div>
            </aside>
        </div>

        <!-- Matches -->
        <section
            v-if="matches.length || linked_sample_missing"
            class="mt-4 card overflow-hidden"
        >
            <header class="flex items-baseline justify-between border-b border-paper-300 bg-paper-100 px-4 py-2.5">
                <p class="eyebrow">Matches with managed eyes</p>
                <p
                    v-if="!linked_sample_missing"
                    class="font-mono text-xs text-sepia-500"
                >
                    {{ matches.length }}
                </p>
            </header>
            <div v-if="linked_sample_missing" class="px-4 py-3 text-sm text-wine-500">
                Linked DNA sample missing from <code class="font-mono">dna_samples</code>.
            </div>
            <table v-else class="ref-table">
                <thead>
                    <tr>
                        <th>Eye</th>
                        <th data-numeric>cM</th>
                        <th data-numeric>Segs</th>
                        <th>Cluster</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="m in matches"
                        :key="m.eye_id"
                        :class="m.ignored ? 'opacity-50' : ''"
                    >
                        <td>
                            <div class="flex items-center gap-2">
                                <SampleAvatar
                                    :photo-url="m.eye_photoUrl || ''"
                                    :alt="m.display_label"
                                    :gender="m.effective_gender || ''"
                                />
                                <Link
                                    :href="route('eyes.matches', m.eye_id)"
                                    class="ref-link"
                                    :class="m.ignored ? 'line-through decoration-sepia-400/60' : ''"
                                >
                                    {{ m.display_label }}
                                </Link>
                            </div>
                        </td>
                        <td class="num">{{ m.sharedCentimorgans }}</td>
                        <td class="num">{{ m.numSharedSegments }}</td>
                        <td>
                            <ClusterPill
                                :code="m.matchClusterCode || ''"
                                :paternal-cluster="m.eye_paternalCluster || ''"
                            />
                        </td>
                        <td class="max-w-xs truncate text-sepia-600" :title="m.notes">
                            {{ m.notes }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </AuthenticatedLayout>
</template>
