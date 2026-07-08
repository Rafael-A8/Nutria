# Fase 02 - Registro de uso de IA

Status: [ ] pendente | [ ] em andamento | [ ] finalizada

## Objetivo

Registrar o uso de IA por usuario, agente, provider, modelo e contexto da chamada, para dar base real aos limites de custo e auditoria.

## Motivacao

Sem registro de uso, qualquer limite vira chute. O projeto precisa saber quem consumiu, quando consumiu, qual agente consumiu, qual modelo foi usado e, se disponivel, quantos tokens foram gastos.

## Escopo

- Criar uma tabela de uso de IA.
- Registrar chamadas do agente principal e dos agentes internos.
- Salvar pelo menos:
  - `user_id`;
  - `agent_name` ou `operation`;
  - `provider`;
  - `model`;
  - `conversation_id` quando existir;
  - tokens de entrada, saida e total quando o SDK retornar;
  - custo estimado quando houver tabela interna de precos;
  - status da chamada;
  - motivo de falha quando falhar.
- Definir um servico unico para registrar uso.

## Fora do escopo

- Bloquear usuarios.
- Criar tela administrativa.
- Fazer billing real.

## Checklist

- [ ] Inspecionar se o Laravel AI SDK expõe eventos ou metadados de uso.
- [ ] Criar migration/model para registro de uso.
- [ ] Criar service de logging de uso.
- [ ] Integrar nas chamadas principais de IA.
- [ ] Cobrir sucesso e falha com testes.
- [ ] Rodar migration em ambiente local.
- [ ] Rodar Pint.
- [ ] Rodar testes focados.
- [ ] Marcar fase como finalizada.

## Criterios de aceite

- Cada chamada relevante de IA gera um registro audivel.
- Falhas tambem ficam registradas.
- O registro nao quebra a conversa se o logging falhar.

## Testes esperados

- Teste garantindo que uma chamada do agente principal registra uso.
- Teste garantindo que uma chamada interna registra uso.
- Teste garantindo que falha de logging nao impede resposta do agente.
