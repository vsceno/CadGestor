# CadGestor

Sistema de controle de acesso fÃ­sico e cadastro de pessoas vinculadas a empresas
(funcionÃ¡rios, alunos, prestadores), com liberaÃ§Ã£o de catraca por biometria digital
ou reconhecimento facial.

> **Aviso LGPD:** dados biomÃ©tricos sÃ£o dados pessoais sensÃ­veis (art. 5Âº, II, e
> art. 11 da LGPD). A base legal de cada perfil de vÃ­nculo Ã© **configurÃ¡vel**, mas
> deve ser **validada pelo jurÃ­dico do cliente** antes da implantaÃ§Ã£o. O padrÃ£o do
> sistema Ã© exigir consentimento especÃ­fico.

## Stack

| Componente | VersÃ£o |
|---|---|
| Windows + Laragon | 6 |
| Apache (Laragon) | 2.4 |
| PHP (CLI e Apache) | 8.5.5 |
| PostgreSQL | 18.6, porta 5432, schema `cadgestor` |
| Composer | 2.x |
| Testes | PHPUnit 13 |
| Estilo | PSR-12 (PHP_CodeSniffer), autoload PSR-4 |

Sem framework completo: MVC prÃ³prio e leve (roteador, controllers, services,
repositories com PDO e views em PHP puro).

## Estrutura de pastas

```
bin/            scripts de linha de comando (migrar, seed, criar-admin; jobs na Fase 9)
config/         rotas e configuraÃ§Ãµes
database/
  setup/        criaÃ§Ã£o de papÃ©is, banco e schema (executar uma vez)
  migracoes/    migraÃ§Ãµes SQL versionadas (Fase 1)
  seeds/        dados iniciais (Fase 1)
public/         ÃšNICA pasta exposta pelo Apache (index.php, assets)
src/            cÃ³digo da aplicaÃ§Ã£o (namespace CadGestor\)
  Nucleo/       roteador, requisiÃ§Ã£o, resposta, ambiente, banco, erros, log
  Banco/        migrador e executor de seeds
  Seguranca/    hash de senha (e, nas prÃ³ximas fases, CSRF, sessÃ£o, cifra)
  Servicos/     regras de negÃ³cio
  Http/         controllers
views/          templates PHP
storage/
  logs/         logs de erro (sem dados pessoais)
  chaves/       chave de cifra da biometria (fora do git)
tests/          PHPUnit
```

## InstalaÃ§Ã£o no Laragon

### 1. PrÃ©-requisitos

1. Laragon 6 com **PHP 8.5.5** selecionado (Menu > PHP > Version).
2. **PostgreSQL 18.6** selecionado e iniciado (Menu > PostgreSQL > Version).
   Se houver outra versÃ£o instalada (ex.: 14), confirme que sÃ³ a 18.6 estÃ¡ rodando na 5432:
   ```powershell
   & "C:\laragon\bin\postgresql\postgresql-18.6-3-windows-x64-binaries\bin\psql.exe" -h 127.0.0.1 -U postgres -c "select version();"
   ```
3. ExtensÃµes do PHP habilitadas em `C:\laragon\bin\php\php-8.5.5-Win32-vs17-x64\php.ini`
   (remova o `;` do inÃ­cio da linha):
   ```ini
   extension=pdo_pgsql
   extension=pgsql
   extension=mbstring
   extension=openssl
   extension=fileinfo
   extension=intl
   extension=sodium
   ```
   `json` jÃ¡ vem embutido no PHP 8. Depois, recarregue o Apache no Laragon.

### 2. CÃ³digo e dependÃªncias

```powershell
cd C:\laragon\www
git clone https://github.com/vsceno/CadGestor.git
cd CadGestor
composer install
```

### 3. Banco de dados

O banco usa **dois papÃ©is**, como numa empresa em que o arquiteto (owner) projeta
o prÃ©dio e o funcionÃ¡rio (app) sÃ³ usa as salas:

| Papel | Uso | PermissÃµes |
|---|---|---|
| `cadgestor_owner` | migraÃ§Ãµes (Fase 1) | dono do schema, cria e altera tabelas |
| `cadgestor_app` | aplicaÃ§Ã£o web e API | apenas SELECT/INSERT/UPDATE/DELETE |

Se a aplicaÃ§Ã£o for comprometida, o invasor nÃ£o consegue apagar tabelas nem mudar a estrutura.

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

O script cria os papÃ©is, o banco `cadgestor` (UTF-8, collation ICU pt-BR) e o
schema `cadgestor`, e define as permissÃµes padrÃ£o.

> No Laragon, o `pg_hba.conf` costuma vir com `trust` para conexÃµes locais, ou seja,
> sem cobrar senha. Em produÃ§Ã£o, use `scram-sha-256`.

### 4. Arquivo .env

```powershell
Copy-Item .env.example .env
```

Preencha `DB_SENHA` e `DB_MIGRACAO_SENHA` com as senhas geradas. Salve o arquivo em
**UTF-8 sem BOM**. No PowerShell 5.1, `Set-Content -Encoding utf8` grava com BOM;
prefira o editor.

### 5. Virtual host `cadgestor.test`

O vhost automÃ¡tico do Laragon aponta para a **raiz** do projeto, e o CadGestor precisa
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
gerado pelo Laragon, entÃ£o ele tem prioridade. Depois clique em **Recarregar** no Laragon,
que tambÃ©m adiciona `cadgestor.test` ao arquivo `hosts`.

Como segunda trava, o `.htaccess` da raiz do projeto nega qualquer acesso. Se algum
vhost apontar para a raiz por engano, `.env`, `vendor/` e `storage/` continuam
inacessÃ­veis.

### 6. MigraÃ§Ãµes, seeds e administrador inicial

```powershell
php bin/migrar.php --status   # lista migraÃ§Ãµes aplicadas e pendentes
php bin/migrar.php            # aplica as pendentes (usa o papel cadgestor_owner)
php bin/seed.php              # perfis padrÃ£o (pode rodar vÃ¡rias vezes)
php bin/criar-admin.php --nome="Nome Completo" --email=admin@empresa.com.br
```

- **MigraÃ§Ãµes sÃ³ andam para frente.** Cada arquivo aplicado fica registrado com
  o checksum. Se vocÃª editar uma migraÃ§Ã£o que jÃ¡ rodou, o migrador recusa e para.
  CorreÃ§Ãµes sempre entram como uma nova migraÃ§Ã£o (exemplo: `0008_corrige_checks_com_nulo.sql`).
- **Seeds sÃ£o idempotentes**, ou seja, rodar de novo nÃ£o duplica nada.
- **A senha do administrador** Ã© gerada aleatoriamente e exibida **uma Ãºnica vez**.
  Ela nÃ£o fica gravada em nenhum arquivo, e a troca Ã© obrigatÃ³ria no primeiro login
  (Fase 2). O script recusa criar um segundo administrador por esse caminho.

### 7. VerificaÃ§Ã£o

| EndereÃ§o | Esperado |
|---|---|
| http://cadgestor.test/ | Tela de login |
| http://cadgestor.test/diagnostico | (administrador, fora de produÃ§Ã£o) DiagnÃ³stico de ambiente, migraÃ§Ãµes, perfis e tabelas |
| http://cadgestor.test/saude | `{"status":"ok","banco":"conectado","schema":"cadgestor"}` |
| http://cadgestor.test/qualquer-coisa | PÃ¡gina 404 |

## Testes e padrÃ£o de cÃ³digo

```powershell
composer test       # PHPUnit (unitÃ¡rios + integraÃ§Ã£o)
composer lint       # verifica PSR-12
composer lint:fix   # corrige o que for automÃ¡tico
```

Os **testes de integraÃ§Ã£o** usam um banco separado, o `cadgestor_teste`. Crie-o uma vez:

```powershell
& "C:\laragon\bin\postgresql\postgresql-18.6-3-windows-x64-binaries\bin\psql.exe" `
    -h 127.0.0.1 -U postgres -f database/setup/02_criar_banco_teste.sql
```

A cada execuÃ§Ã£o, os testes **apagam e recriam** o schema desse banco, aplicam as
migraÃ§Ãµes e os seeds. Cada teste roda numa transaÃ§Ã£o que Ã© desfeita no final. O nome
do banco vem do `phpunit.xml` (`DB_TESTE_BANCO`), e os testes se recusam a rodar se
ele for igual ao `DB_BANCO` do `.env`. Sem o banco de teste, esses testes sÃ£o pulados.

## VariÃ¡veis do .env

| VariÃ¡vel | Fase | DescriÃ§Ã£o |
|---|---|---|
| `APP_AMBIENTE` | 0 | `desenvolvimento`, `teste` ou `producao` |
| `APP_DEBUG` | 0 | `true` mostra detalhes de erro na tela; **sempre ignorado em produÃ§Ã£o** |
| `APP_URL` | 0 | URL base (ex.: `http://cadgestor.test`) |
| `APP_FUSO` | 0 | Fuso das regras de horÃ¡rio (padrÃ£o `America/Fortaleza`) |
| `DB_HOST`, `DB_PORTA` | 0 | Servidor PostgreSQL |
| `DB_BANCO`, `DB_SCHEMA` | 0 | Banco e schema (`cadgestor`) |
| `DB_USUARIO`, `DB_SENHA` | 0 | Papel da aplicaÃ§Ã£o (`cadgestor_app`) |
| `DB_MIGRACAO_USUARIO`, `DB_MIGRACAO_SENHA` | 1 | Papel de migraÃ§Ã£o (`cadgestor_owner`) |
| `APP_SEGREDO` | 2 | Segredo (64 hex) para HMAC de identificadores tÃ©cnicos |
| `SESSAO_INATIVIDADE_MINUTOS` | 2 | Minutos sem uso atÃ© a sessÃ£o expirar (padrÃ£o 30) |
| `SESSAO_SEGURA` | 2 | Cookie de sessÃ£o sÃ³ por HTTPS (`true` em produÃ§Ã£o) |
| `CHAVE_CIFRA_ARQUIVO` | 6 | Caminho da chave de cifra dos templates biomÃ©tricos (fora de `public/`) |
| `IDENTIFICADOR_BIOMETRICO` | 6 | Adaptador do hardware: `simulado` ou o do fabricante |
| `LIMIAR_CONFIANCA` | 7 | Confiança mínima (0 a 1) para aceitar a identificação (padrão 0.80) |
| `RETENCAO_DIAS` | 9 | Dias apÃ³s o fim do vÃ­nculo para excluir biometria e dados. **ProvisÃ³rio: validar com o jurÃ­dico** |

## DecisÃµes tÃ©cnicas

- **Datas:** o banco grava `timestamptz` em UTC; a conversÃ£o para `APP_FUSO` acontece na exibiÃ§Ã£o e nas regras de horÃ¡rio.
- **IDs:** UUID gerado pelo banco com `uuidv7()` (PostgreSQL 18), que Ã© ordenado pelo tempo e mantÃ©m os Ã­ndices compactos.
- **Erros:** o log registra sÃ³ a classe do erro, o arquivo, a linha e um cÃ³digo de referÃªncia, **nunca a mensagem**, porque mensagens de banco podem conter CPF e outros valores. O usuÃ¡rio vÃª sÃ³ o cÃ³digo de referÃªncia.
- **URLs:** parÃ¢metros de rota aceitam apenas UUID, entÃ£o CPF ou nome nunca aparecem no endereÃ§o.
- **CabeÃ§alhos de seguranÃ§a:** toda resposta inclui CSP, `X-Frame-Options: DENY`, `nosniff` e `Referrer-Policy`.

### AutenticaÃ§Ã£o e permissÃµes (Fase 2)

| Papel | Acesso |
|---|---|
| `administrador` | Tudo, incluindo usuÃ¡rios, perfis, termos LGPD, auditoria e diagnÃ³stico |
| `cadastro_empresa` | Empresas, horÃ¡rios, catracas e regras de acesso por perfil |
| `cadastro_pessoa` | Pessoas, vÃ­nculos, consentimento e biometria |
| `consulta` | RelatÃ³rios, exportaÃ§Ã£o CSV e alertas (somente leitura) |

Um usuÃ¡rio pode ter vÃ¡rios papÃ©is. Os papÃ©is sÃ£o verificados **em cada rota**
pelos middlewares, e o menu sÃ³ mostra o que o usuÃ¡rio pode acessar.

- **Senhas:** Argon2id, com mÃ­nimo de 12 caracteres, recusa de senhas comuns e
  troca obrigatÃ³ria no primeiro acesso. Hashes antigos sÃ£o refeitos no login.
- **ForÃ§a bruta:** apÃ³s 5 falhas por e-mail ou 20 por IP em 15 minutos, o login Ã©
  bloqueado. O e-mail fica gravado como HMAC (`APP_SEGREDO`), nunca em texto. A
  resposta Ã© a mesma para e-mail inexistente e senha errada, inclusive no tempo.
- **SessÃ£o:** o cookie `cadgestor_sessao` usa `HttpOnly` e `SameSite=Strict`, e
  `Secure` quando `SESSAO_SEGURA=true`. O ID muda no login e na troca de senha. A
  sessÃ£o expira por inatividade (`SESSAO_INATIVIDADE_MINUTOS`) e apÃ³s 12 horas.
  UsuÃ¡rio desativado perde o acesso na hora.
- **CSRF:** todo POST exige token; sem ele, a resposta Ã© 403.
- **Auditoria:** login, falha de login, logout, troca de senha e cadastro de usuÃ¡rios.

### Empresas, perfis, catracas e regras (Fases 3 e 4)

- **Empresas:** cadastro com validaÃ§Ã£o de CNPJ (numÃ©rico e alfanumÃ©rico), CEP, UF,
  e-mail e telefone do responsÃ¡vel legal. Empresas sÃ£o inativadas, nunca apagadas.
  Em cada empresa se configuram os **perfis aceitos** e o **horÃ¡rio padrÃ£o**.
- **Perfis** (somente administrador): novos perfis entram pela tela, sem mudar
  cÃ³digo. Cada perfil registra a base legal e se ela jÃ¡ foi **validada pelo jurÃ­dico**.
- **Catracas:** ao cadastrar, o sistema gera um token (`cgt_` + 64 caracteres
  hexadecimais) exibido **uma Ãºnica vez**; o banco guarda sÃ³ o SHA-256. Ao gerar
  um novo token, o anterior para de funcionar na hora.
- **Regras de acesso:** uma matriz perfil Ã— catraca Ã— dia Ã— faixa de horÃ¡rio, como
  lista de permissÃµes (fim exclusivo; `00:00` no fim = meia-noite). Faixas sobrepostas
  na mesma catraca sÃ£o recusadas.

#### Modo de falha e funcionamento offline (requisito)

| Modo | Sem resposta do servidor | Indicado para |
|---|---|---|
| `bloquear` (padrÃ£o) | A catraca mantÃ©m o bloqueio | Ãreas restritas |
| `liberar` | A catraca libera a passagem | Fluxos onde travar causa risco |

- A API devolve o `modo_falha` de cada catraca (`GET /api/v1/configuracao`), para que o
  dispositivo saiba como agir sem conexÃ£o.
- **Offline:** o dispositivo deve guardar os eventos localmente e reenviÃ¡-los com
  `data_hora` original e `offline: true`. Eles sÃ£o gravados como eventos offline.
  A decisÃ£o offline depende do recurso do fabricante (lista local de templates) e fica
  para quando o modelo da catraca for definido.
- **EmergÃªncia/incÃªndio:** a liberaÃ§Ã£o das rotas de fuga **nÃ£o deve depender do
  software**. O correto Ã© intertravamento por hardware com o alarme (destravamento
  fÃ­sico, conforme normas do Corpo de Bombeiros). O sistema apenas registra.

### Pessoas e vÃ­nculos (Fase 5)

- **Pessoas:** CPF validado e Ãºnico (uma pessoa por CPF), nome completo, documento
  de identidade, nascimento e contato. A **busca por nome/CPF usa POST**, entÃ£o esses
  dados nÃ£o aparecem na URL, no histÃ³rico do navegador nem nos logs do Apache.
  Listagens mostram o CPF mascarado (`***.982.247-**`).
- **VÃ­nculos:** uma pessoa pode ter vÃ­nculos com vÃ¡rias empresas e mais de um perfil
  na mesma empresa. SÃ³ aparecem combinaÃ§Ãµes de empresa ativa com perfil aceito.
  VÃ­nculos sÃ£o **suspensos** ou **encerrados** (com motivo e data), nunca apagados.
- **Validade** (`Dominio\ValidadeVinculo`): um vÃ­nculo Ã© *vigente* quando o status Ã©
  `ativo` e a data estÃ¡ entre inÃ­cio e fim, **ambos inclusivos**. Fora disso ele fica
  *ainda nÃ£o iniciado*, *vencido*, *suspenso* ou *encerrado*. O painel avisa quando
  faltam 30 dias ou menos para vencer.
- **ExceÃ§Ã£o por vÃ­nculo:** regras prÃ³prias **substituem** as do perfil para aquele vÃ­nculo.
- **SincronizaÃ§Ã£o:** ao criar um vÃ­nculo, as biometrias da pessoa entram na fila para
  as catracas da empresa. Ao encerrar o Ãºltimo vÃ­nculo com uma empresa, entra na fila a
  **remoÃ§Ã£o** da pessoa das catracas dela.

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

### Modelo de dados (Fase 1)

| Grupo | Tabelas |
|---|---|
| Sistema | `usuario_sistema`, `usuario_papel`, `tentativa_login`, `auditoria`, `migracao` |
| Cadastros | `perfil_vinculo`, `empresa`, `empresa_perfil`, `empresa_horario_padrao`, `catraca` |
| Pessoas | `pessoa`, `vinculo`, `regra_acesso` |
| LGPD | `termo_consentimento`, `consentimento`, `biometria` |
| OperaÃ§Ã£o | `evento_acesso`, `alerta`, `fila_sincronizacao` |

- **CPF e CNPJ sÃ£o validados tambÃ©m no banco**, pelas funÃ§Ãµes `cpf_valido` e
  `cnpj_valido`. O CNPJ aceita o **formato alfanumÃ©rico** da Receita, vigente desde
  julho de 2026 (ex.: `12ABC34501DE35`). Os valores sÃ£o gravados sem mÃ¡scara e em maiÃºsculas.
- **Regras de acesso** funcionam como lista de permissÃµes. A hierarquia Ã©: regras
  do vÃ­nculo (exceÃ§Ã£o; se existirem, sÃ³ elas valem) â†’ regras do perfil na empresa
  â†’ horÃ¡rio padrÃ£o da empresa. Turnos que passam da meia-noite viram dois intervalos
  (`22:00â€“24:00` e `00:00â€“06:00`).
- **HistÃ³rico de vÃ­nculos:** vÃ­nculos nunca sÃ£o apagados, sÃ³ encerrados. Uma pessoa
  pode ter vÃ­nculos com vÃ¡rias empresas e mais de um perfil na mesma empresa.
- **Trava de consentimento no banco:** um trigger impede gravar ou reativar
  biometria sem consentimento **ativo da prÃ³pria pessoa**. Biometria excluÃ­da tem o
  template e o nonce apagados de fato, nÃ£o sÃ³ marcados.
- **Menores de 18 anos:** o consentimento exige nome, CPF vÃ¡lido e parentesco do responsÃ¡vel.
- **Termo imutÃ¡vel:** depois de publicado, o texto do termo nÃ£o muda; o hash SHA-256
  Ã© conferido pelo banco. Para mudar o texto, publique uma nova versÃ£o.
- **Foto facial:** a tabela `foto_facial` **nÃ£o** foi criada. O padrÃ£o Ã© guardar sÃ³ o
  template. Ela sÃ³ serÃ¡ criada se o fabricante da catraca exigir a foto para gerar o template.
- **AnonimizaÃ§Ã£o:** depois do prazo de retenÃ§Ã£o, a pessoa Ã© anonimizada (CPF removido),
  e nÃ£o excluÃ­da, para que os eventos de acesso continuem consistentes.
- **PermissÃµes do papel `cadgestor_app`:** nÃ£o pode apagar nem alterar `auditoria` e
  `evento_acesso`; nÃ£o pode apagar `pessoa`, `vinculo`, `consentimento` e `biometria`;
  nÃ£o pode criar nem alterar tabelas. ExclusÃµes por retenÃ§Ã£o rodam com o papel de manutenÃ§Ã£o.
- **AtenÃ§Ã£o com NULL em CHECK:** no PostgreSQL, um CHECK cujo resultado Ã© NULL Ã©
  **aceito**. Por isso as constraints usam `IS NOT NULL` explicitamente (ver migraÃ§Ã£o 0008).

> **Base legal (validar com o jurÃ­dico):** todos os perfis nascem com
> `base_legal = 'consentimento'` e `base_legal_validada = false`. O banco exige
> consentimento para **qualquer** biometria, como pede o escopo. Se o jurÃ­dico
> definir outra base legal para algum perfil (ex.: art. 11, II, "g" para
> funcionÃ¡rios), serÃ¡ preciso uma migraÃ§Ã£o para ajustar essa trava.

## Roadmap

- [x] Fase 0: estrutura, Composer, .env, conexÃ£o PDO, README
- [x] Fase 1: migraÃ§Ãµes e seeds
- [x] Fase 2: autenticaÃ§Ã£o, papÃ©is, CSRF, sessÃ£o
- [x] Fase 3: empresas
- [x] Fase 4: perfis e catracas
- [x] Fase 5: pessoas, vÃ­nculos e regras de acesso
- [x] Fase 6: consentimento LGPD e biometria cifrada
- [ ] Fase 7: API da catraca e motor de decisÃ£o
- [ ] Fase 8: relatÃ³rios, CSV e auditoria
- [ ] Fase 9: jobs de manutenÃ§Ã£o, testes e revisÃ£o de seguranÃ§a
