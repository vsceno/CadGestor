# CadGestor (versão 2: Laravel)

Sistema de controle de acesso por digital e reconhecimento facial, com LGPD.
Esta é a nova base em **Laravel 13 + Blade + Tailwind CSS 4 (Vite)**. A versão
anterior, em PHP próprio, está em [`legado/`](legado/) como referência das
regras de negócio, do banco (RLS, LGPD) e dos testes de segurança.

**Situação:** Fase 0 do adendo 4A de layout e UX (design system). Ainda não há
telas de negócio.

## Decisões de produto (adendo 4A)

- **Catracas:** cada cliente tem as próprias catracas, uma ou mais. Não existe o conceito de "unidade". Onde o adendo fala em unidade, vale catraca (ex.: colunas da matriz de regras).
- **Limite do plano:** quem passa do limite é **notificado** para se ajustar, ou seja, para trocar de plano.
- **Clientes não são excluídos**, só desativados. Nada é apagado e o cliente pode ser reativado.
- **Plataforma:** não tem catracas e não vê dispositivos do cliente. Só controla os clientes (dados, plano e cobrança).
- **Para depois:** chamados, financeiro, fluxo de caixa e NFS-e.

## Como rodar (desenvolvimento)

Pré-requisitos: PHP 8.5, Composer, Node 20 ou superior.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build          # ou "npm run dev" para recarregar ao editar
php artisan serve      # http://127.0.0.1:8000
```

A vitrine do design system fica em **http://127.0.0.1:8000/design-system**. Essa
página não existe em produção.

Testes e padrão de código:

```bash
php artisan test --compact
vendor/bin/pint --format agent
```

## Design system

Toda tela é montada com os componentes abaixo. **Nenhuma tela tem estilo
próprio**: se faltar algo, o componente é criado ou ampliado aqui.

### Tokens (`resources/css/tokens.css`)

Os tokens são a fonte única de cores, raios e sombras, com modo claro e escuro.
O Tailwind os expõe como utilitários (`bg-superficie`, `text-suave`,
`border-borda`, `bg-primaria`...), e o tema troca sozinho, sem precisar de
`dark:` nos componentes.

| Cor | Token | Significado (fixo) |
|---|---|---|
| Azul | `primaria` | Ação principal |
| Verde | `sucesso` | Liberado ou ativo |
| Vermelho | `perigo` | Negado ou erro |
| Âmbar | `atencao` | Atenção |
| Cinza | `inativo` | Inativo ou arquivado |

- A cor **nunca aparece sozinha**: sempre vem com texto ou ícone (ex.: `<x-status>`).
- Todos os pares de texto e fundo têm contraste de pelo menos **4,5:1** (WCAG 2.2 AA), nos dois temas. O menor par é 5,39:1.
- **Tema:** segue o sistema operacional. O botão de tema alterna entre sistema, claro e escuro, e a escolha fica no cookie `cg_tema` (sem dado pessoal). O servidor já entrega a página no tema certo, sem "piscar".
- **Fonte Inter e ícones SVG** saem do próprio servidor (build do Vite). Não há CDN: a CSP só permite `'self'`.

### Componentes (`resources/views/components`)

| Componente | Uso | Observações |
|---|---|---|
| `<x-botao>` | Botão ou link (`href`) | `variante`: primaria, secundaria, perigo, fantasma. `tamanho`: pequeno, medio, grande (portaria). Só ícone? use `rotulo`. |
| `<x-campo>` | Campo de texto, e-mail, data, área de texto | Rótulo sempre visível, "(opcional)" automático, erro ao lado do campo, `mascara` cpf/cnpj/cep/telefone. |
| `<x-selecao>` | Lista de escolha | Mesmo padrão do campo. |
| `<x-tabela>` | Tabelas de dados | Cabeçalho fixo, densidade compacta, colunas configuráveis, seleção em lote e slots de filtros e paginação. Usa `<x-tabela.coluna>` (ordenável), `<x-tabela.selecionar-todos>` e `<x-tabela.selecionar>`. |
| `<x-chip>` | Filtros em chips | Ativo (com "x" para remover) ou para aplicar. |
| `<x-status>` | Selo de status | ativo, negado, atencao, inativo, info. Sempre com ícone e texto. |
| `<x-indicador>` | Card de indicador | `tipo`: normal, destaque, atencao, perigo. Com `href`, o card é clicável. |
| `<x-alerta>` | Mensagens | Erro não some sozinho e não fecha. Sucesso pode fechar e ter "Desfazer". |
| `<x-modal-confirmacao>` | Confirmar ação | Único uso de modal. Descreve o impacto. `digitar` exige o texto exato (ex.: o nome do cliente). |
| `<x-abas>` | Abas de um registro | Cada aba é um link (funciona sem JavaScript). |
| `<x-migalhas>` | Migalhas de pão | Telas de detalhe. |
| `<x-estado-vazio>` | Lista vazia | Diz o próximo passo, com botão. |
| `<x-esqueleto>` | Carregando | Formatos linhas, tabela e cards. Respeita "reduzir animações". |
| `<x-cabecalho-pagina>` | Topo da tela | Título, subtítulo e ações (período, exportar, ação principal). |
| `<x-cartao>` | Bloco de conteúdo | Título, descrição e ações. |
| `<x-icone>` | Ícones SVG | Decorativo por padrão. Com `rotulo`, é anunciado. |
| `<x-layouts.app>` | Área logada | Menu lateral recolhível, cabeçalho com seletor de contexto, busca global e alertas, e barra inferior no celular (4 atalhos e "Mais"). |
| `<x-layouts.publico>` | Páginas sem login | Entrar, recuperar senha, contratar. |

### Padrões

- **Formatos brasileiros:** `App\Support\Formato`.
  - Data no formato dd/mm/aaaa e hora de 24 horas, no fuso `APP_TIMEZONE`.
  - Moeda em R$.
  - CNPJ, inclusive o alfanumérico.
  - CEP e telefone com DDD.
  - CPF **mascarado por padrão** (`***.456.789-**`). O CPF completo só aparece para quem tem permissão, e esse acesso deve ser auditado.
- **Textos:** português do Brasil, em linguagem simples, sem emojis. As mensagens do Laravel (validação, login, senha) estão em `lang/pt_BR`. As de senha nunca revelam se um e-mail tem conta.
- **Busca global por POST:** o termo pesquisado (nome, CPF) não fica na URL nem no histórico.
- **Rascunho automático** (`<form data-rascunho="chave">`):
  - fica no `sessionStorage`, que é apagado ao fechar a aba;
  - nunca guarda senha, campos ocultos ou campos com `data-sem-rascunho` (use em CPF e outros dados pessoais).
- **Teclado:** foco sempre visível, "Pular para o conteúdo", Esc fecha menus e modais, e o modal prende o foco.
- **Segurança:** cabeçalhos em `App\Http\Middleware\CabecalhosSeguranca`.
  - CSP só com `'self'`;
  - sem scripts inline;
  - `frame-ancestors 'none'`, `nosniff` e `Referrer-Policy`.
- **Revisão antes de fechar cada fase:** desktop, tablet e celular, contraste e navegação por teclado. A tela de portaria é testada em tablet na horizontal.
