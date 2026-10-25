<script setup>
import { Head } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';

defineProps({
    version: { type: String, required: true },
});

const steps = [
    'Download the zip below and unzip it somewhere permanent, such as Documents. Chrome loads the extension from that folder every time, so don’t delete it afterwards.',
    'In Chrome, go to <strong>chrome://extensions</strong> and turn on <strong>Developer mode</strong> (top right).',
    'Click <strong>Load unpacked</strong> and choose the <strong>ancestry-switch</strong> folder you just unzipped.',
    'Optionally, pin it using the puzzle-piece icon in the toolbar, so its button is easy to reach.',
    'Reload any open dnaweb tabs.',
];
</script>

<template>
    <Head title="Ancestry Switch" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                title="Ancestry Switch"
                subtitle="A Chrome extension that opens dnaweb’s Ancestry links under the account that can see them"
            />
        </template>

        <div class="mx-auto max-w-2xl space-y-6">
            <section class="card p-5">
                <p class="text-sm leading-relaxed text-ink-500">
                    When you click a DNA or tree link here, Ancestry normally opens under whatever
                    account your browser is logged in as. With this extension, a link to one of
                    the other account’s kits switches Ancestry to that account first.
                </p>
                <a :href="route('ancestry-switch.download')" class="btn-primary mt-4 inline-flex">
                    Download version {{ version }}
                </a>
            </section>

            <section class="card p-5">
                <p class="eyebrow mb-3">Install</p>
                <ol class="space-y-3 text-sm leading-relaxed">
                    <li v-for="(step, i) in steps" :key="i" class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-wine-500 text-xs font-semibold text-white">
                            {{ i + 1 }}
                        </span>
                        <span v-html="step" />
                    </li>
                </ol>
                <p class="mt-4 text-sm text-sepia-500">
                    Chrome shows a “developer mode extensions” warning when it starts. That’s
                    normal for an extension installed this way; just dismiss it.
                </p>
            </section>

            <section class="card p-5">
                <p class="eyebrow mb-3">Using it</p>
                <ul class="list-disc space-y-2 pl-5 text-sm leading-relaxed">
                    <li>
                        To get back to your own login, click the extension’s icon and use the
                        button in its popup.
                    </li>
                    <li class="font-medium text-wine-600">
                        Never use Ancestry’s “Sign out” while you’re on the other account’s
                        login. It may cut off the loaders until the session is reloaded.
                    </li>
                    <li>
                        Switching changes the login for every open Ancestry tab, not just the new one.
                    </li>
                    <li>
                        To update: download again, unzip over the old folder, then click the
                        reload icon on the extension in <strong>chrome://extensions</strong>.
                    </li>
                </ul>
            </section>
        </div>
    </AuthenticatedLayout>
</template>
