<script setup>
import { ref, onMounted } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/App/PageHeader.vue';
import f3 from 'family-chart';
import 'family-chart/styles/family-chart.css';

const props = defineProps({
    person: { type: Object, required: true },
    tree: { type: Object, required: true },
});

const chartContainer = ref(null);

onMounted(() => {
    const chart = f3.createChart(chartContainer.value, props.tree.people)
        .setTransitionTime(300)
        .setCardXSpacing(260)
        .setCardYSpacing(150)
        .setOrientationVertical()
        .setShowSiblingsOfMain(true)
        .setDuplicateBranchToggle(true)
        .setSingleParentEmptyCard(false, { label: '' });

    function handleCardClick(e, d) {
        const personId = d?.data?.data?._person_id ?? d?.data?._person_id;
        if ((e.ctrlKey || e.metaKey) && personId) {
            e.preventDefault();
            e.stopPropagation();
            router.visit(route('people.show', personId));
            return;
        }
        chart.store.updateMainId(d.data.id);
        chart.store.updateTree({});
    }

    chart.setCard(f3.cardHtml)
        .setCardDisplay([['first name'], ['birthday']])
        .setCardDim({ w: 220, h: 70 })
        .setMiniTree(true)
        .setStyle('rect')
        .setOnCardClick(handleCardClick);

    chart.updateMainId(props.tree.focus_id);
    chart.updateTree({ initial: true });
});
</script>

<template>
    <Head :title="`Family tree · ${person.display_label}`" />
    <AuthenticatedLayout>
        <template #header>
            <PageHeader
                compact
                :title="person.display_label"
                :eyebrow="`Person #${person.id} · family tree`"
                :subtitle="`${tree.people.length} people in view · ${tree.ancestor_depth} gen up · ${tree.descendant_depth} gen down · click a card to re-center · ctrl-click for details`"
            >
                <template #actions>
                    <Link :href="route('people.show', person.id)" class="btn-ghost">
                        ← {{ person.display_label }}
                    </Link>
                </template>
            </PageHeader>
        </template>

        <div class="card overflow-hidden">
            <div
                ref="chartContainer"
                class="f3"
                style="width: 100%; height: 78vh; background: var(--paper-50, #faf7f1);"
            ></div>
        </div>
    </AuthenticatedLayout>
</template>

<style>
/* Darken the connector lines — f3 sets stroke="#fff" inline; CSS wins. */
.f3 svg.main_svg .links_view path.link {
    stroke: #57534e;
    stroke-width: 1.5;
}
</style>
