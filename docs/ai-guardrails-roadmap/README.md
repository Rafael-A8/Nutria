# AI Guardrails Roadmap

Status geral: [ ] em andamento | [ ] finalizado

Esta task organiza os ajustes de seguranca, custo e foco de negocio dos agentes do Nutria. A abordagem e executar uma fase por vez, validar com testes e marcar o check da fase antes de seguir para a proxima.

## Objetivo

Deixar o agente protegido contra abuso de custo, perda de foco do dominio nutricional, chamadas desnecessarias de IA e respostas que saem das regras de negocio do produto.

## Regras de trabalho

- Executar apenas uma fase por vez.
- Antes de implementar uma fase, reler o arquivo da fase.
- Ao finalizar uma fase, marcar o checklist do arquivo correspondente.
- Toda fase com alteracao de codigo deve ter teste automatizado.
- Rodar Pint e testes focados antes de marcar a fase como concluida.
- Nao mudar o historico visual de `chat_messages` sem uma decisao explicita.
- Nao trocar modelos em massa sem medir impacto de custo e comportamento.

## Fases

- [ ] [Fase 01 - Model routing e custo previsivel](fase-01-model-routing-e-custo-previsivel.md)
- [ ] [Fase 02 - Registro de uso de IA](fase-02-registro-de-uso-de-ia.md)
- [ ] [Fase 03 - Limites de gasto por usuario](fase-03-limites-de-gasto-por-usuario.md)
- [ ] [Fase 04 - Guardrail de dominio do produto](fase-04-guardrail-de-dominio-do-produto.md)
- [ ] [Fase 05 - Guardrails clinicos, memoria e ferramentas](fase-05-guardrails-clinicos-memoria-e-ferramentas.md)
- [ ] [Fase 06 - Observabilidade e rotina de producao](fase-06-observabilidade-e-rotina-de-producao.md)

## Ordem recomendada

A ordem foi escolhida para reduzir risco operacional primeiro. Antes de sofisticar a resposta do agente, o projeto precisa saber quanto cada usuario esta gastando, conseguir bloquear abuso e impedir que o agente trabalhe fora do dominio.

## Definicao de pronto da task inteira

- Todas as chamadas relevantes de IA usam modelo/provider explicito.
- Existe registro de uso por usuario, agente, provider e modelo.
- Existe limite diario/mensal por usuario e limite global do projeto.
- O agente recusa ou redireciona pedidos fora do dominio nutricional.
- Regras medicas, memoria e ferramentas estao protegidas por testes.
- Existe uma forma simples de auditar custo e abuso em producao.
