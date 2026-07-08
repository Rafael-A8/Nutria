# Tarefa: Remover Instruções Personalizadas

O recurso de "Instruções Personalizadas" (onde o usuário dizia como a IA deveria responder) não está mais sendo utilizado no agente e já foi removido de `NutritionistAgent.php`. Como resultado, os testes de validação no `CustomInstructionsTest.php` estão quebrando.

Sua missão é remover por completo os resquícios dessa funcionalidade do sistema.

## Passos para Execução:

1. **Testes**: Exclua o arquivo `tests/Feature/CustomInstructionsTest.php`, já que essa feature deixará de existir.
2. **Rotas**: No arquivo `routes/settings.php`, remova a rota `ai-model.update-instructions`.
3. **Controller**: No `App\Http\Controllers\Settings\AiModelController`:
   - Remova o método `updateCustomInstructions`.
   - No método `edit`, remova o repasse do dado `customInstructions` para o Vue.
4. **Interface (Frontend)**: Em `resources/js/pages/settings/AiModel.vue`, remova o trecho de código HTML/Vue referente à caixa de texto de "Instruções personalizadas", incluindo  todas as `props`, validações, `useForm` ou referências à rota `/settings/ai-model/instructions`.
5. **Model**: Em `app/Models/Profile.php`, remova a string `'custom_instructions'` do `#[Fillable( ... )]`.
6. **Banco de Dados**: 
   - A coluna `custom_instructions` foi adicionada na migration `2026_04_11_221429_add_custom_instructions_to_user_profiles_table.php` (ou similar). 
   - Opção A: Crie uma nova migration para remover a coluna (`dropColumn('custom_instructions')`), se a de origem não puder ser deletada.
   - Opção B: Se a base local estiver sendo reconstruída regularmente, você pode excluir a migration original (se não tiver ido para produção) e rodar `./vendor/bin/sail artisan migrate:refresh`. Prefira criar uma nova migration para `dropColumn` se houver dúvida.
7. Ao fim, rodar `./vendor/bin/sail artisan test` e confirmar que **todos os testes passam com sucesso**, usando Pint para formatar os arquivos se necessário (`./vendor/bin/sail bin pint --format agent`).

## Observação Importante
Não altere as opções dos modelos (Gemini e OpenAI) definidos no `AiModel.php`, pois isso já foi ajustado para exibir apenas o nome genérico. Foque apenas na parte de remover as Instruções Personalizadas.
