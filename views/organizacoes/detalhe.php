<?php
/**
 * @var array<string, mixed> $organizacao
 * @var bool $cadastroCompleto
 * @var array<string, mixed>|null $assinatura
 * @var \CadGestor\Dominio\SituacaoAssinatura $situacao
 * @var list<array<string, mixed>> $faturas
 * @var list<array<string, mixed>> $planos
 * @var list<array<string, mixed>> $usuarios
 * @var array<string, bool> $loginsTravados
 * @var list<array<string, mixed>> $historico
 * @var int $diasMaximosExtensao
 * @var array<string, string> $novoAdmin
 * @var array<string, string> $erros
 */

use CadGestor\Dominio\SituacaoAssinatura;
use CadGestor\Seguranca\Papel;

$id = (string) $organizacao['id'];
$base = '/organizacoes/' . $id;
$cancelada = ($assinatura['status'] ?? '') === 'cancelada';
$rotuloStatus = ['teste' => 'Teste grátis', 'ativa' => 'Ativa', 'inadimplente' => 'Inadimplente', 'cancelada' => 'Cancelada'];
$rotuloFatura = ['pendente' => 'A pagar', 'paga' => 'Paga', 'vencida' => 'Vencida', 'cancelada' => 'Cancelada'];
$classeFatura = ['pendente' => 'selo-aviso', 'paga' => 'selo-ok', 'vencida' => 'selo-falha', 'cancelada' => 'selo-neutro'];
$classeNivel = [
    SituacaoAssinatura::NORMAL => 'selo-ok',
    SituacaoAssinatura::SOMENTE_LEITURA => 'selo-aviso',
    SituacaoAssinatura::SUSPENSA => 'selo-aviso',
    SituacaoAssinatura::BLOQUEADA => 'selo-falha',
];
$rotuloNivel = [
    SituacaoAssinatura::NORMAL => 'Acesso normal',
    SituacaoAssinatura::SOMENTE_LEITURA => 'Somente leitura',
    SituacaoAssinatura::SUSPENSA => 'Suspenso',
    SituacaoAssinatura::BLOQUEADA => 'Bloqueado',
];
$rotuloCampo = [
    'nome' => 'razão social', 'cnpj' => 'CNPJ', 'nome_fantasia' => 'nome fantasia',
    'inscricao_municipal' => 'inscrição municipal', 'inscricao_estadual' => 'inscrição estadual', 'cep' => 'CEP',
    'logradouro' => 'logradouro', 'numero' => 'número', 'complemento' => 'complemento', 'bairro' => 'bairro',
    'municipio' => 'município', 'uf' => 'UF', 'telefone' => 'telefone', 'email_financeiro' => 'e-mail financeiro',
    'contato_nome' => 'responsável comercial', 'contato_telefone' => 'telefone do responsável',
];
$rotuloEvento = [
    'cobranca_criada' => 'Cobrança gerada', 'pagamento_confirmado' => 'Pagamento confirmado pelo gateway',
    'cobranca_vencida' => 'Cobrança vencida', 'cobranca_cancelada' => 'Cobrança cancelada',
    'baixa_manual' => 'Baixa manual de pagamento',
];
$rotuloOrigem = ['plataforma' => 'Plataforma', 'cliente' => 'Cliente', 'sistema' => 'Sistema'];
$o = $organizacao;
$nomePlano = array_column($planos, 'nome', 'codigo');
$nomeDoPlano = static fn (?string $codigo): string => $nomePlano[$codigo] ?? (string) $codigo;
$telefone = static fn (?string $t): string => $t === null ? '—' : formatar_telefone($t);

/** Texto de uma linha do histórico, montado só com as chaves permitidas que o banco devolve. */
$descrever = static function (array $h) use ($rotuloCampo, $rotuloEvento, $nomeDoPlano): string {
    $antes = $h['antes'];
    $depois = $h['depois'];

    return match ($h['acao']) {
        'nota' => 'Anotação',
        'cadastro_cliente' => 'Contratou pelo site · plano ' . $nomeDoPlano($depois['plano'] ?? null)
            . (isset($depois['teste_ate']) ? ' · teste até ' . formatar_data($depois['teste_ate']) : ''),
        'email_confirmado' => 'E-mail do administrador confirmado',
        'assinatura_alterada' => match (true) {
            isset($depois['plano']) => 'Plano alterado: ' . $nomeDoPlano($antes['plano'] ?? null) . ' → ' . $nomeDoPlano($depois['plano']),
            isset($depois['teste_ate']) => 'Teste grátis estendido até ' . formatar_data($depois['teste_ate'])
                . (isset($depois['dias']) ? " (+{$depois['dias']} dias)" : ''),
            ($depois['status'] ?? '') === 'cancelada' => 'Assinatura cancelada',
            default => 'Assinatura alterada',
        },
        'pagamento' => ($rotuloEvento[$depois['evento'] ?? ''] ?? 'Pagamento')
            . (isset($depois['valor_centavos']) ? ' · ' . formatar_moeda((int) $depois['valor_centavos']) : '')
            . (isset($depois['vencimento']) ? ' · vencimento ' . formatar_data($depois['vencimento']) : ''),
        'criar' => $h['tabela'] === 'organizacao'
            ? 'Cliente cadastrado (venda manual)' . (isset($depois['plano']) ? ' · plano ' . $nomeDoPlano($depois['plano']) : '')
            : 'Administrador cadastrado pela plataforma',
        'acesso_suporte' => 'Acesso de suporte (recurso já removido)',
        'alterar' => match (true) {
            $h['tabela'] === 'usuario_sistema' => ($depois['ativo'] ?? null) === true
                ? 'Administrador reativado' : 'Administrador desativado',
            array_key_exists('ativo', $depois) => $depois['ativo'] ? 'Cliente desbloqueado' : 'Cliente bloqueado',
            isset($depois['campos']) => 'Cadastro alterado: ' . implode(', ', array_map(
                static fn (string $c): string => $rotuloCampo[$c] ?? $c,
                (array) $depois['campos'],
            )),
            default => 'Cadastro alterado',
        },
        'redefinicao_solicitada' => match ($depois['via'] ?? '') {
            'novo_administrador' => 'Link para criar a primeira senha enviado ao administrador',
            'plataforma' => 'Link de redefinição de senha enviado pela plataforma',
            default => 'Administrador pediu redefinição de senha',
        },
        'confirmacao_reenviada' => 'Link de confirmação de e-mail reenviado',
        'login_desbloqueado' => 'Login do administrador desbloqueado',
        default => ucfirst(str_replace('_', ' ', (string) $h['acao'])),
    };
};
?>
<nav class="trilha" aria-label="Você está em"><a href="/organizacoes">Clientes</a><span>/</span><?= e($o['nome']) ?></nav>

<section class="cartao cabecalho-cliente">
    <div class="cabecalho-pagina">
        <div>
            <h1><?= e($o['nome']) ?></h1>
            <p class="subtitulo">
                <?php if ($o['nome_fantasia']) : ?><?= e($o['nome_fantasia']) ?> · <?php endif; ?>
                <?= $o['cnpj'] ? 'CNPJ ' . e(formatar_cnpj((string) $o['cnpj'])) : 'Sem CNPJ' ?>
                · cliente desde <?= e(formatar_data(substr((string) $o['created_at'], 0, 10))) ?>
            </p>
            <div class="selos">
                <span class="selo <?= e($classeNivel[$situacao->nivel]) ?>"><?= e($rotuloNivel[$situacao->nivel]) ?></span>
                <?php if ($assinatura !== null) : ?>
                    <span class="selo selo-neutro"><?= e($rotuloStatus[$assinatura['status']] ?? $assinatura['status']) ?></span>
                <?php endif; ?>
                <?php if (!$o['ativo']) : ?><span class="selo selo-falha">Bloqueado pela plataforma</span><?php endif; ?>
            </div>
        </div>
        <div class="acoes">
            <a class="botao" href="<?= e($base) ?>/editar">Editar cadastro</a>
            <form method="post" action="<?= e($base) ?>/situacao" class="form-inline"
                  data-confirmar="<?= $o['ativo']
                      ? 'Bloquear o cliente? Os usuários dele não conseguirão entrar e as catracas serão recusadas.'
                      : 'Desbloquear o cliente?' ?>">
                <?= csrf_campo() ?>
                <input type="hidden" name="ativo" value="<?= $o['ativo'] ? '0' : '1' ?>">
                <button type="submit" class="botao <?= $o['ativo'] ? 'botao-perigo' : '' ?>">
                    <?= $o['ativo'] ? 'Bloquear' : 'Desbloquear' ?>
                </button>
            </form>
        </div>
    </div>

    <?php if (!$cadastroCompleto) : ?>
        <div class="alerta alerta-aviso" role="status">
            Cadastro incompleto: para emitir a nota fiscal são necessários CNPJ, endereço completo, telefone e
            e-mail financeiro.
            <a href="<?= e($base) ?>/editar">Completar cadastro</a>
        </div>
    <?php endif; ?>
</section>

<div class="duas-colunas">
    <div class="coluna-principal">
        <section class="cartao">
            <h2>Cadastro</h2>
            <dl class="dados">
                <dt>Razão social</dt><dd><?= e($o['nome']) ?></dd>
                <dt>Nome fantasia</dt><dd><?= e($o['nome_fantasia'] ?? '—') ?></dd>
                <dt>CNPJ</dt><dd><?= $o['cnpj'] ? e(formatar_cnpj((string) $o['cnpj'])) : '—' ?></dd>
                <dt>Inscrição municipal</dt><dd><?= e($o['inscricao_municipal'] ?? '—') ?></dd>
                <dt>Inscrição estadual</dt><dd><?= e($o['inscricao_estadual'] ?? '—') ?></dd>
                <dt>Endereço</dt>
                <dd>
                    <?php if ($o['cep']) : ?>
                        <?= e($o['logradouro']) ?>, <?= e($o['numero']) ?><?= $o['complemento'] ? ' · ' . e($o['complemento']) : '' ?><br>
                        <?= e($o['bairro']) ?> · <?= e($o['municipio']) ?>/<?= e($o['uf']) ?> · CEP <?= e(formatar_cep((string) $o['cep'])) ?>
                    <?php else : ?>—<?php endif; ?>
                </dd>
                <dt>Telefone</dt><dd><?= e($telefone($o['telefone'])) ?></dd>
                <dt>E-mail financeiro</dt><dd><?= e($o['email_financeiro'] ?? '—') ?></dd>
                <dt>Responsável comercial</dt>
                <dd><?= e($o['contato_nome'] ?? '—') ?><?= $o['contato_telefone'] ? ' · ' . e($telefone($o['contato_telefone'])) : '' ?></dd>
                <dt>Termos de Uso</dt>
                <dd><?= $o['termos_versao']
                    ? 'Versão ' . e($o['termos_versao']) . ' aceita em ' . e(formatar_data_hora((string) $o['termos_aceitos_em']))
                    : 'Contrato próprio (venda manual)' ?></dd>
            </dl>
        </section>

        <section class="cartao">
            <h2>Faturas</h2>
            <?php if ($faturas === []) : ?>
                <p class="vazio">Nenhuma fatura. Clientes de venda manual são cobrados fora do sistema.</p>
            <?php else : ?>
                <div class="tabela-rolagem">
                    <table class="tabela">
                        <thead><tr><th>Vencimento</th><th class="numero">Valor</th><th>Situação</th><th>Pago em</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($faturas as $f) : ?>
                            <tr>
                                <td><?= e(formatar_data($f['vencimento'])) ?></td>
                                <td class="numero"><?= e(formatar_moeda((int) $f['valor_centavos'])) ?></td>
                                <td><span class="selo <?= e($classeFatura[$f['status']] ?? 'selo-neutro') ?>"><?= e($rotuloFatura[$f['status']] ?? $f['status']) ?></span></td>
                                <td><?= e(formatar_data_hora($f['pago_em']) ?: '—') ?></td>
                                <td class="celula-acao">
                                    <?php if (in_array($f['status'], ['pendente', 'vencida'], true)) : ?>
                                        <details class="menu-acao">
                                            <summary class="botao botao-pequeno">Dar baixa</summary>
                                            <form method="post" action="<?= e($base) ?>/faturas/<?= e($f['id']) ?>/baixa" class="formulario painel-acao"
                                                  data-confirmar="Registrar esta fatura como paga?">
                                                <?= csrf_campo() ?>
                                                <label for="motivo-<?= e($f['id']) ?>">Como foi pago?</label>
                                                <input type="text" id="motivo-<?= e($f['id']) ?>" name="motivo" required minlength="5" maxlength="200"
                                                       placeholder="Ex.: Pix recebido em 10/11">
                                                <p class="dica">Aparece na auditoria do cliente. Não escreva dados pessoais.</p>
                                                <button type="submit" class="botao botao-primario botao-pequeno">Confirmar pagamento</button>
                                            </form>
                                        </details>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="cartao">
            <h2>Administradores do cliente</h2>
            <p class="dica">Os demais usuários são gerenciados pelo administrador do próprio cliente. A plataforma
                nunca vê nem define senhas: ela envia um link para o e-mail da pessoa.</p>
            <div class="tabela-rolagem">
                <table class="tabela">
                    <thead><tr><th>Nome</th><th>E-mail</th><th>Situação</th><th>Último login</th><th></th></tr></thead>
                    <tbody>
                    <?php $administradores = array_filter(
                        $usuarios,
                        static fn (array $u): bool => in_array(Papel::Administrador->value, explode(',', (string) $u['papeis']), true),
                    ); ?>
                    <?php if ($administradores === []) : ?>
                        <tr><td colspan="5" class="vazio">Nenhum administrador. Cadastre um abaixo.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($administradores as $u) : ?>
                        <?php $uid = (string) $u['id']; $acaoUsuario = $base . '/usuarios/' . $uid; ?>
                        <tr>
                            <td><?= e($u['nome']) ?></td>
                            <td><?= e($u['email']) ?></td>
                            <td>
                                <span class="selo <?= $u['ativo'] ? 'selo-ok' : 'selo-neutro' ?>"><?= $u['ativo'] ? 'Ativo' : 'Inativo' ?></span>
                                <?php if ($u['email_verificado_em'] === null) : ?><span class="selo selo-aviso">E-mail não confirmado</span><?php endif; ?>
                                <?php if ($loginsTravados[$uid] ?? false) : ?><span class="selo selo-falha">Login travado</span><?php endif; ?>
                            </td>
                            <td><?= e(formatar_data_hora($u['ultimo_login_em']) ?: '—') ?></td>
                            <td class="celula-acao">
                                <details class="menu-acao">
                                    <summary class="botao botao-pequeno">Ações</summary>
                                    <div class="painel-acao lista-acoes">
                                        <?php if ($u['ativo']) : ?>
                                            <form method="post" action="<?= e($acaoUsuario) ?>/redefinicao"
                                                  data-confirmar="Enviar para <?= e($u['email']) ?> um link para criar uma nova senha?">
                                                <?= csrf_campo() ?>
                                                <button type="submit" class="botao-menu">Enviar link de nova senha</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($u['email_verificado_em'] === null) : ?>
                                            <form method="post" action="<?= e($acaoUsuario) ?>/confirmacao">
                                                <?= csrf_campo() ?>
                                                <button type="submit" class="botao-menu">Reenviar confirmação de e-mail</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($loginsTravados[$uid] ?? false) : ?>
                                            <form method="post" action="<?= e($acaoUsuario) ?>/desbloqueio">
                                                <?= csrf_campo() ?>
                                                <button type="submit" class="botao-menu">Desbloquear login</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" action="<?= e($acaoUsuario) ?>/situacao"
                                              data-confirmar="<?= $u['ativo'] ? 'Desativar este administrador? Ele não conseguirá mais entrar.' : 'Reativar este administrador?' ?>">
                                            <?= csrf_campo() ?>
                                            <input type="hidden" name="ativo" value="<?= $u['ativo'] ? '0' : '1' ?>">
                                            <button type="submit" class="botao-menu <?= $u['ativo'] ? 'perigo' : '' ?>">
                                                <?= $u['ativo'] ? 'Desativar' : 'Reativar' ?>
                                            </button>
                                        </form>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <details class="expansivel"<?= $erros !== [] ? ' open' : '' ?>>
                <summary>Cadastrar novo administrador</summary>
                <form method="post" action="<?= e($base) ?>/administradores" class="formulario">
                    <?= csrf_campo() ?>
                    <p class="dica">Use quando o cliente perder o acesso de administrador (ex.: saída do responsável).
                        A pessoa recebe no e-mail um link para criar a própria senha.</p>
                    <div class="grade">
                        <div>
                            <label for="nome">Nome completo</label>
                            <input type="text" id="nome" name="nome" value="<?= e($novoAdmin['nome'] ?? '') ?>" required maxlength="150"
                                <?= invalido($erros, 'nome') ?>>
                            <?= erro_campo($erros, 'nome') ?>
                        </div>
                        <div>
                            <label for="email">E-mail</label>
                            <input type="email" id="email" name="email" value="<?= e($novoAdmin['email'] ?? '') ?>" required
                                   maxlength="254"<?= invalido($erros, 'email') ?>>
                            <?= erro_campo($erros, 'email') ?>
                            <?= erro_campo($erros, 'papeis') ?>
                        </div>
                    </div>
                    <div class="acoes"><button type="submit" class="botao botao-primario">Criar e enviar link</button></div>
                </form>
            </details>
        </section>
    </div>

    <aside class="coluna-lateral">
        <section class="cartao">
            <h2>Assinatura</h2>
            <?php if ($assinatura === null) : ?>
                <p class="vazio">Sem assinatura.</p>
            <?php else : ?>
                <div class="destaque-plano">
                    <span class="destaque-nome"><?= e($assinatura['plano_nome']) ?></span>
                    <span class="destaque-preco"><?= e(formatar_moeda((int) $assinatura['preco_centavos'])) ?><small>/mês</small></span>
                </div>
                <dl class="dados dados-compactos">
                    <dt>Status</dt>
                    <dd>
                        <?= e($rotuloStatus[$assinatura['status']] ?? $assinatura['status']) ?>
                        <?php if ($assinatura['teste_ate']) : ?>até <?= e(formatar_data((string) $assinatura['teste_ate'])) ?><?php endif; ?>
                        <?php if ($assinatura['inadimplente_desde']) : ?>desde <?= e(formatar_data((string) $assinatura['inadimplente_desde'])) ?><?php endif; ?>
                    </dd>
                    <dt>Acesso</dt><dd><?= e($situacao->aviso ?? 'Normal') ?></dd>
                    <dt>Limites</dt><dd><?= e($assinatura['limite_pessoas']) ?> pessoas · <?= e($assinatura['limite_catracas']) ?> catracas</dd>
                    <dt>Cobrança</dt><dd><?= $assinatura['gateway'] ? 'Gateway (' . e($assinatura['gateway']) . ')' : 'Manual (sem gateway)' ?></dd>
                </dl>

                <?php if (!$cancelada) : ?>
                    <details class="expansivel">
                        <summary>Trocar plano</summary>
                        <form method="post" action="<?= e($base) ?>/plano" class="formulario">
                            <?= csrf_campo() ?>
                            <label for="plano_id">Novo plano</label>
                            <select id="plano_id" name="plano_id" required>
                                <?php foreach ($planos as $plano) : ?>
                                    <?php if ($plano['id'] === $assinatura['plano_id']) { continue; } ?>
                                    <option value="<?= e($plano['id']) ?>"><?= e($plano['nome']) ?> · <?= e(formatar_moeda((int) $plano['preco_centavos'])) ?><?= $plano['disponivel_venda'] ? '' : ' (fora de venda)' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label for="motivo-plano">Motivo</label>
                            <input type="text" id="motivo-plano" name="motivo" required minlength="5" maxlength="200"
                                   placeholder="Ex.: upgrade negociado por telefone">
                            <p class="dica">O cliente vê o motivo na auditoria dele. O uso atual precisa caber no plano novo.</p>
                            <button type="submit" class="botao botao-primario">Trocar plano</button>
                        </form>
                    </details>
                <?php endif; ?>

                <?php if ($assinatura['status'] === 'teste') : ?>
                    <details class="expansivel">
                        <summary>Estender teste grátis</summary>
                        <form method="post" action="<?= e($base) ?>/teste" class="formulario">
                            <?= csrf_campo() ?>
                            <label for="dias">Dias a mais</label>
                            <input type="number" id="dias" name="dias" required min="1" max="<?= e($diasMaximosExtensao) ?>" value="7">
                            <label for="motivo-teste">Motivo</label>
                            <input type="text" id="motivo-teste" name="motivo" required minlength="5" maxlength="200"
                                   placeholder="Ex.: atraso na instalação das catracas">
                            <p class="dica">A primeira cobrança passa a vencer no dia seguinte ao novo fim do teste.</p>
                            <button type="submit" class="botao botao-primario">Estender teste</button>
                        </form>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <section class="cartao">
            <h2>Anotações e histórico</h2>
            <form method="post" action="<?= e($base) ?>/notas" class="formulario">
                <?= csrf_campo() ?>
                <label for="texto" class="visualmente-oculto">Nova anotação</label>
                <textarea id="texto" name="texto" required minlength="3" maxlength="1000" rows="3"
                          placeholder="Anotação interna (o cliente não vê). Evite dados pessoais."></textarea>
                <button type="submit" class="botao botao-pequeno">Anotar</button>
            </form>

            <?php if ($historico === []) : ?>
                <p class="vazio">Sem eventos ainda.</p>
            <?php else : ?>
                <ol class="linha-do-tempo">
                    <?php foreach ($historico as $h) : ?>
                        <li class="evento evento-<?= e($h['origem']) ?>">
                            <div class="evento-cabecalho">
                                <span class="evento-titulo"><?= e($descrever($h)) ?></span>
                                <time><?= e(formatar_data_hora((string) $h['data_hora'])) ?></time>
                            </div>
                            <?php if ($h['acao'] === 'nota') : ?>
                                <p class="evento-texto"><?= nl2br(e($h['texto'])) ?></p>
                            <?php elseif (isset($h['depois']['motivo'])) : ?>
                                <p class="evento-texto">Motivo: <?= e($h['depois']['motivo']) ?></p>
                            <?php endif; ?>
                            <span class="evento-origem">
                                <?= e($rotuloOrigem[$h['origem']] ?? $h['origem']) ?><?= $h['autor'] ? ' · ' . e($h['autor']) : '' ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </aside>
</div>
