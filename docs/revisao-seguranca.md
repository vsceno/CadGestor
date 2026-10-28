# Revisão de segurança e LGPD — CadGestor (Fase 9)

Data: 27/10/2026 · Escopo: código da `main` após a Fase 9.

## 1. Checklist do escopo (seção 5)

| Requisito | Situação | Onde |
|---|---|---|
| Biometria só com consentimento ativo | ✅ Trava na aplicação **e** no banco (trigger) | `BiometriaServico::motivoBloqueio`, migração 0005 |
| Consentimento específico, versão, data, hora e IP | ✅ Inclui o hash do termo e o operador | `ConsentimentoServico`, tabela `consentimento` |
| Revogação remove de todos os dispositivos | ✅ Apaga os templates e agenda `remover` na fila | `ConsentimentoServico::revogar`, `SincronizacaoServico` |
| Base legal configurável por perfil, com aviso jurídico | ✅ Campo e sinalização "aguardando jurídico" | `perfil_vinculo`, telas de Perfis e README |
| Menores: consentimento do responsável; idade desconhecida bloqueia | ✅ | `Dominio\Idade`, `ConsentimentoServico` |
| Só templates cifrados (AES-256-GCM ou equivalente), chave fora do banco | ✅ XChaCha20-Poly1305 + KDF + AAD, com rotação de chave | `CifradorBiometria`, `bin/gerar-chave.php` |
| Nunca gravar imagem bruta de digital | ✅ Nunca é recebida; a foto facial só existe em memória | `LgpdController`, `BiometriaServico` |
| Retenção configurável (30 dias) com job e auditoria | ✅ Anonimização mais exclusão de biometria | `PoliticaRetencao`, `bin/manutencao.php` |
| `password_hash` com Argon2id; tokens só como hash | ✅ | `HashSenha`, `CatracaServico::hashToken` |
| Prepared statements em todas as consultas | ✅ Ver item 2.2 | `src/Repositorios`, `src/Acesso` |
| Escape de saída HTML | ✅ Ver item 2.1 | `views/`, `e()` |
| Validação no servidor | ✅ Serviços validam tudo, e o banco tem CHECKs | `src/Servicos`, migrações |
| CSRF em formulários | ✅ Todo POST web | `VerificaCsrf` |
| Cookies HttpOnly, Secure e SameSite | ✅ `Strict`; `Secure` ligado via `SESSAO_SEGURA` | `Sessao` |
| Controle de acesso por papel em cada rota | ✅ Middlewares por grupo de rotas | `config/rotas.php`, `ExigePapel` |
| Logs sem dados pessoais; sem stack trace em produção | ✅ O log guarda só classe, arquivo, linha e referência | `TratadorErros`, `Log` |
| Sem dados pessoais em URLs | ✅ Parâmetros só UUID; busca por POST | `Roteador`, `PessoaController` |
| Isolamento entre organizações (multi-tenant) | ✅ RLS, FKs compostas e trigger de organização fixa; ver item 2.10 | migração 0010, `ContextoBanco` |

## 2. Verificações executadas

### 2.1 Saída HTML (XSS)
Varri todas as expressões `<?= ... ?>` das views. As que não passam por `e()` são
ternários que só produzem textos fixos (classes CSS, rótulos) ou concatenações em que a
parte dinâmica já passa por `e()`. Não encontrei nenhuma saída de dado do usuário sem escape.
A CSP sem `unsafe-inline` é uma segunda barreira.

### 2.2 SQL
Todas as consultas usam *prepared statements* nativos (`ATTR_EMULATE_PREPARES=false`). Os
trechos montados com interpolação usam **somente** fragmentos fixos do código: listas
de colunas em constantes, filtros com placeholders, o nome do schema validado por regex e
números constantes. Nenhum valor vindo do usuário é interpolado.

### 2.3 Permissões no banco
O papel `cadgestor_app` **não** consegue:
- `UPDATE` ou `DELETE` em `auditoria` e `evento_acesso`;
- `DELETE` em `pessoa`, `vinculo`, `consentimento` e `biometria`;
- DDL (criar ou apagar tabelas).

Isso é coberto pelo teste `PermissoesAppTest`.

### 2.4 Autenticação e sessão
- Argon2id; resposta e tempo iguais para e-mail inexistente; bloqueio após 5 falhas por e-mail ou 20 por IP em 15 minutos.
- O ID de sessão muda no login e na troca de senha; a sessão expira por inatividade e após 12 horas; usuário desativado perde o acesso na hora.
- O token CSRF é renovado no login. Sem token, a resposta é 403.

### 2.5 API das catracas
- Tokens de 256 bits (`cgt_` + 64 hex), guardados como SHA-256. A troca de token invalida o anterior.
- Respostas sem dados pessoais. Sem sessão nem cookie. Sem CORS (navegadores de outra origem não conseguem chamar a API).
- Token inválido gera 401, evento e alerta, com deduplicação e limite de 30 registros por minuto.
- `referencia` precisa ser um UUID válido (tentativas de injeção viram "ninguém reconhecido").
- O corpo JSON é lido com limite de 1 MB; a amostra, até 512 KB em base64.

### 2.6 Uploads
- Só a foto facial: o tipo é conferido pelo **conteúdo** (`finfo`), apenas JPEG, PNG ou WebP, até 5 MB.
- O arquivo temporário é apagado na hora e a variável é zerada (`sodium_memzero`).
- `LimitRequestBody 10 MB` no `.htaccess` (o `php.ini` do Laragon aceita até 2 GB).

### 2.7 CSV
Proteção contra injeção de fórmula (`= + - @` e tabulação) em `Nucleo\Csv`, com teste. CPF mascarado.

### 2.8 Dependências
`composer audit`: nenhuma vulnerabilidade conhecida (phpdotenv 5.8; PHPUnit e PHPCS só em desenvolvimento).

### 2.9 Cabeçalhos HTTP
CSP restritiva, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`
(câmera só no próprio site), `Cache-Control: no-store` nas páginas internas, HSTS em HTTPS.
`X-Powered-By` é removido pela aplicação.

### 2.10 Multi-tenant (migração 0010)
- RLS em todas as tabelas de organização. O contexto vem do servidor (sessão ou token da catraca), nunca da requisição.
- Sem contexto, nada aparece (falha fechada).
- FKs compostas `(organizacao_id, id)` impedem referenciar registros de outro cliente, mesmo com o UUID conhecido.
- O trigger `impedir_troca_organizacao` bloqueia a mudança de organização de qualquer registro.
- O papel `plataforma` é exclusivo de usuários sem organização (trigger `verificar_papel_usuario`).
- Funções `SECURITY DEFINER` com `search_path` fixo, executáveis só por `cadgestor_app`, devolvendo apenas identificadores ou contagens.
- `cg_resumo_organizacoes` exige o modo plataforma.
- `cg_provisionar_organizacao` não pode ser usada por uma organização para alterar outra.
- Teste no navegador: um administrador do cliente B, usando os UUIDs reais de empresa e pessoa do cliente A, recebeu 404 em todas as leituras. Nenhuma escrita teve efeito (conferido no banco).
- Cobertura automatizada: `IsolamentoOrganizacaoTest` (conexão real da aplicação, sujeita ao RLS), `AutenticacaoTest` e `OrganizacaoServicoTest`.
- Corrigido junto: "registro não encontrado" vindo de um serviço passou a responder 404 em vez de 500 (`Aplicacao::atender`).

**Riscos residuais:**
- O papel dono (`cadgestor_owner`) ignora o RLS. Os jobs filtram por organização no código e precisam de cuidado ao evoluir.
- O modo suporte dá à plataforma acesso aos dados pessoais do cliente. Ele é auditado, mas o contrato com o cliente deve prever esse acesso (operador x controlador, LGPD art. 39).
- `/diagnostico` (só fora de produção) mostra contagens de linhas das tabelas do sistema todo, com base em estatísticas do PostgreSQL.

## 3. Correções feitas nesta revisão

1. Remoção do cabeçalho `X-Powered-By` (expunha a versão do PHP).
2. HSTS quando a conexão é HTTPS.
3. `LimitRequestBody` de 10 MB.

Também corrigidos durante as fases (registrado nos commits):
- `CHECK` com `NULL` que aceitava evento negado sem motivo e digital sem dedo (migração 0008).
- Status 419 virava 500 no Apache (trocado por 403).
- Atribuição indevida de pessoa com confiança muito baixa na identificação pelo servidor (piso de 0,30).

## 4. Itens para a implantação em produção (fora do código)

| Item | Ação |
|---|---|
| HTTPS | Obrigatório, porque a API trafega templates. `SESSAO_SEGURA=true` |
| `php.ini` | `display_errors=Off`, `expose_php=Off`, `upload_max_filesize=6M`, `post_max_size=10M`, `session.use_strict_mode=1` |
| Apache | `ServerTokens Prod`, `ServerSignature Off` (hoje o cabeçalho `Server` mostra as versões) |
| PostgreSQL | `pg_hba.conf` com `scram-sha-256` (o Laragon vem com `trust`); senhas fortes nos papéis |
| Chave de cifra | Restringir com `icacls`; backup **separado** do backup do banco; plano de rotação |
| `APP_AMBIENTE=producao` | Desliga o diagnóstico e o simulador e oculta detalhes de erro |
| Job diário | Agendar `bin/manutencao.php` (ver README) |
| Proxy reverso | Se houver, ajustar `Requisicao::ip()` para um `X-Forwarded-For` confiável |

## 5. Riscos residuais e decisões pendentes (cliente/jurídico)

1. **Base legal por perfil**: todos estão como "consentimento". Se o jurídico escolher outra base para funcionários, a trava do banco (trigger) precisará de uma migração.
2. **Prazo de retenção**: 30 dias é provisório. **Eventos de acesso** não são apagados; o titular é anonimizado e os eventos ficam sem identificação. O prazo dos eventos precisa ser definido.
3. **Fabricante da catraca**: formato do template, matching no dispositivo ou no servidor, 1:N ou 1:1, necessidade da foto e capacidade offline. O `IdentificadorSimulado` **não** pode ir para produção.
4. **Emergência/incêndio**: a liberação deve ser por hardware (intertravamento com o alarme), independente do software.
5. **Termo de consentimento**: a versão 1.0 é um modelo e precisa de revisão jurídica.
6. **Templates em trânsito**: a sincronização envia o template decifrado ao dispositivo (é como as catracas funcionam). A proteção depende de HTTPS e da guarda do token.
