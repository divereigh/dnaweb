<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';

// The whole list arrives with the page — a few hundred rows — so the search
// box and the column sort are both local and instant.
const props = defineProps({
    trees: { type: Array, required: true },
});

const q = ref('');
const sort = ref('name');
const hideEmpty = ref(false);

const shown = computed(() => {
    const needle = q.value.trim().toLowerCase();
    let list = props.trees;
    if (needle) {
        list = list.filter(
            (t) =>
                t.name.toLowerCase().includes(needle) ||
                (t.description || '').toLowerCase().includes(needle),
        );
    }
    if (hideEmpty.value) {
        list = list.filter((t) => t.people > 0);
    }
    const by = {
        name: (a, b) => a.name.localeCompare(b.name, undefined, { sensitivity: 'base' }),
        people: (a, b) => b.people - a.people || a.name.localeCompare(b.name),
        samples: (a, b) => b.samples - a.samples || a.name.localeCompare(b.name),
    }[sort.value];
    return [...list].sort(by);
});

const empties = computed(() => props.trees.filter((t) => t.people === 0).length);

function setSort(col) {
    sort.value = col;
}
</script>

<template>
    <Head title="Trees" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                title="Trees"
                :subtitle="`${shown.length.toLocaleString()} of ${trees.length.toLocaleString()} — the groups the Perl loaders build matches into`"
            />
        </template>

        <div class="filter-bar mb-4 sm:grid-cols-[minmax(0,24rem)_auto_1fr]">
            <input
                v-model="q"
                type="search"
                placeholder="Filter by name or description…"
                autofocus
                class="text-sm"
            />
            <label>
                <input v-model="hideEmpty" type="checkbox" />
                Hide empty ({{ empties }})
            </label>
            <span />
        </div>

        <div class="card overflow-hidden">
            <table class="ref-table">
                <thead>
                    <tr>
                        <th class="w-8" />
                        <th
                            :class="['sortable', sort === 'name' ? 'is-active' : '']"
                            @click="setSort('name')"
                        >
                            Name <span class="sort-mark">{{ sort === 'name' ? '↑' : '' }}</span>
                        </th>
                        <th>Description</th>
                        <th
                            data-numeric
                            :class="['sortable', sort === 'people' ? 'is-active' : '']"
                            @click="setSort('people')"
                        >
                            Members <span class="sort-mark">{{ sort === 'people' ? '↓' : '' }}</span>
                        </th>
                        <th data-numeric>On the DNA line</th>
                        <th
                            data-numeric
                            :class="['sortable', sort === 'samples' ? 'is-active' : '']"
                            @click="setSort('samples')"
                        >
                            Kits <span class="sort-mark">{{ sort === 'samples' ? '↓' : '' }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="t in shown" :key="t.id">
                        <td>
                            <!-- A round dot, not a square: an uncoloured tree
                                 renders as a white chip, and a white square
                                 with a hairline ring reads as a checkbox. -->
                            <span
                                class="inline-block h-3 w-3 rounded-full ring-1 ring-inset"
                                :class="t.colour ? 'ring-black/15' : 'ring-paper-400'"
                                :style="{ backgroundColor: t.colour || 'transparent' }"
                                :title="t.colour || 'No colour set'"
                            />
                        </td>
                        <td>
                            <Link :href="route('trees.show', t.name)" class="ref-link">
                                {{ t.name }}
                            </Link>
                        </td>
                        <td class="text-sepia-600">{{ t.description || '—' }}</td>
                        <td class="num">
                            <span v-if="t.people">{{ t.people.toLocaleString() }}</span>
                            <span v-else class="text-sepia-400">—</span>
                        </td>
                        <td class="num">
                            <span v-if="t.dna">{{ t.dna.toLocaleString() }}</span>
                            <span v-else class="text-sepia-400">—</span>
                        </td>
                        <td class="num">
                            <span v-if="t.samples">{{ t.samples.toLocaleString() }}</span>
                            <span v-else class="text-sepia-400">—</span>
                        </td>
                    </tr>
                    <tr v-if="!shown.length">
                        <td colspan="6" class="empty-cell">No trees match.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AuthenticatedLayout>
</template>
