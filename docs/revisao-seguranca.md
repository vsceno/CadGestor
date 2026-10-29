# Revisão de segurança: versão Laravel

Estado em 2026-10-29, após a Fase 8 (branch `laravel`). Esta revisão cobre o sistema novo. A revisão do código antigo (PHP próprio) continua em `docs/revisao-seguranca.md` da versão antiga, sem alterações.

Os números entre parênteses são os testes que comprovam cada item. A suíte tem 129 testes.

## O que foi verificado

**Isolamento entre clientes**
- O banco separa os clientes por RLS (`app.organizacao_id`). A aplicação só enxerga o cliente do usuário logado.
- As rotas de binding passam pelo contexto antes da busca do modelo (senão o RLS esconderia o registro).
- A tela de plataforma só mostra contagens e dados comerciais. Nenhum dado de ambiente de cliente.
- O mesmo CPF pode existir em dois clientes, porque a unicidade é por cliente (`PessoasVinculosTest`).

**Autenticação e senhas**
- Argon2id, mensagem de login genérica, bloqueio após falhas repetidas, link de redefinição de uso único (`TelasAcessoTest`, `AutenticacaoTest`).
- Senha forte pela regra `SenhaForte`: mínimo de 12 caracteres, sem senhas comuns, sem o nome do e-mail.
- A plataforma nunca define nem vê senha de cliente: só envia link para o e-mail da própria pessoa.

**Papéis**
- Cada rota exige o papel certo. Consulta não abre cadastros, nem LGPD, nem exportação, nem auditoria (`CadastrosAcessoTest`, `PessoasVinculosTest`, `LgpdBiometriaTest`, `OperacaoTest`).

**Token da catraca**
- 256 bits aleatórios, com prefixo `cgt_`. No banco fica só o hash SHA-256. O texto aparece uma única vez, logo após a criação ou a renovação, e não entra na auditoria nem na URL.
- Renovar invalida o token anterior na hora. Token ausente ou inválido recebe 401 (`CadastrosAcessoTest`, `AcessoDispositivoTest`).

**Biometria e LGPD**
- Template cifrado com XChaCha20-Poly1305. O dado associado (pessoa, tipo e dedo) amarra o template ao registro: trocar o conteúdo entre registros faz a decifragem falhar.
- A chave fica em arquivo fora do banco e fora do git (`storage/chaves`, ignorado). Não há chave no histórico do repositório.
- Biometria ativa só com consentimento ativo: a regra vale também no banco, por trigger (`LgpdBiometriaTest`).
- Menor de 18 anos só entra com o responsável legal (nome, CPF, vínculo e aceite). Sem data de nascimento não há aceite.
- Revogar o consentimento apaga os templates de fato e agenda a remoção nas catracas.
- Termo publicado não muda (trigger). Uma nova versão não altera o aceite de quem já aceitou outra.

**Trilha de auditoria**
- Só acréscimo para a aplicação. Não grava CPF, e-mail, telefone, dados do responsável nem template (`LgpdBiometriaTest`, `PessoasVinculosTest`).
- A exportação de passagens é só do administrador e fica registrada com período e quantidade.

**Entrada e saída**
- Busca de pessoas e de eventos por POST, para o nome ou o CPF pesquisado não ir para a URL.
- Nenhum script ou estilo inline nas telas novas (a CSP é `'self'`). Nenhuma saída sem escape (`{!!`) nas telas novas.

## Correções feitas nesta revisão

- **Fuso horário das datas.** A conexão do PostgreSQL estava em UTC e a aplicação em `America/Fortaleza`. O horário de parede gravado pelo PHP era lido como UTC e ficava 3 horas no passado. Um aceite de consentimento aparecia com a hora errada. Corrigido com `timezone` na conexão (`LgpdBiometriaTest::test_datas_do_aceite_ficam_no_instante_certo`). **Dados de desenvolvimento gravados antes da correção ficaram com o deslocamento. Não há produção ainda.**
- **Vigência do termo.** A versão nova era comparada pelo segundo truncado do Eloquent e podia perder para a anterior. Passou a usar o relógio do banco com microssegundos.
- **Modelo devolvido sem `ativo`.** Empresa e catraca criadas pelo serviço voltavam sem o campo `ativo` (o default do banco não voltava). A decisão de acesso negaria tudo para quem usasse o retorno direto. Corrigido na origem.
- **Dependência da chave em telas.** Abrir a ficha de uma pessoa exigia o arquivo de chave, porque o serviço de consentimento puxava o cifrador. Separado em `ExclusaoBiometria`, que não usa a cifra.

## Pendências (decisões do cliente ou do jurídico)

1. **Máscara do CPF.** Hoje `Formato::cpfMascarado` mostra 6 dos 11 dígitos (`***.982.247-**`). Recomenda-se mostrar só os dois últimos. A função é usada também nas telas de plataforma, então a mudança precisa ser decidida.
2. **Prazo de retenção.** 30 dias é provisório (`config/manutencao.php`), herdado do legado. O prazo definitivo depende da revisão jurídica. Os eventos de acesso não têm prazo de exclusão definido: hoje ficam sem identificação depois da anonimização do titular.
3. **Revisão jurídica.** Os textos de termo (1.0 e 1.1) e os documentos públicos são modelos. A versão 1.1 usada nos testes é de teste.
4. **Chave de cifra em produção.** Hoje é um arquivo no servidor. Em produção deve ir para um cofre de segredos (KMS ou equivalente), com cópia de segurança controlada. Perder a chave significa perder os templates, que então precisam ser recadastrados pelo dispositivo.
5. **Adaptador da catraca.** O sistema define o contrato da API. O protocolo de cada fabricante e a comparação biométrica (1:N ou 1:1) dependem do modelo escolhido. A comparação acontece no dispositivo.
6. **Limite da API.** 600 requisições por minuto por token. Falta monitorar tentativas com token inválido, que hoje só ficam no log da aplicação.
7. **Emergência e incêndio.** Liberação de todas as catracas em emergência não está implementada.
8. **Sessão e autenticação em dois fatores.** Não implementados. Recomenda-se 2FA para o administrador antes de produção.

## Limitações conhecidas

- A portaria ao vivo atualiza ao recarregar a página (sem atualização automática).
- A grade visual de regras (perfis × catracas) ainda não existe: o editor é por linhas.
- Digitais e rosto só entram pela API da catraca. Não há cadastro por tela, porque a captura depende do equipamento.
- Relatórios saem em CSV, sem PDF.
