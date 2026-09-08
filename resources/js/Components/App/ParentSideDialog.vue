<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import SampleAvatar from '@/Components/App/SampleAvatar.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    // An eye row from /eyes: { id, display_label, paternalCluster,
    // paternalCluster_ancestry, paternalClusterOverride,
    // cluster_p1_count, cluster_p2_count }
    eye: { type: Object, default: null },
    // { p1: [...], p2: [...] } for `eye`, or null while it loads.
    evidence: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const form = useForm({ paternalClusterOverride: null });
const loadingEvidence = ref(false);

watch(
    () => [props.show, props.eye?.id],
    () => {
        if (!props.show || !props.eye) return;
        form.clearErrors();
        form.paternalClusterOverride = props.eye.paternalClusterOverride || null;

        // Pull the top matches of each cluster on demand — see the
        // `cluster_evidence` closure in EyesController::index.
        loadingEvidence.value = true;
        router.reload({
            only: ['cluster_evidence'],
            data: { evidence: props.eye.id },
            preserveState: true,
            preserveScroll: true,
            onFinish: () => { loadingEvidence.value = false; },
        });
    },
    { immediate: true },
);

const ancestry = computed(() => (props.eye?.paternalCluster_ancestry || '').trim().toLowerCase());

// What the pills will actually say once this is saved. Mirrors
// ClusterPill.vue: whichever cluster is paternal, the other is maternal.
const resolved = computed(() => {
    const pick = form.paternalClusterOverride || ancestry.value;
    if (pick !== 'p1' && pick !== 'p2') return null;
    return {
        p1: pick === 'p1' ? 'PATERNAL' : 'MATERNAL',
        p2: pick === 'p2' ? 'PATERNAL' : 'MATERNAL',
    };
});

const disagrees = computed(
    () => !!form.paternalClusterOverride && !!ancestry.value && form.paternalClusterOverride !== ancestry.value,
);

function sideLabel(code) {
    return resolved.value ? resolved.value[code] : code.toUpperCase();
}

function submit() {
    if (!props.eye?.id) return;
    form
        .transform((d) => ({ paternalClusterOverride: d.paternalClusterOverride || null }))
        .put(route('eyes.parent-side.update', props.eye.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => emit('close'),
        });
}

function close() {
    if (form.processing) return;
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <transition
            enter-active-class="duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-if="show" class="fixed inset-0 z-40 bg-black/30" @click="close" />
        </transition>

        <transition
            enter-active-class="duration-200 ease-out"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="duration-150 ease-in"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <aside
                v-if="show && eye"
                class="fixed right-0 top-0 z-50 flex h-full w-full max-w-xl flex-col bg-paper-50 shadow-xl"
                role="dialog"
                aria-modal="true"
                @keydown.esc="close"
            >
                <header class="flex items-start justify-between border-b border-paper-300 px-5 py-4">
                    <div class="min-w-0">
                        <p class="eyebrow">ParentSide mapping</p>
                        <h2 class="truncate text-base font-semibold text-ink-600">
                            {{ eye.display_label }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        class="rounded p-1 text-sepia-400 hover:bg-paper-100 hover:text-wine-500 focus:outline-none focus:ring-1 focus:ring-wine-500"
                        :disabled="form.processing"
                        @click="close"
                        aria-label="Close"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-4 w-4" aria-hidden="true">
                            <path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22z" />
                        </svg>
                    </button>
                </header>

                <form @submit.prevent="submit" class="flex flex-1 flex-col overflow-hidden">
                    <div class="flex-1 overflow-y-auto px-5 py-4">
                        <p class="text-sm text-sepia-600">
                            Ancestry splits this kit's matches into two parent clusters but only
                            names the paternal one once the owner has labelled their sides. Pick
                            it here and every ParentSide pill for this eye reads PATERNAL /
                            MATERNAL instead of P1 / P2.
                        </p>

                        <p class="mt-3 text-xs text-sepia-500">
                            <span class="font-medium text-sepia-600">Ancestry says:</span>
                            <template v-if="ancestry">
                                {{ ancestry }} is paternal
                            </template>
                            <template v-else>
                                nothing — the owner has not set it
                            </template>
                        </p>

                        <fieldset class="mt-5">
                            <legend class="mb-2 block text-sm font-medium text-ink-600">
                                Which cluster is the paternal side?
                            </legend>
                            <div class="space-y-2">
                                <label
                                    v-for="code in ['p1', 'p2']"
                                    :key="code"
                                    class="flex cursor-pointer items-center gap-2 rounded border px-3 py-2 text-sm"
                                    :class="form.paternalClusterOverride === code
                                        ? 'border-wine-500 bg-wine-500/5'
                                        : 'border-paper-300 hover:bg-paper-100'"
                                >
                                    <input
                                        v-model="form.paternalClusterOverride"
                                        type="radio"
                                        :value="code"
                                        class="cursor-pointer"
                                    />
                                    <span class="font-mono text-xs uppercase text-sepia-600">{{ code }}</span>
                                    <span class="text-ink-500">is the paternal side</span>
                                    <span class="ms-auto text-xs text-sepia-500">
                                        {{ Number(code === 'p1' ? eye.cluster_p1_count : eye.cluster_p2_count).toLocaleString() }}
                                        matches
                                    </span>
                                </label>
                                <label
                                    class="flex cursor-pointer items-center gap-2 rounded border px-3 py-2 text-sm"
                                    :class="!form.paternalClusterOverride
                                        ? 'border-wine-500 bg-wine-500/5'
                                        : 'border-paper-300 hover:bg-paper-100'"
                                >
                                    <input
                                        v-model="form.paternalClusterOverride"
                                        type="radio"
                                        :value="null"
                                        class="cursor-pointer"
                                    />
                                    <span class="text-ink-500">
                                        No override —
                                        {{ ancestry ? `use Ancestry's (${ancestry})` : 'leave as P1 / P2' }}
                                    </span>
                                </label>
                            </div>
                            <p v-if="form.errors.paternalClusterOverride" class="mt-1 text-xs text-red-600">
                                {{ form.errors.paternalClusterOverride }}
                            </p>
                        </fieldset>

                        <p v-if="disagrees" class="mt-3 rounded border border-orange-200 bg-orange-50 px-3 py-2 text-xs text-orange-800">
                            This contradicts Ancestry, which says {{ ancestry }} is paternal.
                            Saving keeps Ancestry's value untouched but everything in this app
                            will use yours.
                        </p>

                        <h3 class="mt-6 text-sm font-medium text-ink-600">
                            Strongest matches in each cluster
                        </h3>
                        <p class="mb-3 text-xs text-sepia-500">
                            Recognise a relative here and you have your answer.
                        </p>

                        <p v-if="loadingEvidence && !evidence" class="text-sm text-sepia-500">
                            Loading…
                        </p>
                        <div v-else-if="evidence" class="grid gap-4 sm:grid-cols-2">
                            <div v-for="code in ['p1', 'p2']" :key="code" class="card overflow-hidden">
                                <div class="flex items-baseline gap-2 border-b border-paper-300 bg-paper-100 px-3 py-2">
                                    <span class="font-mono text-xs uppercase text-sepia-600">{{ code }}</span>
                                    <span
                                        class="text-[10px] font-semibold tracking-wide"
                                        :class="sideLabel(code) === 'PATERNAL'
                                            ? 'text-blue-700'
                                            : (sideLabel(code) === 'MATERNAL' ? 'text-pink-700' : 'text-sepia-500')"
                                    >
                                        {{ sideLabel(code) }}
                                    </span>
                                </div>
                                <ul class="divide-y divide-paper-200">
                                    <li
                                        v-for="m in evidence[code] || []"
                                        :key="m.other_id"
                                        class="flex items-center gap-2 px-3 py-1.5 text-xs"
                                    >
                                        <SampleAvatar
                                            :photo-url="m.other_photoUrl || ''"
                                            :alt="m.display_label"
                                            :gender="m.effective_gender || ''"
                                        />
                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-ink-500">{{ m.display_label }}</span>
                                            <span class="block truncate text-sepia-500">
                                                {{ (m.kinships || []).join(' / ') || '—' }}
                                            </span>
                                        </span>
                                        <span class="font-mono text-sepia-600" data-numeric>
                                            {{ m.sharedCentimorgans }}
                                        </span>
                                    </li>
                                    <li
                                        v-if="!(evidence[code] || []).length"
                                        class="px-3 py-2 text-xs text-sepia-400"
                                    >
                                        No matches in this cluster.
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <footer class="flex items-center justify-end gap-2 border-t border-paper-300 px-5 py-4">
                        <button type="button" class="btn-ghost" :disabled="form.processing" @click="close">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            class="inline-flex items-center rounded-md bg-ink-500 px-3 py-1.5 text-sm font-medium text-paper-50 transition hover:bg-ink-600 disabled:opacity-50"
                            :disabled="form.processing"
                        >
                            {{ form.processing ? 'Saving…' : 'Save' }}
                        </button>
                    </footer>
                </form>
            </aside>
        </transition>
    </Teleport>
</template>
