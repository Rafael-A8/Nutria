# Fase 03 - Limites de gasto por usuario

Status: [ ] pendente | [ ] em andamento | [ ] finalizada

## Objetivo

Impedir que um usuario malicioso, bugado ou muito intenso consuma os creditos da API sem controle.

## Motivacao

Esse guardrail deve ser deterministico. Quem decide se pode chamar IA nao deve ser outro modelo, e sim uma regra de backend baseada em uso registrado.

## Escopo

- Criar politica de limite diario por usuario.
- Criar politica de limite mensal por usuario.
- Criar limite global diario do projeto.
- Criar cooldown para abuso de mensagens em curto periodo.
- Definir resposta amigavel quando o limite for atingido.
- Permitir limites maiores para admin/teste, se o projeto ja tiver esse conceito.

## Fora do escopo

- Pagamento ou plano comercial.
- Tela de configuracao de limites.
- Controle financeiro perfeito por centavo.

## Checklist

- [ ] Definir valores iniciais de limite para MVP.
- [ ] Criar service de quota/orcamento.
- [ ] Bloquear chamada antes de invocar IA quando limite for atingido.
- [ ] Registrar bloqueios por limite.
- [ ] Criar testes para limite diario.
- [ ] Criar testes para limite mensal.
- [ ] Criar testes para limite global.
- [ ] Rodar Pint.
- [ ] Rodar testes focados.
- [ ] Marcar fase como finalizada.

## Criterios de aceite

- Usuario acima do limite nao chama provider externo.
- O sistema retorna mensagem clara e curta.
- Admin/desenvolvimento consegue testar sem ficar travado por acidente.
- Existe log do bloqueio para auditoria.

## Testes esperados

- Usuario dentro do limite consegue chamar o agente.
- Usuario acima do limite nao chama o agente.
- Limite global bloqueia novas chamadas.
- Cooldown bloqueia rajada de mensagens.
