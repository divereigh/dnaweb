<script setup>
import { computed, nextTick, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    sampleId: { type: Number, required: true },
    personId: { type: Number, default: null },
    prefill: {
        type: Object,
        default: () => ({ fullName: '', minBirth: null, maxBirth: null, death: null, gender: null }),
    },
});

const emit = defineEmits(['close']);

const nameInput = ref(null);

const initial = computed(() => {
    const p = props.prefill || {};
    let birth = '';
    if (p.minBirth && p.maxBirth && p.minBirth !== p.maxBirth) {
        birth = `${p.minBirth}-${p.maxBirth}`;
    } else if (p.minBirth || p.maxBirth) {
        birth = String(p.minBirth || p.maxBirth);
    }
    return {
        fullName: p.fullName || '',
        birth,
        death: p.death || null,
        gender: p.gender || '',
        ancestry_url: '',
    };
});

const form = useForm({ ...initial.value });

watch(
    () => [props.show, props.prefill, props.sampleId],
    () => {
        if (props.show) {
            form.clearErrors();
            form.defaults({ ...initial.value });
            form.reset();
            nextTick(() => nameInput.value?.focus());
        }
    },
);

const isCreating = computed(() => !props.personId);

function submit() {
    form.transform((data) => ({
        fullName: data.fullName,
        birth: data.birth || null,
        death: data.death === '' ? null : data.death,
        gender: data.gender || null,
        ancestry_url: data.ancestry_url?.trim() || null,
    })).put(route('dna.person.upsert', props.sampleId), {
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
    <Modal :show="show" max-width="md" @close="close">
        <form @submit.prevent="submit" class="bg-paper-50 p-6">
            <div class="mb-4 flex items-baseline justify-between">
                <h2 class="text-base font-semibold text-ink-600">
                    {{ isCreating ? 'Create person' : 'Edit person' }}
                </h2>
                <span class="text-xs uppercase tracking-eyebrow text-sepia-500">
                    Sample #{{ sampleId }}
                </span>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-eyebrow text-sepia-500">
                        Name
                    </label>
                    <input
                        ref="nameInput"
                        v-model="form.fullName"
                        type="text"
                        maxlength="100"
                        class="w-full rounded-md border-paper-300 text-sm focus:border-wine-500 focus:ring-wine-500"
                    />
                    <p v-if="form.errors.fullName" class="mt-1 text-xs text-red-600">
                        {{ form.errors.fullName }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-eyebrow text-sepia-500">
                            Birth year
                        </label>
                        <input
                            v-model="form.birth"
                            type="text"
                            placeholder="1885 or 1880-1885"
                            class="w-full rounded-md border-paper-300 text-sm focus:border-wine-500 focus:ring-wine-500"
                        />
                        <p v-if="form.errors.birth" class="mt-1 text-xs text-red-600">
                            {{ form.errors.birth }}
                        </p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-eyebrow text-sepia-500">
                            Death year
                        </label>
                        <input
                            v-model="form.death"
                            type="number"
                            min="1500"
                            max="2100"
                            class="w-full rounded-md border-paper-300 text-sm focus:border-wine-500 focus:ring-wine-500"
                        />
                        <p v-if="form.errors.death" class="mt-1 text-xs text-red-600">
                            {{ form.errors.death }}
                        </p>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-eyebrow text-sepia-500">
                        Gender
                    </label>
                    <select
                        v-model="form.gender"
                        class="w-full rounded-md border-paper-300 text-sm focus:border-wine-500 focus:ring-wine-500"
                    >
                        <option value="">— unknown —</option>
                        <option value="M">M</option>
                        <option value="F">F</option>
                        <option value="U">U</option>
                    </select>
                    <p v-if="form.errors.gender" class="mt-1 text-xs text-red-600">
                        {{ form.errors.gender }}
                    </p>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-eyebrow text-sepia-500">
                        Ancestry tree link
                    </label>
                    <input
                        v-model="form.ancestry_url"
                        type="text"
                        maxlength="500"
                        placeholder="Paste an Ancestry family-tree URL or {person}:1030:{tree}"
                        class="w-full rounded-md border-paper-300 font-mono text-xs focus:border-wine-500 focus:ring-wine-500"
                    />
                    <p class="mt-1 text-[11px] text-sepia-400">
                        Links this person to a gedcom_people row (tree id + ancestry id) — leave blank to skip.
                    </p>
                    <p v-if="form.errors.ancestry_url" class="mt-1 text-xs text-red-600">
                        {{ form.errors.ancestry_url }}
                    </p>
                </div>
            </div>

            <div class="mt-6 flex items-center justify-end gap-2">
                <button
                    type="button"
                    class="btn-ghost"
                    :disabled="form.processing"
                    @click="close"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    class="inline-flex items-center rounded-md bg-ink-500 px-3 py-1.5 text-sm font-medium text-paper-50 transition hover:bg-ink-600 disabled:opacity-50"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Saving…' : 'Save' }}
                </button>
            </div>
        </form>
    </Modal>
</template>
