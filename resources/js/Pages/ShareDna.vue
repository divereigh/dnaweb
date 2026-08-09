<script setup>
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Share your DNA" />
    <div class="min-h-screen bg-paper-100 py-10 px-4 text-ink-500">
        <div class="mx-auto max-w-2xl">
            <header class="mb-8 text-center">
                <h1 class="text-2xl font-semibold text-ink-600">Share your DNA results</h1>
                <p class="mt-2 text-sm text-sepia-500">
                    Invite <span class="font-medium text-ink-500">Damian Ivereigh</span>
                    (<a href="mailto:damo@launtel.net.au" class="text-wine-500 hover:text-wine-600">damo@launtel.net.au</a>)
                    as a <span class="font-medium text-ink-500">Viewer</span> on your Ancestry DNA test.
                </p>
            </header>

            <section class="mb-6 overflow-hidden rounded-lg bg-paper-50 shadow-card">
                <h2 class="border-b border-paper-300 bg-paper-100 px-5 py-3 text-sm font-semibold uppercase tracking-wide text-sepia-600">
                    From a computer
                </h2>
                <ol class="space-y-3 px-5 py-4 text-sm leading-relaxed">
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-wine-500 text-xs font-semibold text-white">1</span>
                        <span>Login to Ancestry.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-wine-500 text-xs font-semibold text-white">2</span>
                        <span>Go to the <strong>DNA</strong> drop-down menu and select <strong>&ldquo;Your results summary&rdquo;</strong>.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-wine-500 text-xs font-semibold text-white">3</span>
                        <span>Click the <strong>&ldquo;Invite&rdquo;</strong> button at the top right.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-wine-500 text-xs font-semibold text-white">4</span>
                        <span>Below the <strong>&ldquo;DNA test sharing&rdquo;</strong> title is another <strong>&ldquo;Invite&rdquo;</strong> button, click it.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-wine-500 text-xs font-semibold text-white">5</span>
                        <span>Enter <strong>&ldquo;Damian Ivereigh&rdquo;</strong> in the username, or select email and use <strong>damo@launtel.net.au</strong>.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-wine-500 text-xs font-semibold text-white">6</span>
                        <span>Select <strong>&ldquo;Viewer&rdquo;</strong> as the role.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-wine-500 text-xs font-semibold text-white">7</span>
                        <span>Click <strong>&ldquo;Invite&rdquo;</strong>.</span>
                    </li>
                </ol>
            </section>

            <section class="overflow-hidden rounded-lg bg-paper-50 shadow-card">
                <h2 class="border-b border-paper-300 bg-paper-100 px-5 py-3 text-sm font-semibold uppercase tracking-wide text-sepia-600">
                    From the Ancestry app
                </h2>
                <ol class="space-y-3 px-5 py-4 text-sm leading-relaxed">
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-marine-500 text-xs font-semibold text-white">1</span>
                        <span>Click on your name (top left).</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-marine-500 text-xs font-semibold text-white">2</span>
                        <span>Under <strong>&ldquo;Manage DNA Test&rdquo;</strong>, check that your name is there (if not, change it under <strong>&ldquo;Switch DNA Test&rdquo;</strong>).</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-marine-500 text-xs font-semibold text-white">3</span>
                        <span>Click on the settings cog.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-marine-500 text-xs font-semibold text-white">4</span>
                        <span>Click on <strong>&ldquo;DNA test sharing&rdquo;</strong>.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-marine-500 text-xs font-semibold text-white">5</span>
                        <span>Click on <strong>&ldquo;Invite&rdquo;</strong>.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-marine-500 text-xs font-semibold text-white">6</span>
                        <span>Enter <strong>&ldquo;Damian Ivereigh&rdquo;</strong> or <strong>damo@launtel.net.au</strong>.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-marine-500 text-xs font-semibold text-white">7</span>
                        <span>Click <strong>&ldquo;Invite&rdquo;</strong> again.</span>
                    </li>
                </ol>
            </section>
        </div>
    </div>
</template>
