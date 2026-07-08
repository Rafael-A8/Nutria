# Fase 04 - Guardrail de dominio do produto

Status: [ ] pendente | [ ] em andamento | [ ] finalizada

## Objetivo

Evitar que o agente vire um assistente generico e saia do dominio do Nutria.

## Motivacao

O produto e um agente nutricional. Ele pode falar de alimentacao, refeicoes, metas, sintomas relacionados a comida, habitos e organizacao nutricional. Ele nao deve gastar token ensinando JavaScript, escrevendo contratos ou resolvendo assuntos fora do produto.

## Escopo

- Criar classificador de dominio antes do agente principal.
- Classificar mensagens como:
  - `nutrition_allowed`;
  - `nutrition_adjacent`;
  - `medical_risk`;
  - `unrelated`;
  - `prompt_injection`.
- Bloquear ou redirecionar `unrelated`.
- Encaminhar `prompt_injection` para o guardrail atual.
- Permitir casos nutricionais indiretos, como receita mais leve, substituicoes e planejamento alimentar.

## Fora do escopo

- Moderacao completa de conteudo.
- Diagnostico medico.
- Criar FAQ ou base RAG.

## Checklist

- [ ] Definir categorias e exemplos.
- [ ] Criar classificador barato com structured output.
- [ ] Criar resposta padrao para assunto fora do dominio.
- [ ] Garantir que pedidos nutricionais indiretos nao sejam bloqueados.
- [ ] Criar testes para receita, JavaScript, prompt injection e sintomas.
- [ ] Rodar Pint.
- [ ] Rodar testes focados.
- [ ] Marcar fase como finalizada.

## Criterios de aceite

- Pedido claramente fora do dominio nao chama o agente principal.
- Pedido relacionado a nutricao continua funcionando.
- O usuario recebe redirecionamento educado, nao uma resposta fria.

## Testes esperados

- "Me ensina JavaScript" e bloqueado/redirecionado.
- "Me passa uma receita de bolo menos calorica" e permitido.
- "Estou com diarreia depois de leite" e permitido como risco/saude alimentar, sem diagnostico.
- Prompt injection continua bloqueado.
