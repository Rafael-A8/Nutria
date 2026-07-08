# Tarefa: Corrigir SaveMemoryTool e Recuperação de Memórias

## Descrição do Problema
Durante a execução de testes, foram encontrados erros na implementação da `SaveMemoryTool` e suspeita-se que o Agente Nutricionista (`NutritionistAgent`) não está conseguindo recuperar corretamente as memórias salvas.

### 1. Erro na SaveMemoryTool
O teste `Tests\Feature\Ai\Tools\SaveMemoryToolTest` está falhando com o seguinte erro:
```
FAILED  Tests\Feature\Ai\Tools\SaveMemoryToolTest > SaveMemoryTool does not save identical memory
Failed asserting that two strings are identical.
-'memory_already_exists'
+'memory_saved'
```
**Análise:** Ao invés da ferramenta identificar que uma memória idêntica já existe no banco e retornar `memory_already_exists`, ela está gravando novamente e retornando `memory_saved`. É necessário verificar a lógica que procura por duplicatas no arquivo `app/Ai/Tools/SaveMemoryTool.php`.

### 2. Recuperação de Memória pelo NutritionistAgent
Há indícios nos resultados dos testes e na experimentação de que o agente não está sendo capaz de utilizar memórias corretamente. É necessário investigar como as memórias estão sendo carregadas no contexto do Agente e se o system prompt ou a lógica de injeção de contexto está processando isso como esperado.

## Passos para Resolução:
1. **Investigar `SaveMemoryTool`:**
   - Acesse `app/Ai/Tools/SaveMemoryTool.php`.
   - Modifique a lógica do método responsável pelo salvamento para garantir que ele faça uma checagem (provavelmente baseada no conteúdo ou no `user_id`) antes de criar um novo registro no banco de dados.
   - Rode o teste correspondente para validar: `vendor/bin/sail artisan test --filter SaveMemoryToolTest`

2. **Revisar a Recuperação de Memória:**
   - Verifique `app/Ai/Agents/NutritionistAgent.php` e serviços relacionados ou o model `UserMemory`.
   - Analise se as memórias estão sendo enviadas no array de instruções ou mensagens que vai para o provider LLM.
   - Faça ajustes no carregamento do histórico e nas instruções, se aplicável, e rode a suíte de testes de agente.

3. **Verificar os demais testes afetados:**
   - Execute o comando: `vendor/bin/sail artisan test` e assegure-se de que os testes envolvidos em Memória e Agentes passem adequadamente.
