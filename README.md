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
bin/            scripts de linha de comando (migrar, seed, criar-admin; jobs na Fase 9)
config/         rotas e configurações
database/
  setup/        criação de papéis, banco e schema (executar uma vez)
  migracoes/    migrações SQL versionadas (Fase 1)
  seeds/        dados iniciais (Fase 1)
public/         ÚNICA pasta exposta pelo Apache (index.php, assets)
src/            código da aplicação (namespace CadGestor\)
  Nucleo/       roteador, requisição, resposta, ambiente, banco, erros, log
  Banco/        migrador e executor de seeds
  Seguranca/    hash de senha (e, nas próximas fases, CSRF, sessão, cifra)
  Servicos/     regras de negócio
  Http/         controllers
views/          templates PHP
storage/
  logs/         logs de erro (sem dados pessoais)
  chaves/       chave de cifra da biometria (fora do git)
tests/          PHPUnit
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
php bin/seed.php              # perfis padrão (pode rodar várias vezes)
php bin/criar-admin.php --nome="Nome Completo" --email=admin@empresa.com.br
```

- **Migrações só andam para frente.** Cada arquivo aplicado fica registrado com
  o checksum. Se você editar uma migração que já rodou, o migrador recusa e para.
  Correções sempre entram como uma nova migração (exemplo: `0008_corrige_checks_com_nulo.sql`).
- **Seeds são idempotentes**, ou seja, rodar de novo não duplica nada.
- **A senha do administrador** é gerada aleatoriamente e exibida **uma única vez**.
  Ela não fica gravada em nenhum arquivo, e a troca é obrigatória no primeiro login
  (Fase 2). O script recusa criar um segundo administrador por esse caminho.

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
| `RETENCAO_DIAS` | 9 | Dias após o fim do vínculo para excluir biometria e dados. **Provisório: validar com o jurídico** |

## Decisões técnicas

- **Datas:** o banco grava `timestamptz` em UTC; a conversão para `APP_FUSO` acontece na exibição e nas regras de horário.
- **IDs:** UUID gerado pelo banco com `uuidv7()` (PostgreSQL 18), que é ordenado pelo tempo e mantém os índices compactos.
- **Erros:** o log registra só a classe do erro, o arquivo, a linha e um código de referência, **nunca a mensagem**, porque mensagens de banco podem conter CPF e outros valores. O usuário vê só o código de referência.
- **URLs:** parâmetros de rota aceitam apenas UUID, então CPF ou nome nunca aparecem no endereço.
- **Cabeçalhos de segurança:** toda resposta inclui CSP, `X-Frame-Options: DENY`, `nosniff` e `Referrer-Policy`.

### Autenticação e permissões (Fase 2)

| Papel | Acesso |
|---|---|
| `administrador` | Tudo, incluindo usuários, perfis, termos LGPD, auditoria e diagnóstico |
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
- [ ] Fase 5: pessoas, vínculos e regras de acesso
- [ ] Fase 6: consentimento LGPD e biometria cifrada
- [ ] Fase 7: API da catraca e motor de decisão
- [ ] Fase 8: relatórios, CSV e auditoria
- [ ] Fase 9: jobs de manutenção, testes e revisão de segurança
