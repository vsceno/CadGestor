# CadGestor

Sistema de controle de acesso físico e cadastro de pessoas vinculadas a empresas
(funcionários, alunos, prestadores), com liberação de catraca por biometria digital
ou reconhecimento facial.

> **Aviso LGPD:** dados biométricos são dados pessoais sensíveis (art. 5º, II, e
> art. 11 da LGPD). A base legal de cada perfil de vínculo é **configurável**, mas
> deve ser **validada pelo jurídico do cliente** antes da implantação. O padrão do
> sistema é exigir consentimento específico.

## Stack

| Componente | Versão |
|---|---|
| Windows + Laragon | 6 |
| Apache (Laragon) | 2.4 |
| PHP (CLI e Apache) | 8.5.5 |
| PostgreSQL | 18.6, porta 5432, schema `cadgestor` |
| Composer | 2.x |
| Testes | PHPUnit 13 |
| Estilo | PSR-12 (PHP_CodeSniffer), autoload PSR-4 |

Sem framework completo: MVC próprio e leve (roteador, controllers, services,
repositories com PDO e views em PHP puro).

## Estrutura de pastas

```
bin/            CLI: migrar, seed, criar-admin, gerar-chave, manutencao
config/         rotas.php e servicos.php (quem depende de quem)
database/
  setup/        criação de papéis, banco e schema (executar uma vez)
  migracoes/    migrações SQL versionadas (0001..0009)
  seeds/        perfis padrão e termo de consentimento modelo
docs/           revisão de segurança e LGPD
public/         ÚNICA pasta exposta pelo Apache (index.php, assets)
src/            código da aplicação (namespace CadGestor\)
  Nucleo/       roteador, requisição, resposta, ambiente, banco, erros, log, CSV
  Http/         controllers e middlewares (sessão, CSRF, login, papel)
  Dominio/      regras puras: Cpf, Cnpj, IntervaloHorario, ValidadeVinculo, Idade, PoliticaRetencao
  Servicos/     regras de negócio e auditoria
  Repositorios/ SQL com PDO (prepared statements)
  Acesso/       DecisaoAcesso, API da catraca e sincronização com dispositivos
  Biometria/    contrato IdentificadorBiometrico, simulado e adaptadores de fabricantes
  Seguranca/    sessão, CSRF, senhas, autenticação, cifra da biometria
  Banco/        migrador, seeds e transações
views/          templates PHP (saída sempre com e())
storage/
  logs/         logs de erro (sem dados pessoais)
  chaves/       chave de cifra da biometria (fora do git)
tests/          PHPUnit: Unit/ (sem banco) e Integracao/ (banco cadgestor_teste)
```

## Instalação no Laragon

### 1. Pré-requisitos

1. Laragon 6 com **PHP 8.5.5** selecionado (Menu > PHP > Version).
2. **PostgreSQL 18.6** selecionado e iniciado (Menu > PostgreSQL > Version).
   Se houver outra versão instalada (ex.: 14), confirme que só a 18.6 está rodando na 5432:
   ```powershell
   & "C:\laragon\bin\postgresql\postgresql-18.6-3-windows-x64-binaries\bin\psql.exe" -h 127.0.0.1 -U postgres -c "select version();"
   ```
3. Extensões do PHP habilitadas em `C:\laragon\bin\php\php-8.5.5-Win32-vs17-x64\php.ini`
   (remova o `;` do início da linha):
   ```ini
   extension=pdo_pgsql
   extension=pgsql
   extension=mbstring
   extension=openssl
   extension=fileinfo
   extension=intl
   extension=sodium
   ```
   `json` já vem embutido no PHP 8. Depois, recarregue o Apache no Laragon.

### 2. Código e dependências

```powershell
cd C:\laragon\www
git clone https://github.com/vsceno/CadGestor.git
cd CadGestor
composer install
```

### 3. Banco de dados

O banco usa **dois papéis**, como numa empresa em que o arquiteto (owner) projeta
o prédio e o funcionário (app) só usa as salas:

| Papel | Uso | Permissões |
|---|---|---|
| `cadgestor_owner` | migrações (Fase 1) | dono do schema, cria e altera tabelas |
| `cadgestor_app` | aplicação web e API | apenas SELECT/INSERT/UPDATE/DELETE |

Se a aplicação for comprometida, o invasor não consegue apagar tabelas nem mudar a estrutura.

Gere duas senhas fortes e execute o script como `postgres`:

```powershell
$owner = php -r "echo bin2hex(random_bytes(18));"
$app   = php -r "echo bin2hex(random_bytes(18));"
& "C:\laragon\bin\postgresql\postgresql-18.6-3-windows-x64-binaries\bin\psql.exe" `
    -h 127.0.0.1 -U postgres `
    -v "senha_owner=$owner" -v "senha_app=$app" `
    -f database/setup/01_criar_banco.sql
"DB_SENHA=$app"; "DB_MIGRACAO_SENHA=$owner"   # anote para o .env
```

O script cria os papéis, o banco `cadgestor` (UTF-8, collation ICU pt-BR) e o
schema `cadgestor`, e define as permissões padrão.

> No Laragon, o `pg_hba.conf` costuma vir com `trust` para conexões locais, ou seja,
> sem cobrar senha. Em produção, use `scram-sha-256`.

### 4. Arquivo .env

```powershell
Copy-Item .env.example .env
```

Preencha `DB_SENHA` e `DB_MIGRACAO_SENHA` com as senhas geradas. Salve o arquivo em
**UTF-8 sem BOM**. No PowerShell 5.1, `Set-Content -Encoding utf8` grava com BOM;
prefira o editor.

### 5. Virtual host `cadgestor.test`

O vhost automático do Laragon aponta para a **raiz** do projeto, e o CadGestor precisa
apontar para `public/`. Crie `C:\laragon\etc\apache2\sites-enabled\01-cadgestor.test.conf`:

```apache
<VirtualHost *:80>
    DocumentRoot "C:/laragon/www/CadGestor/public"
    ServerName cadgestor.test
    <Directory "C:/laragon/www/CadGestor/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

O prefixo `01-` faz esse arquivo ser carregado antes do `auto.CadGestor.test.conf`
gerado pelo Laragon, então ele tem prioridade. Depois clique em **Recarregar** no Laragon,
que também adiciona `cadgestor.test` ao arquivo `hosts`.

Como segunda trava, o `.htaccess` da raiz do projeto nega qualquer acesso. Se algum
vhost apontar para a raiz por engano, `.env`, `vendor/` e `storage/` continuam
inacessíveis.

### 6. Migrações, seeds e administrador inicial

```powershell
php bin/migrar.php --status   # lista migrações aplicadas e pendentes
php bin/migrar.php            # aplica as pendentes (usa o papel cadgestor_owner)
php bin/seed.php              # perfis padrão e termo modelo de cada organização (pode rodar várias vezes)
php bin/criar-admin.php --nome="Nome Completo" --email=admin@empresa.com.br   # administrador da PLATAFORMA
```

- **Migrações só andam para frente.** Cada arquivo aplicado fica registrado com
  o checksum. Se você editar uma migração que já rodou, o migrador recusa e para.
  Correções sempre entram como uma nova migração (exemplo: `0008_corrige_checks_com_nulo.sql`).
- **Seeds são idempotentes**, ou seja, rodar de novo não duplica nada.
- **A senha do administrador** é gerada aleatoriamente e exibida **uma única vez**.
  Ela não fica gravada em nenhum arquivo, e a troca é obrigatória no primeiro login
  (Fase 2). O script recusa criar um segundo administrador por esse caminho.
- **Depois do primeiro login**, o administrador da plataforma cadastra as
  organizações (clientes) em `/organizacoes` e o administrador de cada uma (ver
  "Multi-tenant" abaixo).

### 7. Verificação

| Endereço | Esperado |
|---|---|
| http://cadgestor.test/ | Tela de login |
| http://cadgestor.test/diagnostico | (administrador, fora de produção) Diagnóstico de ambiente, migrações, perfis e tabelas |
| http://cadgestor.test/saude | `{"status":"ok","banco":"conectado","schema":"cadgestor"}` |
| http://cadgestor.test/qualquer-coisa | Página 404 |

## Testes e padrão de código

```powershell
composer test       # PHPUnit (unitários + integração)
composer lint       # verifica PSR-12
composer lint:fix   # corrige o que for automático
```

Os **testes de integração** usam um banco separado, o `cadgestor_teste`. Crie-o uma vez:

```powershell
& "C:\laragon\bin\postgresql\postgresql-18.6-3-windows-x64-binaries\bin\psql.exe" `
    -h 127.0.0.1 -U postgres -f database/setup/02_criar_banco_teste.sql
```

A cada execução, os testes **apagam e recriam** o schema desse banco, aplicam as
migrações e os seeds. Cada teste roda numa transação que é desfeita no final. O nome
do banco vem do `phpunit.xml` (`DB_TESTE_BANCO`), e os testes se recusam a rodar se
ele for igual ao `DB_BANCO` do `.env`. Sem o banco de teste, esses testes são pulados.

## Variáveis do .env

| Variável | Fase | Descrição |
|---|---|---|
| `APP_AMBIENTE` | 0 | `desenvolvimento`, `teste` ou `producao` |
| `APP_DEBUG` | 0 | `true` mostra detalhes de erro na tela; **sempre ignorado em produção** |
| `APP_URL` | 0 | URL base (ex.: `http://cadgestor.test`) |
| `APP_FUSO` | 0 | Fuso das regras de horário (padrão `America/Fortaleza`) |
| `DB_HOST`, `DB_PORTA` | 0 | Servidor PostgreSQL |
| `DB_BANCO`, `DB_SCHEMA` | 0 | Banco e schema (`cadgestor`) |
| `DB_USUARIO`, `DB_SENHA` | 0 | Papel da aplicação (`cadgestor_app`) |
| `DB_MIGRACAO_USUARIO`, `DB_MIGRACAO_SENHA` | 1 | Papel de migração (`cadgestor_owner`) |
| `APP_SEGREDO` | 2 | Segredo (64 hex) para HMAC de identificadores técnicos |
| `SESSAO_INATIVIDADE_MINUTOS` | 2 | Minutos sem uso até a sessão expirar (padrão 30) |
| `SESSAO_SEGURA` | 2 | Cookie de sessão só por HTTPS (`true` em produção) |
| `CHAVE_CIFRA_ARQUIVO` | 6 | Caminho da chave de cifra dos templates biométricos (fora de `public/`) |
| `IDENTIFICADOR_BIOMETRICO` | 6 | Adaptador do hardware: `simulado` ou o do fabricante |
| `LIMIAR_CONFIANCA` | 7 | Confiança mínima (0 a 1) para aceitar a identificação (padrão 0.80) |
| `RETENCAO_DIAS` | 9 | Dias após o fim do vínculo para excluir biometria e anonimizar o titular. **Provisório: validar com o jurídico** |
| `PAGAMENTO_GATEWAY` | Comercial | `simulado` (só fora de produção) ou o adaptador do gateway real |
| `PAGAMENTO_WEBHOOK_TOKEN` | Comercial | Segredo que autentica os avisos do gateway (64 hex) |
| `MENSAGEIRO` | Comercial | `arquivo` (só fora de produção, grava em `storage/emails`) ou o provedor real |

## Decisões técnicas

- **Datas:** o banco grava `timestamptz` em UTC; a conversão para `APP_FUSO` acontece na exibição e nas regras de horário.
- **IDs:** UUID gerado pelo banco com `uuidv7()` (PostgreSQL 18), que é ordenado pelo tempo e mantém os índices compactos.
- **Erros:** o log registra só a classe do erro, o arquivo, a linha e um código de referência, **nunca a mensagem**, porque mensagens de banco podem conter CPF e outros valores. O usuário vê só o código de referência.
- **URLs:** parâmetros de rota aceitam apenas UUID, então CPF ou nome nunca aparecem no endereço.
- **Cabeçalhos de segurança:** toda resposta inclui CSP, `X-Frame-Options: DENY`, `nosniff` e `Referrer-Policy`.

### Autenticação e permissões (Fase 2)

| Papel | Acesso |
|---|---|
| `plataforma` | Só a plataforma: clientes, planos, assinaturas e administradores dos clientes. Não acessa o ambiente dos clientes |
| `administrador` | Tudo **na própria organização**, incluindo usuários, perfis, termos LGPD, auditoria e diagnóstico |
| `cadastro_empresa` | Empresas, horários, catracas e regras de acesso por perfil |
| `cadastro_pessoa` | Pessoas, vínculos, consentimento e biometria |
| `consulta` | Relatórios, exportação CSV e alertas (somente leitura) |

Um usuário pode ter vários papéis. Os papéis são verificados **em cada rota**
pelos middlewares, e o menu só mostra o que o usuário pode acessar.

- **Senhas:** Argon2id, com mínimo de 12 caracteres, recusa de senhas comuns e
  troca obrigatória no primeiro acesso. Hashes antigos são refeitos no login.
- **Força bruta:** após 5 falhas por e-mail ou 20 por IP em 15 minutos, o login é
  bloqueado. O e-mail fica gravado como HMAC (`APP_SEGREDO`), nunca em texto. A
  resposta é a mesma para e-mail inexistente e senha errada, inclusive no tempo.
- **Sessão:** o cookie `cadgestor_sessao` usa `HttpOnly` e `SameSite=Strict`, e
  `Secure` quando `SESSAO_SEGURA=true`. O ID muda no login e na troca de senha. A
  sessão expira por inatividade (`SESSAO_INATIVIDADE_MINUTOS`) e após 12 horas.
  Usuário desativado perde o acesso na hora.
- **CSRF:** todo POST exige token; sem ele, a resposta é 403.
- **Auditoria:** login, falha de login, logout, troca de senha e cadastro de usuários.

### Empresas, perfis, catracas e regras (Fases 3 e 4)

- **Empresas:** cadastro com validação de CNPJ (numérico e alfanumérico), CEP, UF,
  e-mail e telefone do responsável legal. Empresas são inativadas, nunca apagadas.
  Em cada empresa se configuram os **perfis aceitos** e o **horário padrão**.
- **Perfis** (somente administrador): novos perfis entram pela tela, sem mudar
  código. Cada perfil registra a base legal e se ela já foi **validada pelo jurídico**.
- **Catracas:** ao cadastrar, o sistema gera um token (`cgt_` + 64 caracteres
  hexadecimais) exibido **uma única vez**; o banco guarda só o SHA-256. Ao gerar
  um novo token, o anterior para de funcionar na hora.
- **Regras de acesso:** uma matriz perfil × catraca × dia × faixa de horário, como
  lista de permissões (fim exclusivo; `00:00` no fim = meia-noite). Faixas sobrepostas
  na mesma catraca são recusadas.

#### Modo de falha e funcionamento offline (requisito)

| Modo | Sem resposta do servidor | Indicado para |
|---|---|---|
| `bloquear` (padrão) | A catraca mantém o bloqueio | Áreas restritas |
| `liberar` | A catraca libera a passagem | Fluxos onde travar causa risco |

- A API devolve o `modo_falha` de cada catraca (`GET /api/v1/configuracao`), para que o
  dispositivo saiba como agir sem conexão.
- **Offline:** o dispositivo deve guardar os eventos localmente e reenviá-los com
  `data_hora` original e `offline: true`. Eles são gravados como eventos offline.
  A decisão offline depende do recurso do fabricante (lista local de templates) e fica
  para quando o modelo da catraca for definido.
- **Emergência/incêndio:** a liberação das rotas de fuga **não deve depender do
  software**. O correto é intertravamento por hardware com o alarme (destravamento
  físico, conforme normas do Corpo de Bombeiros). O sistema apenas registra.

### Pessoas e vínculos (Fase 5)

- **Pessoas:** CPF validado e único (uma pessoa por CPF), nome completo, documento
  de identidade, nascimento e contato. A **busca por nome/CPF usa POST**, então esses
  dados não aparecem na URL, no histórico do navegador nem nos logs do Apache.
  Listagens mostram o CPF mascarado (`***.982.247-**`).
- **Vínculos:** uma pessoa pode ter vínculos com várias empresas e mais de um perfil
  na mesma empresa. Só aparecem combinações de empresa ativa com perfil aceito.
  Vínculos são **suspensos** ou **encerrados** (com motivo e data), nunca apagados.
- **Validade** (`Dominio\ValidadeVinculo`): um vínculo é *vigente* quando o status é
  `ativo` e a data está entre início e fim, **ambos inclusivos**. Fora disso ele fica
  *ainda não iniciado*, *vencido*, *suspenso* ou *encerrado*. O painel avisa quando
  faltam 30 dias ou menos para vencer.
- **Exceção por vínculo:** regras próprias **substituem** as do perfil para aquele vínculo.
- **Sincronização:** ao criar um vínculo, as biometrias da pessoa entram na fila para
  as catracas da empresa. Ao encerrar o último vínculo com uma empresa, entra na fila a
  **remoção** da pessoa das catracas dela.

### LGPD: consentimento e biometria (Fase 6)

```powershell
php bin/gerar-chave.php                # cria storage/chaves/biometria.key (uma vez)
php bin/gerar-chave.php --rotacionar   # nova versão de chave (as antigas continuam decifrando)
```

> **Faça backup do arquivo de chave em local seguro e separado do backup do banco.**
> Sem ele, os templates cifrados não podem ser recuperados. No Windows, restrinja o
> acesso com `icacls storage\chaves\biometria.key /inheritance:r /grant:r "%USERNAME%:R"`
> e conceda leitura ao usuário do serviço do Apache.

- **Consentimento antes de tudo:** a coleta fica bloqueada até haver consentimento ativo.
  A trava existe na aplicação **e** no banco (trigger `biometria_exige_consentimento`).
- **O que fica registrado:** versão e hash SHA-256 do termo, data e hora, IP da estação,
  canal e o operador. Os termos são versionados e **imutáveis**; uma nova versão é
  publicada em *Termos LGPD*. O termo `1.0` é um **modelo** e precisa de revisão jurídica.
- **Menores de 18 anos:** o consentimento exige nome, CPF e parentesco do responsável
  legal. **Sem data de nascimento, a coleta é bloqueada** (idade não verificável).
- **Cifra:** XChaCha20-Poly1305 (libsodium), com subchaves derivadas por KDF, nonce
  aleatório de 24 bytes e AAD que amarra o template à pessoa, ao tipo e ao dedo.
  O `hash_integridade` é um BLAKE2b com chave. Nenhuma tela mostra template.
- **Foto facial (decisão documentada):** a foto (webcam ou arquivo JPEG/PNG/WebP de
  até 5 MB, tipo conferido pelo conteúdo) é usada **só em memória** para gerar o
  template e é descartada. O arquivo temporário é apagado e a variável é zerada com
  `sodium_memzero`. **A tabela `foto_facial` não existe.** Ela só será criada se o
  fabricante exigir a foto no dispositivo.
- **Digital:** imagem bruta nunca é recebida nem gravada. No modo simulado, a
  "leitura" é gerada no servidor e sinalizada na tela.
- **Revogação:** apaga **de fato** todas as biometrias (template, nonce e hash viram
  NULL), cancela envios pendentes e agenda `remover` em **todas** as catracas das
  empresas da pessoa, tudo registrado na auditoria.
- **Integração com hardware:** contrato `Biometria\IdentificadorBiometrico`
  (extrair template, cadastrar, remover, identificar), com a implementação
  `IdentificadorSimulado`. Adaptadores de fabricantes entram em
  `src/Biometria/Adaptadores` (veja o LEIA-ME); **nenhum SDK foi suposto**.

### API das catracas e decisão de acesso (Fase 7)

Todas as rotas exigem `Authorization: Bearer <token da catraca>` e trocam JSON. Elas
**não usam sessão nem cookie**. Em produção, use **somente HTTPS**, porque o
`/sincronizacao` entrega templates decifrados ao dispositivo dono da operação.

| Método e rota | Uso |
|---|---|
| `POST /api/v1/acesso` | Pedido de passagem. Retorna `{"decisao":"liberado"\|"negado","motivo":...,"evento_id":...,"data_hora":...}` |
| `GET /api/v1/configuracao` | Modo de falha, tipo de identificação, limiar, fuso e hora do servidor |
| `GET /api/v1/sincronizacao` | Próximas operações `cadastrar`/`remover` da catraca (com template em base64 quando for cadastrar) |
| `POST /api/v1/sincronizacao/{id}` | Confirma a operação: `{"sucesso":true}` ou `{"sucesso":false,"erro":"..."}`. Falhas são reagendadas com atraso crescente; depois de 5 tentativas, viram alerta |
| `POST /api/v1/eventos-offline` | Eventos decididos sem servidor: `{"eventos":[{"tipo","referencia","resultado","data_hora"}]}`. Aceita até 30 dias para trás e até 500 por envio |

**Corpo do `/acesso`**: há dois modos, porque depende do fabricante (ponto em aberto).

```jsonc
// 1) O dispositivo reconheceu a pessoa (1:N ou 1:1 no hardware)
{ "tipo": "digital", "referencia": "<uuid da pessoa no dispositivo>", "score": 0.93 }
// 2) O servidor identifica (1:N) a partir da amostra capturada
{ "tipo": "facial", "amostra": "<base64>" }
```

A resposta traz só a decisão e o motivo técnico, sem nome, CPF ou qualquer outro
dado pessoal. A `referencia` é o UUID da pessoa, que não identifica ninguém fora do sistema.

**`DecisaoAcesso`** (`src/Acesso/DecisaoAcesso.php`) verifica, nesta ordem, e para na
primeira falha:

1. Catraca existe e está ativa, e a empresa dela também → `catraca_inexistente` ou `catraca_inativa`
2. Pessoa identificada, existente e ativa → `pessoa_nao_identificada` ou `pessoa_inativa`
3. Vínculo **vigente** com a empresa da catraca → `sem_vinculo_ativo`
4. Perfil aceito pela empresa e ativo → `perfil_nao_permitido`
5. Regra para o dia, a hora e a catraca, no fuso `APP_FUSO` (vínculo > perfil > horário padrão) → `fora_do_horario`
6. Confiança ≥ `LIMIAR_CONFIANCA` e biometria ativa com consentimento → `biometria_nao_confere`

Todo pedido gera um `evento_acesso`. Toda negação gera um **alerta**, e 3 ou mais
negações em 2 minutos na mesma catraca geram um alerta de *tentativas repetidas*.
Um token desconhecido gera evento, alerta de *dispositivo desconhecido* e resposta 401,
com limite de registros para não lotar as tabelas. Na identificação feita pelo servidor,
um candidato com confiança abaixo de 0,30 é tratado como "ninguém reconhecido", para não
atribuir o evento à pessoa errada.

**Simulador** (`/simulador`, administrador, só fora de produção): usa o mesmo motor da
API, sem token, para testar regras e motivos pelo navegador.

Exemplo com `curl`:

```bash
curl -X POST http://cadgestor.test/api/v1/acesso \
  -H "Authorization: Bearer cgt_<64 hex>" -H "Content-Type: application/json" \
  -d "{\"tipo\":\"digital\",\"referencia\":\"<uuid>\",\"score\":0.95}"
```

### Relatórios, CSV e auditoria (Fase 8)

- **Relatórios** (`/relatorios`, papel `consulta`): filtros por período (datas locais
  inclusivas, até 366 dias), empresa, perfil, catraca e resultado. Mostram resumos
  **por perfil**, **por empresa**, por motivo de negação e por dia, além da lista de
  eventos. Os filtros vão na URL porque são apenas datas e UUIDs.
- **Exportação CSV**: UTF-8 com BOM e `;`, que o Excel em português abre direto, com
  **proteção contra injeção de fórmula** (células começando com `= + - @` viram
  texto). O CPF sai **mascarado** e cada exportação é registrada na auditoria (quem,
  quando, filtros e número de linhas, sem o conteúdo).
- **Trilha de auditoria** (`/auditoria`, administrador): filtros por período, ação,
  tabela e usuário, exibição de "antes/depois" e exportação CSV. O papel da aplicação
  não consegue alterar nem apagar esses registros.

### Manutenção, retenção e segurança (Fase 9)

```powershell
php bin/manutencao.php --simular                    # mostra o que seria feito (não altera nada)
php bin/manutencao.php --simular --data=2027-02-01  # prevê o efeito numa data futura
php bin/manutencao.php                              # executa todas as tarefas
php bin/manutencao.php --tarefa=retencao            # uma tarefa: vinculos|retencao|catracas|limpeza
```

| Tarefa | O que faz |
|---|---|
| `vinculos` | Encerra vínculos com validade expirada e agenda a remoção da pessoa nas catracas |
| `retencao` | Para quem está sem vínculo há mais de `RETENCAO_DIAS`: **apaga as biometrias**, apaga os dados do responsável no consentimento e **anonimiza** o titular (nome, CPF, documento, nascimento e contato); agenda a remoção nos dispositivos |
| `catracas` | Gera alerta para catracas ativas sem contato há mais de 30 minutos |
| `limpeza` | Remove tentativas de login e itens concluídos da fila com mais de 90 dias |

Cada execução é registrada na auditoria (`job_manutencao` e `anonimizar`) e no log, só
com contagens. O job usa o papel `cadgestor_owner`, porque o papel da aplicação não tem
permissão de exclusão.

- **Política de retenção** (`Dominio\PoliticaRetencao`): a pessoa é elegível quando não
  tem vínculo em vigor e já passaram mais de N dias da **maior** data de fim (ou da data de
  cadastro, se nunca teve vínculo). O registro do consentimento é mantido (versão, hash e
  data) como prova de licitude, sem dados do responsável. Os eventos de acesso continuam,
  ligados ao registro anonimizado.

**Agendamento no Windows** (todo dia às 02:00):

```powershell
schtasks /Create /TN "CadGestor - manutencao" /SC DAILY /ST 02:00 /RU SYSTEM `
  /TR "C:\laragon\bin\php\php-8.5.5-Win32-vs17-x64\php.exe C:\laragon\www\CadGestor\bin\manutencao.php"
```

**Revisão de segurança:** veja [docs/revisao-seguranca.md](docs/revisao-seguranca.md), com o
checklist do escopo, as verificações feitas, as correções, o que configurar em
produção e os riscos residuais.

### Multi-tenant: organizações isoladas (migração 0010)

O CadGestor atende **vários clientes** numa mesma instalação. Cada cliente é uma
**organização**, com suas próprias empresas, perfis, catracas, pessoas, vínculos,
consentimentos, biometrias, eventos, alertas, usuários e auditoria. Uma organização
**nunca** enxerga os dados de outra.

Pense num prédio comercial: cada andar é uma organização, e o crachá que a recepção
entrega só abre as portas do andar daquela empresa. Aqui, quem confere o crachá é o
próprio banco de dados.

**Como o isolamento é garantido (camadas):**

1. **Row-Level Security (RLS) no PostgreSQL.** No início de cada requisição a
   aplicação informa a organização ao banco (`ContextoBanco`, via `set_config`). As
   políticas só mostram e só aceitam linhas daquela organização, mesmo que uma
   consulta esqueça o filtro. Sem organização definida, nada aparece.
2. **`organizacao_id` com `DEFAULT cg_org_atual()`.** Os INSERTs pegam a organização
   do contexto automaticamente.
3. **Chaves estrangeiras compostas `(organizacao_id, id)`.** Mesmo sabendo o UUID de
   uma empresa de outro cliente, não é possível criar um vínculo apontando para ela.
4. **Trigger `impedir_troca_organizacao`.** Nenhum registro muda de organização.
5. **Papel `plataforma` travado no banco.** Um administrador de cliente não consegue
   se promover a administrador da plataforma.

**Usuários:**

| Tipo | `organizacao_id` | O que faz |
|---|---|---|
| Plataforma | `NULL` | Cadastra organizações e o administrador de cada uma; vê só contagens (nenhum dado pessoal) |
| Organização | preenchido | Trabalha só dentro da própria organização, conforme os papéis |

- **A plataforma não entra no ambiente do cliente.** Ela só vende e controla os
  planos (migração 0011). O modo suporte existiu na primeira versão e foi removido.
  Se um cliente perder o administrador, a plataforma cria outro, e isso aparece na
  auditoria do cliente como "Equipe da plataforma".
- **O e-mail de login é único no sistema todo.** Já CPF, CNPJ, nome de perfil,
  identificador de catraca e versão de termo são únicos **dentro** da organização.
  A mesma prestadora de limpeza pode atender dois clientes, como cadastros separados.
- **Organização desativada:** os usuários dela não entram (sessões abertas caem) e
  a API recusa as catracas dela (401).
- **Organização nova** já nasce com os perfis padrão e o termo **modelo** 1.0
  (`cg_provisionar_organizacao`), que deve ser revisado pelo jurídico de cada cliente.

**Onde a organização ainda não é conhecida:** login (pelo e-mail) e API (pelo hash
do token da catraca) usam funções `SECURITY DEFINER` (`cg_login_usuario`,
`cg_catraca_por_token`), que devolvem só identificadores. Requisições com token
inválido geram eventos e alertas **sem organização**, visíveis só no nível da
plataforma.

**Jobs de manutenção** rodam com o papel dono, que não passa pelo RLS. Por isso o
job faz cada alteração dentro da organização do registro, e a auditoria vai para
o cliente certo. O resumo do job fica na auditoria da plataforma.

**Instalações anteriores:** a migração 0010 move os dados existentes para a
"Organização padrão" (pode ser renomeada em `/organizacoes`). O teste
`IsolamentoOrganizacaoTest` verifica tudo isso com a conexão real da aplicação.

### Comercial: planos, assinatura e cobrança (migração 0011)

A plataforma é como a administradora de um shopping: vende e controla os contratos
(planos), mas não entra nas lojas. Cada cliente é dono do próprio ambiente.

**Fluxo de venda (self-service):**

1. O cliente abre `/cadastro`, escolhe o plano e informa a razão social, o CNPJ e o
   administrador (nome, e-mail e senha). Também aceita os Termos de Uso, o Contrato
   de Tratamento de Dados e a Política de Privacidade. A versão aceita, a data e o
   IP ficam gravados.
2. O ambiente nasce pronto, numa única transação:
   - perfis padrão e termo de consentimento modelo;
   - assinatura em **teste grátis de 14 dias**;
   - assinatura no gateway, com a primeira cobrança vencendo no fim do teste.
3. O administrador recebe um e-mail de confirmação. O login só funciona depois de
   clicar no link (o banco guarda só o hash do token; o link vale por 72 horas).
4. O cliente configura tudo sozinho: empresas, catracas, pessoas e usuários.

**Venda manual** (contrato fora do site): em `/organizacoes/nova`, a plataforma cria
o cliente com a assinatura ativa, sem cobrança automática.

**Planos** (`/planos`, só a plataforma): preço mensal e limites de **pessoas ativas** e
**catracas ativas**. Os valores iniciais são **provisórios**: Básico R$ 199,00
(100 pessoas, 2 catracas), Profissional R$ 499,00 (500 e 6) e Empresarial R$ 1.290,00
(2.000 e 20). Os limites são conferidos ao cadastrar e ao reativar pessoas e catracas.
Um downgrade só é aceito se o uso atual couber no plano novo.

**"Minha assinatura"** (`/assinatura`): plano, uso, faturas com o link de pagamento,
troca de plano, cancelamento (é preciso digitar CANCELAR) e **exportação dos
cadastros em CSV**, disponível a qualquer momento.

**Régua de inadimplência** (`Dominio\SituacaoAssinatura`, **prazos provisórios**). A
situação é calculada na hora, sem depender de job:

| Dias de atraso | Sistema | Catracas |
|---|---|---|
| 0 a 6 | Normal, com aviso | Funcionam |
| 7 a 14 | Somente leitura | Funcionam |
| 15 a 29 | Só "Minha assinatura" | **Funcionam** (bloquear a porta afeta terceiros) |
| 30 ou mais / cancelada | Só "Minha assinatura" | Recusadas (API 403) |

O teste grátis vencido sem pagamento conta como atraso a partir do dia seguinte.

**Cobrança:** a interface `Cobranca\GatewayPagamento` tem uma implementação simulada,
`GatewaySimulado`, que só funciona fora de produção. Nas faturas dela, "Pagar" abre
`/pagamento-simulado/{id}`, onde é possível pagar, deixar vencer ou gerar a fatura
seguinte. O gateway real entra como um adaptador em `src/Cobranca/Adaptadores`,
escrito a partir da documentação oficial do gateway. **Nenhum dado de cartão passa
pelo CadGestor.**

**Webhook do gateway:** `POST /api/v1/pagamentos/webhook`.
- **Autenticação:** é conferida pelo adaptador. No simulado, o cabeçalho
  `X-Webhook-Token` deve ser igual a `PAGAMENTO_WEBHOOK_TOKEN`.
- **Idempotência:** cada aviso tem um id único (`evento_gateway`). Um aviso
  reenviado não é aplicado de novo.
- **Organização:** o aviso chega sem organização. Ela é descoberta pela cobrança ou
  pela assinatura do gateway, e o processamento roda dentro dela, com a auditoria
  na trilha do cliente.

**E-mail:** a interface `Mensagens\Mensageiro`. Em desenvolvimento, `MensageiroArquivo`
grava arquivos `.eml` em `storage/emails/`, pasta fora do git porque contém dados
pessoais. Em produção, é obrigatório configurar um provedor real.

**Documentos jurídicos** (`/termos-de-uso`, `/contrato-dados`, `/privacidade`): são
**MODELOS** (versão `1.0-modelo`) e precisam de revisão do advogado antes de vender.

### Modelo de dados (Fase 1)

| Grupo | Tabelas |
|---|---|
| Sistema | `usuario_sistema`, `usuario_papel`, `tentativa_login`, `auditoria`, `migracao` |
| Cadastros | `perfil_vinculo`, `empresa`, `empresa_perfil`, `empresa_horario_padrao`, `catraca` |
| Pessoas | `pessoa`, `vinculo`, `regra_acesso` |
| LGPD | `termo_consentimento`, `consentimento`, `biometria` |
| Operação | `evento_acesso`, `alerta`, `fila_sincronizacao` |

- **CPF e CNPJ são validados também no banco**, pelas funções `cpf_valido` e
  `cnpj_valido`. O CNPJ aceita o **formato alfanumérico** da Receita, vigente desde
  julho de 2026 (ex.: `12ABC34501DE35`). Os valores são gravados sem máscara e em maiúsculas.
- **Regras de acesso** funcionam como lista de permissões. A hierarquia é: regras
  do vínculo (exceção; se existirem, só elas valem) → regras do perfil na empresa
  → horário padrão da empresa. Turnos que passam da meia-noite viram dois intervalos
  (`22:00–24:00` e `00:00–06:00`).
- **Histórico de vínculos:** vínculos nunca são apagados, só encerrados. Uma pessoa
  pode ter vínculos com várias empresas e mais de um perfil na mesma empresa.
- **Trava de consentimento no banco:** um trigger impede gravar ou reativar
  biometria sem consentimento **ativo da própria pessoa**. Biometria excluída tem o
  template e o nonce apagados de fato, não só marcados.
- **Menores de 18 anos:** o consentimento exige nome, CPF válido e parentesco do responsável.
- **Termo imutável:** depois de publicado, o texto do termo não muda; o hash SHA-256
  é conferido pelo banco. Para mudar o texto, publique uma nova versão.
- **Foto facial:** a tabela `foto_facial` **não** foi criada. O padrão é guardar só o
  template. Ela só será criada se o fabricante da catraca exigir a foto para gerar o template.
- **Anonimização:** depois do prazo de retenção, a pessoa é anonimizada (CPF removido),
  e não excluída, para que os eventos de acesso continuem consistentes.
- **Permissões do papel `cadgestor_app`:** não pode apagar nem alterar `auditoria` e
  `evento_acesso`; não pode apagar `pessoa`, `vinculo`, `consentimento` e `biometria`;
  não pode criar nem alterar tabelas. Exclusões por retenção rodam com o papel de manutenção.
- **Atenção com NULL em CHECK:** no PostgreSQL, um CHECK cujo resultado é NULL é
  **aceito**. Por isso as constraints usam `IS NOT NULL` explicitamente (ver migração 0008).

> **Base legal (validar com o jurídico):** todos os perfis nascem com
> `base_legal = 'consentimento'` e `base_legal_validada = false`. O banco exige
> consentimento para **qualquer** biometria, como pede o escopo. Se o jurídico
> definir outra base legal para algum perfil (ex.: art. 11, II, "g" para
> funcionários), será preciso uma migração para ajustar essa trava.

## Roadmap

- [x] Fase 0: estrutura, Composer, .env, conexão PDO, README
- [x] Fase 1: migrações e seeds
- [x] Fase 2: autenticação, papéis, CSRF, sessão
- [x] Fase 3: empresas
- [x] Fase 4: perfis e catracas
- [x] Fase 5: pessoas, vínculos e regras de acesso
- [x] Fase 6: consentimento LGPD e biometria cifrada
- [x] Fase 7: API da catraca e motor de decisão
- [x] Fase 8: relatórios, CSV e auditoria
- [x] Fase 9: jobs de manutenção, testes e revisão de segurança
- [x] Multi-tenant: organizações isoladas por RLS
- [x] Comercial: planos, cadastro self-service, assinatura, cobrança (gateway simulado) e régua de inadimplência
