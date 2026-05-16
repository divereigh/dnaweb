<script setup>
const props = defineProps({
    userUuid: { type: String, default: '' },
    adminUserUuid: { type: String, default: '' },
    label: { type: String, default: '' },           // optional, for tooltip
    adminLabel: { type: String, default: '' },      // optional, for tooltip
});

function profileUrl(uuid) {
    if (!uuid) return null;
    return `https://www.ancestry.com.au/profile/${String(uuid).toUpperCase()}`;
}
</script>

<template>
    <a
        v-if="userUuid"
        :href="profileUrl(userUuid)"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
        :title="label ? `Ancestry profile: ${label}` : 'Ancestry profile (user)'"
    >
        <img src="/ancestry-icon.svg" alt="" class="h-3.5 w-3.5" />
        USER
    </a>
    <a
        v-if="adminUserUuid"
        :href="profileUrl(adminUserUuid)"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-1 rounded border border-paper-300 bg-paper-50 px-1.5 py-0.5 text-[11px] font-medium text-ink-300 hover:border-paper-400 hover:bg-paper-100 hover:text-ink-500"
        :title="adminLabel ? `Ancestry profile of admin for: ${adminLabel}` : 'Ancestry profile (admin)'"
    >
        <img src="/ancestry-icon.svg" alt="" class="h-3.5 w-3.5" />
        ADMIN
    </a>
</template>
