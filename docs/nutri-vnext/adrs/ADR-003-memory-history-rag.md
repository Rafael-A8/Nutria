# ADR-003: Memory, History, Recurrence, and RAG Separation

Status: Accepted for Nutri vNext.

## Context

The legacy system stores visible chat history, agent conversation history, user memories, meal history, summaries, and meal-item embeddings. These concepts currently interact in ways that can blur nutritional authority.

## Decision

Memory stores preferences and conversational context, not nutritional truth. Meal history stores events that occurred. Saved meal templates model recurring routines. RAG may locate records or candidates but has no nutritional authority.

Repeated meals use an exact source meal or an explicit saved template.

## Consequences

The system must keep conversation messages, memory, domain state, catalog knowledge, and audit history separate. Retrieval output cannot become a calorie source unless a deterministic workflow validates it against approved domain data.

## Open Implementation Decisions

- OPEN IMPLEMENTATION DECISION: exact template versioning UI and API.
- OPEN IMPLEMENTATION DECISION: how users select an exact prior meal versus a saved template in conversation.
