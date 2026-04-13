<script setup lang="ts">
import DOMPurify from 'dompurify';
import { marked } from 'marked';
import { computed } from 'vue';

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
    line-height: 1.75;
}

.markdown-message :deep(p) {
    margin-bottom: 0.75rem;
}

.markdown-message :deep(p:last-child) {
    margin-bottom: 0;
}

.markdown-message :deep(strong) {
    font-weight: 600;
}

.markdown-message :deep(ul),
.markdown-message :deep(ol) {
    margin: 0.5rem 0 0.5rem 1.25rem;
}

.markdown-message :deep(li) {
    margin-bottom: 0.25rem;
}

.markdown-message :deep(li::marker) {
    color: var(--muted-foreground);
}

.markdown-message :deep(code) {
    background-color: var(--muted);
    border-radius: 0.25rem;
    padding: 0.15rem 0.4rem;
    font-size: 0.85em;
    font-family:
        ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.markdown-message :deep(pre) {
    background-color: var(--muted);
    border-radius: 0.5rem;
    padding: 0.875rem 1rem;
    margin: 0.75rem 0;
    overflow-x: auto;
    font-size: 0.85em;
}

.markdown-message :deep(pre code) {
    background: none;
    padding: 0;
}

.markdown-message :deep(blockquote) {
    border-left: 3px solid var(--border);
    padding-left: 0.75rem;
    margin: 0.5rem 0;
    color: var(--muted-foreground);
}

.markdown-message :deep(a) {
    color: var(--primary);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.markdown-message :deep(h1),
.markdown-message :deep(h2),
.markdown-message :deep(h3) {
    font-weight: 600;
    margin-top: 1rem;
    margin-bottom: 0.5rem;
}

.markdown-message :deep(hr) {
    border-color: var(--border);
    margin: 0.75rem 0;
}
</style>
