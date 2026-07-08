# Plan: Implement Prompt Injection Guardrails

## Context
A abordagem de usar o Cache do Laravel com um tempo de expiração (TTL) será utilizada para garantir segurança e performance, evitando a criação de tabelas e não sujando o banco de dados. As recusas ao usuário serão feitas através do lançamento de `Exception` nativa, para aproveitar a captura efêmera do frontend/Inertia (que mostra a notificação na tela, mas não salva o erro no histórico do chat).

## Steps

1. **Criar o middleware:** Executar o comando: `php artisan make:agent-middleware Guardrails` (usar Sail: `./vendor/bin/sail artisan make:agent-middleware Guardrails`).

2. **Implementar a lógica:** No arquivo `app/Ai/Middleware/Guardrails.php`:
   - Verificar primeiro se o usuário (`auth()->id()`) está atualmente bloqueado no Cache (ex: chave `guardrails_blocked_{id}`). Se sim, lançar imediatanente a exceção: *"Sua conta foi bloqueada temporariamente. Procure a administração do sistema."*
   - Configurar um Agente Anônimo (`agent()`) forçando o modelo `gpt-4o-mini`.
   - Utilizar **Structured Output** solicitando um schema apenas com o booleano `is_injection` para avaliar o texto que vem em `$prompt->prompt`.

3. **Fluxo de injeção detectada (`is_injection == true`):**
   - Registrar no log do sistema de forma silenciosa: `Log::warning('Tentativa de Prompt Injection', ['user_id' => auth()->id(), 'prompt' => $prompt->prompt]);`
   - Incrementar as tentativas do usuário no cache (chave `guardrails_strikes_{id}`). Configurar essa chave para expirar em **1 hora**.
   - Se o contador atingir o limite (3 vezes):
     - Salvar o marcador de bloqueio no Cache por **24 horas** (chave `guardrails_blocked_{id}`).
     - Lançar a `\Exception` com a mensagem pesada: *"Sua conta foi bloqueada temporariamente. Procure a administração do sistema."*
   - Se ainda não bateu o limite (1 ou 2), lançar apenas a `\Exception` genérica: *"Sua solicitação não pode ser processada"*.

4. **Fluxo Seguro (`is_injection == false`):**
   - Limpar o cache de tentativas recentes (opcional).
   - Seguir para o agente real usando `return $next($prompt);`.

5. **Registrar o Guardrail:** 
   - No arquivo `app/Ai/Agents/NutritionistAgent.php`.
   - Garantir que a classe implemente a interface `Laravel\Ai\Contracts\HasMiddleware`.
   - Adicionar o novo middleware `Guardrails::class` dentro do método `middleware()`.

## Relevant files
- `app/Ai/Middleware/Guardrails.php` — Responsável pela verificação, logs e cache.
- `app/Ai/Agents/NutritionistAgent.php` — Onde a barreira será associada na pipeline.

## Decisions
- **Nome do middleware:** Será `Guardrails`.
- **Semântica:** Usaremos o comparador `==` ao invés de `===` para manter o código mais fluido e menos mecânico.
- **Castigos (Strikes) via Cache:** Falhas expiram sozinhas em 1 hora. Bloqueio total do bot tem punição de 24 horas. Controle 100% via temporizador no Cache.
- **Interrupções:** O bloqueio da pipeline da IA não responderá com texto de modelo gerado, mas interronperá via `throw new \Exception(...)` para exibir a mensagem e não consumir logs no banco de dados.
