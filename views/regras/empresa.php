<?php
/**
 * @var array<string, mixed> $empresa
 * @var list<array{id: string, nome: string}> $perfis
 * @var list<array<string, mixed>> $catracas
 * @var array<string, list<array<string, mixed>>> $regrasPorPerfil
 * @var list<array<string, mixed>> $horariosPadrao
 */

use CadGestor\Nucleo\Visao;

$id = (string) $empresa['id'];
?>
<section class="cartao">
    <h1>Regras de acesso</h1>
    <p class="subtitulo">
        <a href="/empresas/<?= e($id) ?>"><?= e($empresa['razao_social']) ?></a>
    </p>
    <div class="alerta alerta-info">
        Funciona como lista de permissões: fora das faixas cadastradas o acesso é negado.
        Ordem de aplicação: <strong>regras do vínculo</strong> (exceção individual, no cadastro da pessoa) →
        <strong>regras do perfil</strong> (abaixo) → <strong>horário padrão da empresa</strong>
        (<?= e(count($horariosPadrao)) ?> faixa(s)).
    </div>
    <?php if ($perfis === []) : ?>
        <p class="vazio">Nenhum perfil aceito. <a href="/empresas/<?= e($id) ?>#perfis">Configure os perfis da empresa</a>.</p>
    <?php endif; ?>
</section>

<?php foreach ($perfis as $perfil) : ?>
    <?php $regras = $regrasPorPerfil[$perfil['id']] ?? []; ?>
    <section class="cartao" id="perfil-<?= e($perfil['id']) ?>">
        <h2><?= e($perfil['nome']) ?></h2>
        <p class="dica">
            <?= $regras === []
                ? 'Sem regras próprias: segue o horário padrão da empresa em todas as catracas.'
                : count($regras) . ' faixa(s) cadastrada(s).' ?>
        </p>
        <form method="post" action="/empresas/<?= e($id) ?>/regras/<?= e($perfil['id']) ?>" class="formulario">
            <?= csrf_campo() ?>
            <?= Visao::parcial('editor-horarios', [
                'idBloco' => 'regras-' . $perfil['id'],
                'linhas' => $regras,
                'catracas' => $catracas,
            ]) ?>
            <div class="acoes"><button type="submit" class="botao botao-primario">Salvar regras de <?= e($perfil['nome']) ?></button></div>
        </form>
    </section>
<?php endforeach; ?>
