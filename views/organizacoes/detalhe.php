<?php
/**
 * @var array<string, mixed> $organizacao
 * @var array<string, mixed>|null $assinatura
 * @var \CadGestor\Dominio\SituacaoAssinatura $situacao
 * @var list<array<string, mixed>> $faturas
 * @var list<array<string, mixed>> $usuarios
 * @var array<string, string> $novoAdmin
 * @var array<string, string> $erros
 */

use CadGestor\Seguranca\Papel;

$id = (string) $organizacao['id'];
?>
<section class="cartao">
    <div class="cabecalho-pagina">
        <div>
            <h1><?= e($organizacao['nome']) ?></h1>
            <p class="subtitulo">
                Cliente desde <?= e(formatar_data(substr((string) $organizacao['created_at'], 0, 10))) ?>
                <?php if (!$organizacao['ativo']) : ?>· <span class="selo selo-falha">Bloqueado pela plataforma</span><?php endif; ?>
            </p>
        </div>
        <div class="acoes">
            <a class="botao" href="/organizacoes/<?= e($id) ?>/editar">Renomear</a>
            <form method="post" action="/organizacoes/<?= e($id) ?>/situacao" class="form-inline"
                  data-confirmar="<?= $organizacao['ativo']
                      ? 'Bloquear o cliente? Os usuários dele não conseguirão entrar e as catracas serão recusadas.'
                      : 'Desbloquear o cliente?' ?>">
                <?= csrf_campo() ?>
                <input type="hidden" name="ativo" value="<?= $organizacao['ativo'] ? '0' : '1' ?>">
                <button type="submit" class="botao <?= $organizacao['ativo'] ? 'botao-perigo' : '' ?>">
                    <?= $organizacao['ativo'] ? 'Bloquear' : 'Desbloquear' ?>
                </button>
            </form>
        </div>
    </div>

    <h2>Assinatura</h2>
    <?php if ($assinatura === null) : ?>
        <p>Sem assinatura.</p>
    <?php else : ?>
        <dl class="dados">
            <dt>Plano</dt>
            <dd><?= e($assinatura['plano_nome']) ?> · <?= e(formatar_moeda((int) $assinatura['preco_centavos'])) ?>/mês</dd>
            <dt>Status</dt>
            <dd>
                <?= e($assinatura['status']) ?>
                <?php if ($assinatura['teste_ate']) : ?>(teste até <?= e(formatar_data((string) $assinatura['teste_ate'])) ?>)<?php endif; ?>
                <?php if ($assinatura['inadimplente_desde']) : ?>(em atraso desde <?= e(formatar_data((string) $assinatura['inadimplente_desde'])) ?>)<?php endif; ?>
            </dd>
            <dt>Situação de acesso</dt>
            <dd><?= e($situacao->aviso ?? 'Normal') ?></dd>
            <dt>Cobrança</dt>
            <dd><?= $assinatura['gateway'] ? e($assinatura['gateway']) : 'Manual (sem gateway)' ?></dd>
        </dl>
    <?php endif; ?>

    <?php if ($faturas !== []) : ?>
        <h2>Faturas</h2>
        <div class="tabela-rolagem">
            <table class="tabela">
                <thead><tr><th>Vencimento</th><th class="numero">Valor</th><th>Status</th><th>Pago em</th></tr></thead>
                <tbody>
                <?php foreach ($faturas as $f) : ?>
                    <tr>
                        <td><?= e(formatar_data($f['vencimento'])) ?></td>
                        <td class="numero"><?= e(formatar_moeda((int) $f['valor_centavos'])) ?></td>
                        <td><?= e($f['status']) ?></td>
                        <td><?= e(formatar_data_hora($f['pago_em']) ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="cartao">
    <h2>Usuários do cliente</h2>
    <div class="tabela-rolagem">
        <table class="tabela">
            <thead><tr><th>Nome</th><th>E-mail</th><th>Papéis</th><th>Situação</th><th>Último login</th></tr></thead>
            <tbody>
            <?php if ($usuarios === []) : ?>
                <tr><td colspan="5">Nenhum usuário. Cadastre o administrador abaixo.</td></tr>
            <?php endif; ?>
            <?php foreach ($usuarios as $u) : ?>
                <?php
                $papeis = array_filter(array_map(
                    static fn (string $p): ?string => Papel::tryFrom($p)?->rotulo(),
                    $u['papeis'] === '' ? [] : explode(',', (string) $u['papeis']),
                ));
                ?>
                <tr>
                    <td><?= e($u['nome']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><?= e(implode(', ', $papeis)) ?></td>
                    <td>
                        <span class="selo <?= $u['ativo'] ? 'selo-ok' : 'selo-neutro' ?>">
                            <?= $u['ativo'] ? 'Ativo' : 'Inativo' ?>
                        </span>
                    </td>
                    <td><?= e(formatar_data_hora($u['ultimo_login_em']) ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="dica">Os demais usuários são gerenciados pelo administrador do próprio cliente.</p>
</section>

<section class="cartao cartao-medio">
    <h2>Novo administrador do cliente</h2>
    <p class="dica">Use quando o cliente perder o acesso de administrador (ex.: saída do responsável).</p>
    <form method="post" action="/organizacoes/<?= e($id) ?>/administradores" class="formulario">
        <?= csrf_campo() ?>

        <label for="nome">Nome completo</label>
        <input type="text" id="nome" name="nome" value="<?= e($novoAdmin['nome'] ?? '') ?>" required maxlength="150"
            <?= invalido($erros, 'nome') ?>>
        <?= erro_campo($erros, 'nome') ?>

        <label for="email">E-mail</label>
        <input type="email" id="email" name="email" value="<?= e($novoAdmin['email'] ?? '') ?>" required
               maxlength="254"<?= invalido($erros, 'email') ?>>
        <?= erro_campo($erros, 'email') ?>
        <?= erro_campo($erros, 'papeis') ?>

        <p class="dica">Uma senha provisória será gerada e exibida uma única vez.</p>
        <div class="acoes">
            <button type="submit" class="botao botao-primario">Criar administrador</button>
        </div>
    </form>
</section>
