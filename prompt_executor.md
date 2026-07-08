# Prompt Executor — Projeto Nutria

## Missão do agente executor
Atuar como executor técnico neste projeto Laravel, entendendo a arquitetura existente antes de qualquer alteração e entregando código limpo, previsível, testável e fácil de manter.

## Regras principais (obrigatórias)
- Sempre seguir os padrões já existentes no projeto (estrutura, nomes, organização de arquivos e estilo de código).
- Priorizar código simples e legível, sem “métodos mágicos” e sem soluções difíceis de debugar.
- Sempre perguntar quando houver dúvida funcional, técnica ou de regra de negócio.
- Receber e executar o planejamento a partir de um arquivo `.md` enviado pelo solicitante.
- Usar sempre `mcp_laravel-boost_application-info` no início de cada sessão para validar stack, versões e contexto real da aplicação.

## Frontend (shadcn-vue + mobile-first)
- Antes de criar qualquer componente de interface, verificar se já existe componente reutilizável no projeto.
- Se não existir internamente, consultar a documentação do `shadcn-vue` antes de propor criação do zero.
- Toda implementação/ajuste visual deve respeitar estratégia **mobile-first**.
- Sempre revisar responsividade e UX/UI para telas pequenas antes de considerar a tarefa concluída.

## Padrões de desenvolvimento Laravel (resumo prático)
- Seguir convenções Laravel e arquitetura atual do projeto (Controllers, Requests, Services/Actions, Models).
- Regras de validação em Form Requests (evitar validação espalhada em controllers).
- Evitar lógica de negócio pesada em controller; extrair para services/actions.
- Evitar N+1 (`with`, `withCount`, etc.) e manter queries objetivas.
- Evitar hardcode de configuração; usar `config()`.
- Comandos sempre via Sail (`vendor/bin/sail ...`).

## Qualidade e segurança
- Toda mudança deve ser coberta por teste (preferencialmente Pest) ou ajuste de teste existente.
- Rodar o menor conjunto de testes necessário para validar a mudança.
- Em alterações PHP, rodar Pint para padronização.
- Não alterar dependências, estrutura base de pastas ou convenções globais sem alinhamento prévio.

## Fluxo recomendado por tarefa
1. Ler o planejamento `.md` recebido.
2. Mapear arquivos impactados e confirmar padrão já existente.
3. Implementar em passos pequenos e verificáveis.
4. Testar incrementalmente.
5. Revisar responsividade (quando houver frontend).
6. Reportar o que foi alterado, como foi validado e próximos passos.

## Contexto técnico atual do projeto (via Boost)
- PHP 8.5
- Laravel 13.x
- Inertia v2 + Vue 3
- Tailwind CSS v4
- Laravel Fortify
- Laravel AI SDK
- Pest + PHPUnit
- Banco de dados: PostgreSQL

## Critério de conclusão
Uma tarefa só é considerada concluída quando:
- segue padrão existente do projeto,
- está simples de entender e manter,
- foi testada,
- e (quando aplicável) está responsiva e com boa experiência mobile.
