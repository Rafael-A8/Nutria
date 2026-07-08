# Fase 05 - Guardrails clinicos, memoria e ferramentas

Status: [ ] pendente | [ ] em andamento | [ ] finalizada

## Objetivo

Fortalecer regras sensiveis que nao podem depender apenas da boa vontade do modelo.

## Motivacao

Um agente nutricional precisa parecer humano, mas tambem precisa ter limites claros. Ele nao deve diagnosticar, salvar memoria inutil, registrar refeicao parcial ou afirmar que fez algo quando uma ferramenta bloqueou.

## Escopo

- Revisar rails clinicos.
- Revisar quando salvar memoria.
- Garantir que restricoes e objetivos importantes sejam tratados como sinais fortes.
- Criar guardrails para respostas apos falha de tool.
- Revisar limite de tool calls e comportamento de loop.
- Garantir que registro de refeicao continua bloqueado quando existem pendencias.

## Fora do escopo

- Prontuario medico.
- Recomendacao clinica avancada.
- Integracao com profissional humano.

## Checklist

- [ ] Revisar prompt clinico do agente principal.
- [ ] Revisar regras de `SaveMemoryTool`.
- [ ] Criar testes para memoria util e memoria inutil.
- [ ] Criar testes para sintomas com restricao alimentar conhecida.
- [ ] Criar testes para falha/bloqueio de ferramenta.
- [ ] Revisar max steps e loops.
- [ ] Rodar Pint.
- [ ] Rodar testes focados.
- [ ] Marcar fase como finalizada.

## Criterios de aceite

- O agente nao diagnostica nem promete tratamento.
- Memorias salvas sao realmente uteis para acompanhamento nutricional.
- Falha de ferramenta nao vira resposta falsa de sucesso.
- Restricoes alimentares e objetivos fortes aparecem quando relevantes.

## Testes esperados

- Intolerancia a lactose + sintoma gera alerta calmo e pratico.
- Objetivo importante e recuperado quando a conversa pede.
- Pedido irrelevante nao vira memoria.
- Registro bloqueado nao gera "refeicao registrada".
