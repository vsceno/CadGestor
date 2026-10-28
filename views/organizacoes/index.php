<?php
/**
 * @var list<array<string, mixed>> $organizacoes
 * @var array<string, int> $indicadores
 */

use CadGestor\Dominio\SituacaoAssinatura;

$rotuloStatus = ['teste' => 'Teste grátis', 'ativa' => 'Ativa', 'inadimplente' => 'Inadimplente', 'cancelada' => 'Cancelada'];
$classeNivel = [
    SituacaoAssinatura::NORMAL => 'selo-ok',
    SituacaoAssinatura::SOMENTE_LEITURA => 'selo-aviso',
    SituacaoAssinatura::SUSPENSA => 'selo-aviso',
    SituacaoAssinatura::BLOQUEADA => 'selo-falha',
];
$rotuloNivel = [
    SituacaoAssinatura::NORMAL => 'Normal',
    SituacaoAssinatura::SOMENTE_LEITURA => 'Somente leitura',
    SituacaoAssinatura::SUSPENSA => 'Suspenso',
    SituacaoAssinatura::BLOQUEADA => 'Bloqueado',
];
?>
<section class="cartao">
    <div class="cabecalho-pagina">
        <h1>Clientes</h1>
        <div class="acoes">
            <a class="botao" href="/planos">Planos</a>
            <a class="botao botao-primario" href="/organizacoes/nova">Novo cliente (venda manual)</a>
        </div>
    </div>

    <div class="indicadores">
        <div class="indicador"><span class="indicador-valor"><?= e(formatar_moeda($indicadores['receita_mensal'])) ?></span><span class="indicador-rotulo">Receita mensal recorrente</span></div>
        <div class="indicador"><span class="indicador-valor"><?= e($indicadores['ativas']) ?></span><span class="indicador-rotulo">Ativos</span></div>
        <div class="indicador"><span class="indicador-valor"><?= e($indicadores['teste']) ?></span><span class="indicador-rotulo">Em teste grátis</span></div>
        <div class="indicador"><span class="indicador-valor"><?= e($indicadores['inadimplentes']) ?></span><span class="indicador-rotulo">Inadimplentes</span></div>
        <div class="indicador"><span class="indicador-valor"><?= e($indicadores['canceladas']) ?></span><span class="indicador-rotulo">Cancelados</span></div>
    </div>

    <p class="dica">
        Cada cliente é dono do próprio ambiente (empresas, catracas, pessoas e usuários). A plataforma
        não entra nele: aqui aparecem só dados comerciais e contagens.
    </p>

    <div class="tabela-rolagem">
        <table class="tabela">
            <thead>
                <tr><th>Cliente</th><th>Plano</th><th>Assinatura</th><th>Situação</th>
                    <th class="numero">Pessoas</th><th class="numero">Catracas</th><th class="numero">Usuários</th></tr>
            </thead>
            <tbody>
            <?php if ($organizacoes === []) : ?>
                <tr><td colspan="7">Nenhum cliente ainda.</td></tr>
            <?php endif; ?>
            <?php foreach ($organizacoes as $o) : ?>
                <?php $a = $o['assinatura']; $situacao = $o['situacao']; ?>
                <tr>
                    <td>
                        <a href="/organizacoes/<?= e($o['id']) ?>"><strong><?= e($o['nome']) ?></strong></a>
                        <?php if (!$o['ativo']) : ?><br><span class="selo selo-falha">Bloqueado pela plataforma</span><?php endif; ?>
                    </td>
                    <td><?= e($a['plano_nome'] ?? '—') ?></td>
                    <td><?= e($rotuloStatus[$a['status'] ?? ''] ?? 'Sem assinatura') ?></td>
                    <td><span class="selo <?= e($classeNivel[$situacao->nivel]) ?>"><?= e($rotuloNivel[$situacao->nivel]) ?></span></td>
                    <td class="numero"><?= e($o['pessoas']) ?></td>
                    <td class="numero"><?= e($o['catracas']) ?></td>
                    <td class="numero"><?= e($o['usuarios']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
