<script setup lang="ts">
import { computed } from 'vue';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

const props = defineProps<{
    content: string;
}>();

marked.setOptions({
    breaks: true,
});

const html = computed(() => {
    const raw = marked.parse(props.content) as string;
    return DOMPurify.sanitize(raw);
});
</script>

<template>
    <div class="markdown-message" v-html="html" />
</template>

<style scoped>
.markdown-message {
    line-height: 1.6;
}

.markdown-message :deep(p) {
    margin-bottom: 0.5rem;
}

.markdown-message :deep(p:last-child) {
    margin-bottom: 0;
}

.markdown-message :deep(strong) {
    font-weight: 600;
}

.markdown-message :deep(ul),
.markdown-message :deep(ol) {
    margin: 0.4rem 0 0.4rem 1.2rem;
}

.markdown-message :deep(li) {
    margin-bottom: 0.2rem;
}

.markdown-message :deep(code) {
    background-color: rgb(0 0 0 / 0.1);
    border-radius: 0.25rem;
    padding: 0.1rem 0.35rem;
    font-size: 0.85em;
    font-family: monospace;
}

.markdown-message :deep(pre) {
    background-color: rgb(0 0 0 / 0.12);
    border-radius: 0.5rem;
    padding: 0.75rem 1rem;
    margin: 0.5rem 0;
    overflow-x: auto;
    font-size: 0.85em;
}

.markdown-message :deep(pre code) {
    background: none;
    padding: 0;
}
</style>
