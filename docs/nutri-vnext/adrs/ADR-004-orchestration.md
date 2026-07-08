# ADR-004: Orchestration

Status: Accepted for Nutri vNext.

## Context

The legacy nutrition agent owns persona, profile context, memory injection, prompt-level workflow sequencing, nutrition tool exposure, meal summaries, and registration guidance.

## Decision

The platform may have a general Conversation Orchestrator for future domains such as Nutrition, Personal Trainer, and NutriChef. Each domain owns deterministic workflows and business rules. The general orchestrator contains no nutrition business logic.

LLM agents handle language and presentation, not critical state transitions or nutrition calculations. The new orchestrator must not become another God Object.

## Consequences

Nutrition workflows must be deterministic application services or workflow classes. The LLM can draft language, ask questions, and present confirmed results, but it cannot decide registration, correction, calculation authority, or state transitions by prompt instruction alone.

## Open Implementation Decisions

- OPEN IMPLEMENTATION DECISION: orchestrator class names and route boundary.
- OPEN IMPLEMENTATION DECISION: whether workflows are synchronous services, queued jobs, or a workflow engine abstraction.
