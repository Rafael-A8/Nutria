# Legacy Inventory

Status: M0.1 repository audit. This document records confirmed code behavior only unless explicitly marked as probable behavior or target behavior.

## Repository Areas Inspected

- Chat HTTP routes and controller entry points.
- AI agent instructions, tools, and middleware.
- Meal extraction, fallback parsing, ambiguity, estimation, guardrail, registration, summary, embedding, and memory services.
- Nutrition and AI configuration.
- Meal, chat, memory, and summary models and migrations.
- Chat Vue page and generated controller action usage.
- Feature tests covering agent prompts, parsing, estimation, registration, meal service embeddings, summaries, image/audio chat, conversation cycles, guardrails, and memory.
- Queue infrastructure. No app-owned nutrition jobs/events/listeners were found under `app/`; queue config exists in `config/queue.php:16-45` and queue tables exist in `database/migrations/0001_01_01_000002_create_jobs_table.php:14-45`.

## Current End-To-End Lifecycle

1. HTTP entry points are authenticated and verified chat routes in `routes/web.php:11-19`.
2. Text chat validates `message`, persists the user message, sends the prompt to the agent, persists the assistant reply, and returns JSON in `app/Http/Controllers/ChatController.php:43-54`.
3. Image chat stores uploaded images, persists a visible user message, and if no text is provided sends a prompt that asks the agent to analyze food, estimate calories, and register the meal in `app/Http/Controllers/ChatController.php:57-85`.
4. Audio chat stores the audio file, transcribes with `Transcription::fromUpload()`, persists the transcript, sends it to the agent, and returns reply plus transcription in `app/Http/Controllers/ChatController.php:88-109`.
5. `sendToAgent()` creates `NutritionistAgent`, reuses the current weekly agent conversation when possible, generates the previous cycle summary if needed, calls `forUser()` or `continue()`, and returns `reply` and `conversationId` in `app/Http/Controllers/ChatController.php:150-175`.
6. Conversation cycle reuse is selected from `agent_conversations` and reset if incomplete tool calls are detected in `app/Http/Controllers/ChatController.php:178-207`.
7. Visible chat persistence is separate from SDK conversation persistence through `ChatMessageService::storeUserMessage()`, `storeAssistantMessage()`, and `getHistory()` in `app/Services/ChatMessageService.php:15-43`.
8. Prompt assembly happens in `NutritionistAgent::instructions()`: profile data, latest weight, today calories, follow-up context, meal-tool sequencing, clinical rails, output format, and recent conversation summaries are assembled in `app/Ai/Agents/NutritionistAgent.php:93-222`.
9. The agent exposes guardrails and memory injection middleware in `app/Ai/Agents/NutritionistAgent.php:361-366`.
10. The agent exposes profile, meal parse, meal estimate, meal registration, summary, weight, similar item, period summary, and memory tools together in `app/Ai/Agents/NutritionistAgent.php:374-386`.
11. Guardrails run a prompt-injection classifier and cache strikes/blocks in `app/Ai/Middleware/Guardrails.php:27-85`.
12. Memory injection loads priority memories and vector-similar contextual memories, then appends them to the prompt in `app/Ai/Middleware/InjectMemories.php:24-55`.
13. `parse_meal_message` delegates to `MealExtractionService` and returns plain `items_text` lines in `app/Ai/Tools/ParseMealMessageTool.php:29-67`.
14. `MealExtractionService` first calls a structured anonymous agent, then falls back to `MealMessageParsingService` on failure in `app/Services/MealExtractionService.php:41-60`.
15. The structured extraction prompt explicitly says not to calculate calories in `app/Services/MealExtractionService.php:83-96`.
16. The deterministic fallback parser detects catalog aliases, quantities, preparation context, and composite total grams in `app/Services/MealMessageParsingService.php:43-153`, `app/Services/MealMessageParsingService.php:187-260`, and `app/Services/MealMessageParsingService.php:284-352`.
17. `get_similar_items` queries meal-item embeddings and returns previous item descriptions, grams, and calories as text in `app/Ai/Tools/GetSimilarItemsTool.php:46-65`.
18. `estimate_meal` normalizes plain text items, calls `MealEstimationService`, and returns registration status plus `items_for_registration_text` in `app/Ai/Tools/EstimateMealTool.php:32-85`.
19. `MealEstimationService::estimate()` loops through items, computes estimates, optionally invokes a structured AI fallback for low-confidence foods, and prepares item lines for registration in `app/Services/MealEstimationService.php:43-160`.
20. `MealEstimationService::estimateItem()` resolves a config reference, retrieves a best history match, resolves quantity, assesses ambiguity, and then estimates from preparation fat, history, config reference, or AI fallback in `app/Services/MealEstimationService.php:179-230`.
21. Confirmed current behavior: history is preferred over deterministic references because `estimateItem()` calls `estimateFromHistory()` before `estimateFromReference()` in `app/Services/MealEstimationService.php:217-222`.
22. Confirmed current behavior: historical calories are proportionally scaled in `estimateFromHistory()` in `app/Services/MealEstimationService.php:262-290`.
23. Confirmed current behavior: static config reference calories are calculated with `quantityGrams * calories_per_100g / 100` in `app/Services/MealEstimationService.php:297-338`.
24. Confirmed current behavior: the current reference resolver uses alias containment with the longest matching alias length in `app/Services/MealEstimationService.php:414-437`.
25. `register_meal` parses plain text item lines, validates basic guardrails, creates the meal, creates items, sums calories, and returns a registered status in `app/Ai/Tools/RegisterMealTool.php:57-103`.
26. `MealRegistrationGuardrailService` validates meal type, expected item count, pending count, positive calories, and consumed time in `app/Services/MealRegistrationGuardrailService.php:24-78`.
27. Meal persistence creates flat `meals` and `meal_items`; each item stores description, optional grams, calories, and later an embedding in `app/Services/MealService.php:15-39`.
28. Meal-item semantic history retrieval searches the current user's embedded meal items first, then other users' embedded meal items in `app/Services/MealService.php:47-73`.
29. Meal history summaries are event queries over persisted meals and item calories in `app/Services/MealService.php:79-155`.
30. Conversation summaries combine meal stats, weight stats, and user chat messages, then call an anonymous agent to generate an internal summary in `app/Services/SummaryService.php:33-101` and `app/Services/SummaryService.php:125-170`.
31. Memory persistence saves user-provided conversational context with embeddings in `app/Ai/Tools/SaveMemoryTool.php:44-83`.
32. Final user-visible output is only the agent response text persisted as a chat message and returned to the Vue client in `app/Http/Controllers/ChatController.php:52-54`, `app/Http/Controllers/ChatController.php:80-85`, and `app/Http/Controllers/ChatController.php:104-109`.

## Component Inventory

| Component | Lines | Current responsibility | Real responsibility | Direct dependencies and business rules | Duplicate rules | Target classification | Risk | Target phase |
|---|---|---|---|---|---|---|---|---|
| Chat routes | `routes/web.php:11-19` | Expose chat HTTP endpoints. | HTTP boundary for all nutrition conversation traffic. | Auth and verified middleware; routes for text, image, audio, media reads. | No. | Preserve/adapt. | Low. | M4 route to orchestrator. |
| `ChatController::sendMessage()` | `app/Http/Controllers/ChatController.php:43-54` | Persist message and call agent. | Starts nutrition workflow through a general agent and visible chat persistence. | `SendMessageRequest`, `ChatMessageService`, `NutritionistAgent`. | Message persistence overlaps SDK conversation persistence. | Adapt. | Medium: visible chat and SDK history can diverge. | M4. |
| `ChatController::sendImageMessage()` | `app/Http/Controllers/ChatController.php:57-85` | Store images and send prompt. | Lets prompt text ask the agent to estimate calories and register from image context. | `Files\Image`, storage, `sendToAgent()`. Business rule: default image prompt says estimate calories and register. | Duplicates nutrition flow trigger outside domain workflow. | Replace/adapt. | High: image-derived data is untrusted in target ADR-008. | M5 for label evidence, M4 for chat. |
| `ChatController::sendAudioMessage()` | `app/Http/Controllers/ChatController.php:88-109` | Transcribe audio and call agent. | Converts audio to text before the same agent workflow. | `Transcription::fromUpload()`, `ChatMessageService`. | No. | Preserve/adapt. | Medium: transcript still enters prompt-controlled flow. | M4. |
| `ChatController::sendToAgent()` and cycle helpers | `app/Http/Controllers/ChatController.php:150-207` | Choose current SDK conversation and prompt agent. | Conversation orchestration and incomplete-tool-call recovery. | `NutritionistAgent`, `SummaryService`, `agent_conversations`, `agent_conversation_messages`. | Duplicates orchestration concerns that target moves out of agent/controller. | Adapt. | Medium. | M4. |
| `ChatMessageService` | `app/Services/ChatMessageService.php:15-93` | Visible chat persistence and retrieval. | Stores user/assistant visible transcript and supplies follow-up signals. | `ChatMessage`, user relation, date queries. | Visible history duplicates SDK conversation tables. | Preserve/adapt. | Low for event history, medium for orchestration. | M4. |
| `NutritionistAgent` prompt | `app/Ai/Agents/NutritionistAgent.php:93-222` | Persona, context, and tool instructions. | God Agent prompt containing profile, summaries, follow-up context, meal workflow instructions, clinical rails, and output rules. | `MealService`, `WeightLogService`, `SummaryService`, `ChatMessageService`, profile data, cache. Business rules include parse-before-estimate, use similar items, estimate before register, no registration when blocked. | Duplicates tool descriptions and guardrail rules. | Replace/adapt. | High: prompt-controlled sequencing. | M4. |
| `NutritionistAgent::tools()` | `app/Ai/Agents/NutritionistAgent.php:374-386` | Expose all tools. | Lets one agent invoke nutrition, profile, memory, summary, history, and weight tools. | All tool classes. | Tool sequencing duplicated in prompt/tool descriptions. | Replace/adapt. | High: God Object/tool overexposure. | M0.2 remove history-decision tool; M4 orchestrator. |
| `Guardrails` middleware | `app/Ai/Middleware/Guardrails.php:27-85` | Prompt-injection classifier and cached blocking. | Security guardrail around prompts. | Anonymous agent, `Cache`, `Log`, `Auth`. | No nutrition duplicate. | Preserve/adapt. | Medium: classifier failure can block valid prompts but not nutrition authority issue. | M3 security hardening. |
| `InjectMemories` middleware | `app/Ai/Middleware/InjectMemories.php:24-135` | Inject user memories into prompt. | Mixes preference/context retrieval into the agent prompt. | `UserMemory`, `UserMemoryCategory`, vector similarity. Business rule: restrictions/goals priority, contextual memories by similarity. | Memory usage rules also live in prompt tests. | Preserve/adapt. | Medium: memory can influence wording; target forbids memory as nutrition truth. | M3/M4. |
| `ParseMealMessageTool` | `app/Ai/Tools/ParseMealMessageTool.php:29-79` | Tool wrapper for meal extraction. | Converts structured extraction into plain text lines for the next tool. | `MealExtractionService`. Business rule: if clarification required, do not estimate/register. | Duplicates agent sequencing instructions. | Replace/adapt. | High: fragile text contract. | M1. |
| `MealExtractionService` | `app/Services/MealExtractionService.php:41-218` | Structured meal fact extraction. | LLM extraction plus normalization and deterministic fallback. | Anonymous structured agent, `MealMessageParsingService`, date resolver. Business rule: no calories; no identifiable food triggers clarification. | Fallback parser has separate contract normalization. | Adapt. | High: LLM creates parse facts used downstream. | M1. |
| `MealMessageParsingService` | `app/Services/MealMessageParsingService.php:43-424` | Deterministic fallback parser. | Alias/quantity/context detector backed by static config references. | `config('nutrition.estimation.references')`. Business rules: composite meal clarification, alias overlap, negation, preparation context. | Reference resolution duplicated with `MealEstimationService::referenceFor()`. | Replace/adapt. | High: alias matching and quantity ownership can be wrong. | M1/M2. |
| `GetSimilarItemsTool` | `app/Ai/Tools/GetSimilarItemsTool.php:46-65` | Return similar historical items. | Exposes historical item calories to the agent as text before estimation. | `MealService::findSimilarItems()`. Business rule: previous item grams/calories are visible to LLM. | Duplicates history lookup inside `MealEstimationService`. | Remove/adapt. | High: semantic-history contamination. | M0.2 remove from nutrition decisions. |
| `EstimateMealTool` | `app/Ai/Tools/EstimateMealTool.php:32-212` | Estimate meal and format registration lines. | Bridges service estimates to prompt-visible text and registration text. | `MealEstimationService`. Business rules: registration allowed only when all items estimated and no pending items. | Duplicates guardrail count logic. | Replace/adapt. | High: validated estimate IDs absent. | M3. |
| `MealEstimationService::estimate()` | `app/Services/MealEstimationService.php:43-160` | Estimate all items. | Main nutrition calculation coordinator. | `MealService`, `MealAmbiguityService`, low-confidence AI fallback. Business rule: all-or-clarification flow, low-confidence fallback. | Duplicates registration readiness with `EstimateMealTool`. | Replace/adapt. | High: central legacy nutrition authority. | M0.2 containment, M3 replacement. |
| `MealEstimationService::estimateItem()` | `app/Services/MealEstimationService.php:179-230` | Estimate one item. | Combines reference lookup, semantic history, quantity defaulting, ambiguity, and source selection. | `referenceFor()`, `bestHistoryMatch()`, `resolveQuantityGrams()`, `MealAmbiguityService`. Business rule: history before reference. | Duplicates resolver/parser reference use. | Replace. | Critical: historical calorie reuse. | M0.2, M2/M3. |
| `MealEstimationService::estimateFromHistory()` | `app/Services/MealEstimationService.php:262-290` | Reuse historical item calories. | Scales historical calories by grams or reuses historical calories. | `MealItem`. Business rule: source `user_history`. | Duplicates `GetSimilarItemsTool` history output. | Remove from decisions. | Critical. | M0.2. |
| `MealEstimationService::estimateFromReference()` | `app/Services/MealEstimationService.php:297-338` | Calculate calories from config reference. | Deterministic static-reference calculation. | `config/nutrition.php`. Business rule: formula and default grams/calories. | Duplicated reference lookup in parser. | Adapt/replace. | Medium: static unversioned catalog. | M2/M3. |
| `MealEstimationService` AI fallback | `app/Services/MealEstimationService.php:476-620` | Estimate unknown foods via structured AI. | Non-catalog calorie source when deterministic reference/history fails. | Anonymous structured agent with `gpt-4o-mini`. Business rule: rough responsible estimates accepted into registration. | Duplicates target validation absent. | Replace/adapt. | High: LLM calorie authority. | M3. |
| `MealAmbiguityService` | `app/Services/MealAmbiguityService.php:58-119` | Decide clarification for ambiguous items. | Preparation-fat, vague-quantity, and low-confidence signal service. | String heuristics and flags from estimator. Business rule: high-impact cooking fat asks clarification only after quantity resolution. | Duplicates parser context detection. | Adapt/replace. | High for cooking-fat failures. | M0.2/M1. |
| `MealRegistrationGuardrailService` | `app/Services/MealRegistrationGuardrailService.php:24-78` | Basic registration validation. | Last gate before persistence. | Meal type list, expected/pending counts, positive calories, consumed_at. | Meal type list duplicated in prompt and schemas. | Replace/adapt. | High: no provenance/estimate validation. | M3. |
| `RegisterMealTool` | `app/Ai/Tools/RegisterMealTool.php:57-103` | Persist meal after estimation. | Accepts free calorie text from tool request and writes meal/items. | `MealRegistrationGuardrailService`, `MealService`. Business rule: sum text calories. | Duplicates count/calorie readiness with estimate tool. | Replace. | Critical against ADR-005. | M3. |
| `MealService::addItem()` | `app/Services/MealService.php:23-39` | Create meal item and embedding. | Persists flat item and semantic vector after registration. | `Embeddings::for()`, `MealItem`. Business rule: every item gets an embedding. | Embedding text normalization is local. | Adapt. | High: embeddings later used for decisions. | M0.2 disable decision use; M3/M5 persistence replacement. |
| `MealService::findSimilarItems()` | `app/Services/MealService.php:47-73` | Search similar meal items. | Semantic retrieval over user history and global other-user meal items. | `whereVectorSimilarTo`, `MealItem`. Business rule: user history first, then global history. | Used both directly by tool and internally by estimator. | Remove from nutrition decisions. | Critical: global historical calorie reuse. | M0.2. |
| `MealService` summaries | `app/Services/MealService.php:79-155` | Meal history summaries. | Event-query reporting over persisted meal events and calories. | User meals/items. Business rules: today/week/period totals, top items. | Used by tools and summaries. | Preserve/adapt. | Medium: totals use legacy flat calories until vNext components. | M4/M5. |
| `SummaryService` | `app/Services/SummaryService.php:33-170` | Weekly conversation summaries. | Summarizes previous cycle from meal stats, weights, and user messages. | `MealService`, `WeightLogService`, `ChatMessageService`, anonymous agent. | Agent also injects recent summaries. | Preserve/adapt. | Medium: summary stats reflect legacy calories. | M4. |
| `SaveMemoryTool` | `app/Ai/Tools/SaveMemoryTool.php:44-83` | Save user memory. | Stores preference/context memories with embeddings. | `UserMemory`, `Embeddings`, vector duplicate check. | Injected by `InjectMemories`. | Preserve/adapt. | Medium: memory must not become nutrition truth. | M3/M4. |
| `GetTodaySummaryTool` | `app/Ai/Tools/GetTodaySummaryTool.php:37-49` | Return today's calories by meal. | Event query tool over meal history. | `MealService::getTodaySummary()`. | Agent prompt already includes today summary. | Preserve/adapt. | Low if kept event-only. | M4. |
| `GetPeriodSummaryTool` | `app/Ai/Tools/GetPeriodSummaryTool.php:42-77` | Return period meal/weight summary. | Event query tool over history and weight logs. | `MealService`, `WeightLogService`. | SummaryService also uses period stats. | Preserve/adapt. | Low if kept event-only. | M4. |
| `config/nutrition.php` | `config/nutrition.php:3-620` | Static nutrition config. | Unversioned catalog, aliases, cooking-fat flags, measurement assumptions. | Used by parser and estimator. Butter is 717 kcal/100g at `config/nutrition.php:423-428`; tilapia is inside generic fish reference at `config/nutrition.php:149-154`. | Parser and estimator both read it. | Replace with catalog. | High: no versions/governance. | M2. |
| `config/ai.php` | `config/ai.php:16-21`, `config/ai.php:34-39`, `config/ai.php:52-127` | AI provider config. | Default provider selection, embedding cache setting, provider credentials. | AI SDK. | No. | Preserve/adapt. | Low. | Cross-cutting. |

## Persistence Inventory

| Area | Lines | Current responsibility | Real responsibility | Dependencies and business rules | Duplicate rules | Target classification | Risk | Phase |
|---|---|---|---|---|---|---|---|---|
| `Meal` model | `app/Models/Meal.php:11-31` | Persist a meal with `user_id`, `meal_type`, and `consumed_at`. | Flat meal event root. | Depends on `User` and `MealItem` relations; business rule is only one meal type string and timestamp. | Meal type validation is elsewhere. | Adapt. | Medium: no entries/components. | M1/M3. |
| `MealItem` model | `app/Models/MealItem.php:9-25` | Persist flat item description, grams, calories, and embedding cast. | Legacy component substitute. | Belongs to `Meal`; calories are stored as final scalar. | Duplicates target component and estimate concepts without provenance. | Replace/adapt. | Critical. | M3. |
| `ChatMessage` model | `app/Models/ChatMessage.php:9-25` | Persist visible chat role/content/media. | User-facing transcript. | Belongs to `User`; `image_paths` cast. | SDK conversation messages also persist prompt history. | Preserve/adapt. | Low. | M4. |
| `UserMemory` model | `app/Models/UserMemory.php:9-25` | Persist memory content/category/vector. | Preference/context memory store. | Belongs to `User`; embedding cast. | Memory injection rules live in middleware. | Preserve/adapt. | Medium: must not become nutrition truth. | M3/M4. |
| `UserConversationSummary` model | `app/Models/UserConversationSummary.php:11-44` | Persist generated summary, stats, period, and trigger. | Conversation-cycle context. | Belongs to `User`; enum casts; stats JSON. | Agent prompt also injects summaries. | Preserve/adapt. | Medium: stats include legacy calories. | M4. |
| `meals` migration | `database/migrations/2026_03_30_000003_create_meals_table.php:11-17` | Create flat meal table. | Legacy meal event table. | FK to users, `meal_type`, `consumed_at`. | No. | Adapt. | Medium. | M1/M3. |
| `meal_items` migration | `database/migrations/2026_04_02_000001_create_meal_items_table.php:12-21` | Create item table plus vector column. | Legacy component, estimate result, and embedding store combined. | FK to meals, scalar `calories`, optional grams, vector(1536). | Duplicates target `meal_components` and `nutrition_estimates`. | Replace/adapt. | Critical. | M0.2/M3. |
| `chat_messages` migrations | `database/migrations/2026_04_02_141721_create_chat_messages_table.php:14-23`, `database/migrations/2026_04_11_191823_add_image_paths_to_chat_messages_table.php:14-16` | Create visible transcript/media storage. | Conversation event log for UI. | User FK, role/content/media, user/date index. | SDK conversation tables duplicate internal conversation. | Preserve. | Low. | M4. |
| AI conversation migrations | `database/migrations/2026_03_31_114850_create_agent_conversations_table.php:14-39` | Create SDK conversations and tool message tables. | Internal agent conversation state, not nutrition domain state. | Stores tool calls/results but no domain validation. | Prompt/tool state overlaps pending-operation target. | Preserve/adapt. | Medium. | M4. |
| `user_memories` migration | `database/migrations/2026_05_21_225221_create_user_memories_table.php:11-19` | Create memories with vector. | Preference/context retrieval store. | User FK, category, vector(1536). | Memory category rules live in enum/tool/middleware. | Preserve/adapt. | Medium. | M3/M4. |
| Summary migration | `database/migrations/2026_06_05_224020_refactor_summaries_to_user_conversation_summaries_table.php:68-170` | Add conversation summary periods, triggers, and indexes. | Generated summary context table. | Unique and period indexes; not an audit log. | Summary generation logic in service. | Preserve/adapt. | Low. | M4/M5. |
| Queue infrastructure | `config/queue.php:16-45`, `database/migrations/0001_01_01_000002_create_jobs_table.php:14-45` | Configure queues and create queue tables. | Infrastructure only; no confirmed nutrition-specific jobs. | Database queue driver and jobs/batches/failed jobs tables. | No nutrition rules. | Preserve. | Low. | Future workflow implementation open. |

## API And UI Dependencies

| Surface | Lines | Dependency | Impact |
|---|---|---|---|
| Vue chat props | `resources/js/pages/Chat/Index.vue:29-57` | Receives `chatMessages` only. | No structured meal response dependency. |
| Text/image fetch | `resources/js/pages/Chat/Index.vue:177-270` | Expects JSON `reply`; image path replacement expects `imagePaths`. | Backend can change nutrition internals if response keys remain. |
| Audio fetch | `resources/js/pages/Chat/Index.vue:334-384` | Expects `reply` and `transcription`. | Backend can change nutrition internals if response keys remain. |
| Media display | `resources/js/pages/Chat/Index.vue:396-407`, `resources/js/pages/Chat/Index.vue:427-515` | Uses chat message media paths. | Domain migration should preserve visible media access. |
| Request validation | `app/Http/Requests/SendMessageRequest.php:14-18`, `app/Http/Requests/SendImageMessageRequest.php:14-20`, `app/Http/Requests/SendAudioMessageRequest.php:14-18` | Chat message, image, audio validation only. | No current structured meal API request. |

## Test Inventory

| Test file | Lines | Current behavior protected | Migration note |
|---|---|---|---|
| `tests/Feature/MealEstimationServiceTest.php` | `tests/Feature/MealEstimationServiceTest.php:10-40` | Deterministic config-reference calculation. | Keep invariant but move to versioned catalog. |
| `tests/Feature/MealEstimationServiceTest.php` | `tests/Feature/MealEstimationServiceTest.php:43-87` | Cooking-fat clarification/retention for some cases. | Extend for tilapia/butter unknown quantity. |
| `tests/Feature/MealEstimationServiceTest.php` | `tests/Feature/MealEstimationServiceTest.php:89-115` | Historical calorie scaling is expected. | Must change in M0.2. |
| `tests/Feature/MealEstimationServiceTest.php` | `tests/Feature/MealEstimationServiceTest.php:135-239` | AI fallback can estimate unknown foods before registration. | Must be replaced by validated estimate workflow in M3. |
| `tests/Feature/MealMessageParsingServiceTest.php` | `tests/Feature/MealMessageParsingServiceTest.php:5-81` | Fallback parsing, preparation context, composite meal clarification. | Add tilapia/butter parser regression in M0.2. |
| `tests/Feature/MealExtractionServiceTest.php` | `tests/Feature/MealExtractionServiceTest.php:7-91` | Structured extraction and fallback parser contract. | Main/fallback shared contract remains required. |
| `tests/Feature/EstimateMealToolTest.php` | `tests/Feature/EstimateMealToolTest.php:8-147` | Tool JSON and plain text registration line contract. | Replace with estimate identifiers in M3. |
| `tests/Feature/Ai/Tools/RegisterMealToolTest.php` | `tests/Feature/Ai/Tools/RegisterMealToolTest.php:8-98` | Plain text calorie lines can register after basic guardrail. | Replace with validated estimate IDs in M3. |
| `tests/Feature/MealServiceTest.php` | `tests/Feature/MealServiceTest.php:35-90` | Meal item embeddings are generated and searched. | M0.2 should stop using them for nutrition decisions; deletion waits for migration. |
| `tests/Feature/MealServiceTest.php` | `tests/Feature/MealServiceTest.php:93-202` | Event summary totals. | Preserve as history queries, later based on active validated components. |
| `tests/Feature/Ai/Tools/GetSimilarItemsToolTest.php` | `tests/Feature/Ai/Tools/GetSimilarItemsToolTest.php:8-28` | Similar history tool returns item calories. | Remove from nutrition decisions in M0.2. |
| `tests/Feature/NutritionistAgentTest.php` | `tests/Feature/NutritionistAgentTest.php:353-377` | Agent prompt sequencing and estimate-before-register instructions. | Replace prompt-controlled sequencing with workflows. |
| `tests/Feature/ConversationCycleTest.php` | `tests/Feature/ConversationCycleTest.php:12-96`, `tests/Feature/ConversationCycleTest.php:98-166` | Weekly SDK conversation reuse and incomplete tool recovery. | Replace/adapt with pending operation state. |
| `tests/Feature/GuardrailsMiddlewareTest.php` | `tests/Feature/GuardrailsMiddlewareTest.php:11-102` | Prompt-injection guardrail cache behavior. | Preserve as safety layer, not nutrition authority. |

## Dependency And Deletion Map

| Legacy class/area | Current dependents | Cannot remove until |
|---|---|---|
| `NutritionistAgent` | `ChatController::sendToAgent()` at `app/Http/Controllers/ChatController.php:150-175`; tests at `tests/Feature/NutritionistAgentTest.php:20-350`, `tests/Feature/ConversationCycleTest.php:12-166`, `tests/Feature/GuardrailsMiddlewareTest.php:11-102`. | A Conversation Orchestrator routes chat to deterministic nutrition workflows and tests are rewritten. |
| `ParseMealMessageTool` | Agent tool list at `app/Ai/Agents/NutritionistAgent.php:374-386`; tests at `tests/Feature/ParseMealMessageToolTest.php:7-89`. | `InterpretationDraft` workflow replaces tool text contract. |
| `EstimateMealTool` | Agent tool list at `app/Ai/Agents/NutritionistAgent.php:374-386`; tests at `tests/Feature/EstimateMealToolTest.php:8-147`. | Structured estimate workflow returns validated estimate IDs. |
| `RegisterMealTool` | Agent tool list at `app/Ai/Agents/NutritionistAgent.php:374-386`; `NutritionistAgentTest` at `tests/Feature/NutritionistAgentTest.php:521-527`; registration tests at `tests/Feature/Ai/Tools/RegisterMealToolTest.php:8-98`. | Registration workflow accepts validated estimate IDs and persists components transactionally. |
| `MealEstimationService` | `EstimateMealTool` at `app/Ai/Tools/EstimateMealTool.php:37-41`; tests at `tests/Feature/MealEstimationServiceTest.php:10-321`. | Catalog resolver, `NutritionEstimate`, validation, and registration boundaries exist. |
| `MealExtractionService` | `ParseMealMessageTool` at `app/Ai/Tools/ParseMealMessageTool.php:31-34`; tests at `tests/Feature/MealExtractionServiceTest.php:7-91`. | Interpretation workflow and fallback parser contract replace it. |
| `MealMessageParsingService` | `MealExtractionService` fallback at `app/Services/MealExtractionService.php:16-20`, `app/Services/MealExtractionService.php:55-59`; parser tests at `tests/Feature/MealMessageParsingServiceTest.php:5-81`. | Deterministic fallback parser emits target `InterpretationDraft`. |
| `MealAmbiguityService` | `MealEstimationService` constructor and use at `app/Services/MealEstimationService.php:17-23`, `app/Services/MealEstimationService.php:194-202`; tests at `tests/Feature/MealAmbiguityServiceTest.php:6-55`. | Validation/clarification workflow owns ambiguity states. |
| `MealRegistrationGuardrailService` | `RegisterMealTool` at `app/Ai/Tools/RegisterMealTool.php:15-20`, `app/Ai/Tools/RegisterMealTool.php:59-76`; registration tests at `tests/Feature/Ai/Tools/RegisterMealToolTest.php:39-98`. | Full estimate validation and operation validation exist. |
| `MealService` | Registration tool at `app/Ai/Tools/RegisterMealTool.php:78-90`; agent context at `app/Ai/Agents/NutritionistAgent.php:228-252`, `app/Ai/Agents/NutritionistAgent.php:264-306`; summary tools at `app/Ai/Tools/GetTodaySummaryTool.php:37-49`, `app/Ai/Tools/GetPeriodSummaryTool.php:42-77`; `SummaryService` at `app/Services/SummaryService.php:24-28`, `app/Services/SummaryService.php:51-58`; tests at `tests/Feature/MealServiceTest.php:14-202`. | Persistence, summaries, and history queries are split into canonical write services and event query services. |
| Semantic-history methods | `MealEstimationService::bestHistoryMatch()` at `app/Services/MealEstimationService.php:671-675`; `GetSimilarItemsTool` at `app/Ai/Tools/GetSimilarItemsTool.php:46-65`; `MealService::findSimilarItems()` at `app/Services/MealService.php:47-73`. | M0.2 disables nutrition-decision use; deletion waits until no history query or embedding migration depends on them. |
| Meal-item embeddings | Migration at `database/migrations/2026_04_02_000001_create_meal_items_table.php:21`; model cast at `app/Models/MealItem.php:15-19`; creation at `app/Services/MealService.php:31-36`; search at `app/Services/MealService.php:47-73`; tests at `tests/Feature/MealServiceTest.php:35-90`. | No workflows, tests, or migration compatibility require legacy item vectors. |
| `config/nutrition.php` | Parser references at `app/Services/MealMessageParsingService.php:390-399`; estimator references at `app/Services/MealEstimationService.php:648-657`; tests override config at `tests/Feature/MealEstimationServiceTest.php:117-132`. | Persisted versioned catalog is seeded and resolver uses active reviewed versions. |

## Confirmed Legacy Risks

- Confirmed current behavior: prompt instructions control parse, similar-history lookup, estimate, and registration sequencing in `app/Ai/Agents/NutritionistAgent.php:168-178`.
- Confirmed current behavior: the same agent exposes nutrition, profile, summaries, weight, memory, and history tools in `app/Ai/Agents/NutritionistAgent.php:374-386`.
- Confirmed current behavior: historical items can override static references because `estimateFromHistory()` is called before `estimateFromReference()` in `app/Services/MealEstimationService.php:217-222`.
- Confirmed current behavior: historical calories are scaled proportionally in `app/Services/MealEstimationService.php:270-273`.
- Confirmed current behavior: global other-user meal items are included after user items in semantic history search in `app/Services/MealService.php:65-73`.
- Confirmed current behavior: `GetSimilarItemsTool` exposes previous item calories to the LLM in `app/Ai/Tools/GetSimilarItemsTool.php:46-65`.
- Confirmed current behavior: registration persists free calorie values parsed from plain text item lines in `app/Ai/Tools/RegisterMealTool.php:83-91`.
- Confirmed current behavior: persisted `meal_items` lack source, reference version, formula, confidence, assumptions, and validation state in `database/migrations/2026_04_02_000001_create_meal_items_table.php:12-21`.
- Confirmed current behavior: the static reference for butter is 717 kcal/100g and marked as cooking fat in `config/nutrition.php:423-428`.
- Confirmed current behavior: tilapia aliases resolve through the generic `peixe grelhado` config entry in `config/nutrition.php:149-154`.

## Probable Behaviors

- Probable behavior: if structured extraction emits the wrong component/quantity pairing, downstream estimation trusts that pairing because `EstimateMealTool` normalizes tool item lines and passes them directly to `MealEstimationService` in `app/Ai/Tools/EstimateMealTool.php:32-41`.
- Probable behavior: meal summaries and conversation summaries can preserve incorrect calories after registration because they sum persisted `meal_items.calories` in `app/Services/MealService.php:79-155`.

## Approved Target Discrepancies

- Target behavior: meal history is never a nutrition reference. Current code uses `MealItem` history as source `user_history` in `app/Services/MealEstimationService.php:262-290`.
- Target behavior: reusable nutrition values come from a curated, persisted, versioned catalog. Current values live in static `config/nutrition.php:3-620`.
- Target behavior: registration accepts validated estimate identifiers. Current registration accepts plain text `calories=...` lines in `app/Ai/Tools/RegisterMealTool.php:42-44`, `app/Ai/Tools/RegisterMealTool.php:57-103`.
- Target behavior: LLM agents handle language, not critical transitions. Current prompt tells the agent when to parse, estimate, call history, register, and ask clarification in `app/Ai/Agents/NutritionistAgent.php:168-178`.
- Target behavior: memory stores context, not nutritional truth. Current memory is injected into the prompt in `app/Ai/Middleware/InjectMemories.php:90-123`; this is acceptable only if workflows ignore memory as calculation authority.
- Target behavior: corrections are versioned operations. No correction workflow or estimate supersession persistence exists in the inspected nutrition code.

## Unresolved Implementation Decisions

- OPEN IMPLEMENTATION DECISION: exact compatibility strategy for existing `meals` and `meal_items` reads during migration.
- OPEN IMPLEMENTATION DECISION: whether legacy `meal_items.embedding` is retained for event recall after M0.2 or retired with a data migration later.
- OPEN IMPLEMENTATION DECISION: target workflow execution mechanism and operation identifier format.
- OPEN IMPLEMENTATION DECISION: catalog seed source and editorial review process.
- OPEN IMPLEMENTATION DECISION: user-facing behavior when a mixed meal has some validated components and some pending components.
