<?php
/**
 * @var list<array<string, mixed>> $organizacoes
 * @var array<string, int> $indicadores
 * @var array<string, string> $filtros
 * @var bool $filtrando
 * @var list<array<string, mixed>> $planos
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
<div class="cabecalho-pagina">
    <div>
        <h1>Clientes</h1>
        <p class="subtitulo">Cada cliente é dono do próprio ambiente. Aqui aparecem só dados comerciais e contagens.</p>
    </div>
    <div class="acoes">
        <a class="botao" href="/planos">Planos</a>
        <a class="botao botao-primario" href="/organizacoes/nova">+ Novo cliente</a>
    </div>
</div>

<div class="indicadores">
    <div class="indicador indicador-destaque">
        <span class="indicador-rotulo">Receita mensal recorrente</span>
        <span class="indicador-valor"><?= e(formatar_moeda($indicadores['receita_mensal'])) ?></span>
    </div>
    <div class="indicador"><span class="indicador-rotulo">Ativos</span><span class="indicador-valor"><?= e($indicadores['ativas']) ?></span></div>
    <div class="indicador"><span class="indicador-rotulo">Em teste grátis</span><span class="indicador-valor"><?= e($indicadores['teste']) ?></span></div>
    <div class="indicador indicador-alerta<?= $indicadores['inadimplentes'] > 0 ? ' com-valor' : '' ?>"><span class="indicador-rotulo">Inadimplentes</span><span class="indicador-valor"><?= e($indicadores['inadimplentes']) ?></span></div>
    <div class="indicador"><span class="indicador-rotulo">Cancelados</span><span class="indicador-valor"><?= e($indicadores['canceladas']) ?></span></div>
</div>

<section class="cartao">
    <form method="post" action="/organizacoes/busca" class="filtros" role="search">
        <?= csrf_campo() ?>
        <div class="filtro-busca">
            <label for="q">Buscar</label>
            <input type="search" id="q" name="q" value="<?= e($filtros['q']) ?>" maxlength="100" placeholder="Razão social, nome fantasia ou CNPJ">
        </div>
        <div>
            <label for="status">Assinatura</label>
            <select id="status" name="status">
                <option value="">Todas</option>
                <?php foreach ($rotuloStatus as $valor => $rotulo) : ?>
                    <option value="<?= e($valor) ?>"<?= selecionado($filtros['status'] === $valor) ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="plano">Plano</label>
            <select id="plano" name="plano">
                <option value="">Todos</option>
                <?php foreach ($planos as $p) : ?>
                    <option value="<?= e($p['codigo']) ?>"<?= selecionado($filtros['plano'] === $p['codigo']) ?>><?= e($p['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filtro-acoes">
            <button type="submit" class="botao botao-primario">Filtrar</button>
            <?php if ($filtrando) : ?><a class="botao" href="/organizacoes">Limpar</a><?php endif; ?>
        </div>
    </form>

    <div class="tabela-rolagem">
        <table class="tabela tabela-clientes">
            <thead>
                <tr><th>Cliente</th><th>CNPJ</th><th>Plano</th><th>Assinatura</th><th>Situação</th>
                    <th class="numero">Pessoas</th><th class="numero">Catracas</th><th class="numero">Usuários</th></tr>
            </thead>
            <tbody>
            <?php if ($organizacoes === []) : ?>
                <tr><td colspan="8" class="vazio"><?= $filtrando ? 'Nenhum cliente com esses filtros.' : 'Nenhum cliente ainda.' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($organizacoes as $o) : ?>
                <?php $a = $o['assinatura']; $situacao = $o['situacao']; ?>
                <tr>
                    <td>
                        <a class="link-forte" href="/organizacoes/<?= e($o['id']) ?>"><?= e($o['nome']) ?></a>
                        <?php if ($o['nome_fantasia']) : ?><span class="texto-suave"><?= e($o['nome_fantasia']) ?></span><?php endif; ?>
                        <?php if (!$o['ativo']) : ?><span class="selo selo-falha">Bloqueado pela plataforma</span><?php endif; ?>
                    </td>
                    <td class="nao-quebra"><?= $o['cnpj'] ? e(formatar_cnpj((string) $o['cnpj'])) : '<span class="texto-suave">—</span>' ?></td>
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
