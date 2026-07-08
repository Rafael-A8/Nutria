# Fase 06 - Observabilidade e rotina de producao

Status: [ ] pendente | [ ] em andamento | [ ] finalizada

## Objetivo

Criar uma rotina simples para acompanhar custo, abuso, falhas e qualidade do agente em producao.

## Motivacao

Guardrails sem observabilidade viram sensacao. O projeto precisa mostrar rapidamente quando um usuario esta abusando, quando um provider esta falhando ou quando o agente esta pedindo clarificacao demais.

## Escopo

- Criar consultas ou comandos para auditar uso.
- Registrar metricas importantes:
  - chamadas por usuario;
  - custo estimado por usuario;
  - bloqueios por limite;
  - bloqueios por dominio;
  - falhas de provider;
  - quantidade de clarificacoes;
  - refeicoes bloqueadas por guardrail.
- Criar alerta simples por log ou notificacao quando limite global estiver perto.
- Documentar rotina manual de verificacao.

## Fora do escopo

- Dashboard completo.
- BI.
- Sistema de billing comercial.

## Checklist

- [ ] Definir metricas minimas.
- [ ] Criar comando ou consulta para resumo diario.
- [ ] Criar alerta simples para limite global.
- [ ] Documentar rotina de verificacao.
- [ ] Criar testes do comando/consulta quando aplicavel.
- [ ] Rodar Pint.
- [ ] Rodar testes focados.
- [ ] Marcar fase como finalizada.

## Criterios de aceite

- E possivel saber quanto cada usuario consumiu.
- E possivel ver bloqueios por abuso ou dominio.
- E possivel identificar falha recorrente de provider.
- Existe uma rotina simples para olhar producao sem depender de adivinhacao.

## Testes esperados

- Comando retorna resumo diario.
- Registros bloqueados aparecem no resumo.
- Falhas de provider aparecem no resumo.
