<?php
/**
 * Layout base de todas as páginas.
 *
 * @var string $conteudo HTML já renderizado da view interna
 * @var string $titulo
 * @var \CadGestor\Seguranca\UsuarioAutenticado|null $usuario
 * @var string|null $caminho_atual
 * @var (callable(): list<array{tipo: string, mensagem: string}>)|null $consumir_flash
 */

use CadGestor\Seguranca\Papel;

$usuario ??= null;
$caminhoAtual = $caminho_atual ?? '';
$mensagens = isset($consumir_flash) ? $consumir_flash() : [];

// Itens do menu: [rótulo, caminho, papéis que enxergam (vazio = todos)]
$menu = [
    ['Painel', '/painel', []],
    ['Empresas', '/empresas', [Papel::CadastroEmpresa]],
    ['Catracas', '/catracas', [Papel::CadastroEmpresa]],
    ['Pessoas', '/pessoas', [Papel::CadastroPessoa]],
    ['Perfis', '/perfis', [Papel::Administrador]],
    ['Usuários', '/usuarios', [Papel::Administrador]],
];

$itensVisiveis = $usuario === null || $usuario->deveTrocarSenha ? [] : array_filter(
    $menu,
    static fn (array $item): bool => $item[2] === [] || $usuario->temPapel(...$item[2]),
);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo ?? 'CadGestor') ?> · CadGestor</title>
    <link rel="stylesheet" href="/assets/css/app.css">
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
    <header class="topo">
        <a class="marca" href="/">CadGestor</a>
        <?php if ($itensVisiveis !== []) : ?>
            <nav class="menu" aria-label="Menu principal">
                <?php foreach ($itensVisiveis as [$rotulo, $caminho]) : ?>
                    <?php $ativo = $caminhoAtual === $caminho || str_starts_with($caminhoAtual, $caminho . '/'); ?>
                    <a href="<?= e($caminho) ?>"<?= $ativo ? ' aria-current="page"' : '' ?>><?= e($rotulo) ?></a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <?php if ($usuario !== null) : ?>
            <div class="conta">
                <span class="conta-nome"><?= e($usuario->primeiroNome()) ?></span>
                <a href="/senha">Trocar senha</a>
                <form method="post" action="/sair" class="form-inline">
                    <?= csrf_campo() ?>
                    <button type="submit" class="botao-link">Sair</button>
                </form>
            </div>
        <?php endif; ?>
    </header>
    <main class="conteudo">
        <?php foreach ($mensagens as $mensagem) : ?>
            <div class="alerta alerta-<?= e($mensagem['tipo']) ?>" role="status"><?= e($mensagem['mensagem']) ?></div>
        <?php endforeach; ?>
        <?= $conteudo ?>
    </main>
    <footer class="rodape">CadGestor · controle de acesso</footer>
</body>
</html>
