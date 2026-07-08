# Fase 01 - Model routing e custo previsivel

Status: [ ] pendente | [ ] em andamento | [x] finalizada

## Objetivo

Garantir que cada chamada de IA use provider e modelo explicitos, evitando que uma chamada interna caia sem querer em um modelo caro do SDK.

## Motivacao

O agente principal pode continuar testando Gemini 3.5 Flash, porque ele e responsavel por tom, conversa e uso de ferramentas. Ja os agentes internos devem ser baratos e previsiveis, pois fazem tarefas pequenas como classificacao, extracao, resumo e fallback.

## Escopo

- Revisar todas as chamadas `agent(...)`.
- Manter `NutritionistAgent` configuravel por usuario.
- Manter guardrails internos em modelo economico.
- Definir modelo explicito no `SummaryService`.
- Se fizer sentido, criar uma configuracao unica para modelos internos, evitando strings soltas duplicadas.

## Fora do escopo

- Criar novas tabelas de uso.
- Bloquear usuarios por custo.
- Trocar o modelo principal para todos os usuarios.

## Checklist

- [x] Mapear chamadas atuais de IA.
- [x] Definir modelo explicito no resumo semanal.
- [x] Confirmar que extração e estimativa continuam usando modelo barato.
- [x] Atualizar ou criar testes cobrindo provider/model esperado.
- [x] Rodar Pint.
- [x] Rodar testes focados.
- [x] Marcar fase como finalizada.

## Criterios de aceite

- Nenhuma chamada interna importante fica dependente do default invisivel do SDK.
- O teste garante que o `SummaryService` nao usa um modelo caro por acidente.
- O comportamento do agente principal nao muda para usuarios que escolheram Gemini ou OpenAI.

## Testes esperados

- Teste do `SummaryService` verificando provider/model da chamada interna.
- Testes existentes do `NutritionistAgent` continuam passando.
- Testes de meal extraction e meal estimation continuam passando.
