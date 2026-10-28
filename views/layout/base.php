<?php
/**
 * Layout base de todas as páginas.
 *
 * Logado: menu lateral com os itens que o papel do usuário enxerga.
 * Sem login (entrar, contratar, documentos): página centralizada, sem menu.
 *
 * @var string $conteudo HTML já renderizado da view interna
 * @var string $titulo
 * @var \CadGestor\Seguranca\UsuarioAutenticado|null $usuario
 * @var string|null $caminho_atual
 * @var (callable(): list<array{tipo: string, mensagem: string}>)|null $consumir_flash
 */

use CadGestor\Nucleo\Visao;
use CadGestor\Seguranca\Papel;

$usuario ??= null;
$caminhoAtual = $caminho_atual ?? '';
$mensagens = isset($consumir_flash) ? $consumir_flash() : [];

// Ícones (traços SVG, desenhados aqui: a CSP não permite fontes ou imagens de fora).
$icones = [
    'painel' => '<path d="M3 13h8V3H3zM13 21h8V11h-8zM3 21h8v-6H3zM13 3v6h8V3z"/>',
    'empresas' => '<path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h1M14 9h1M9 13h1M14 13h1M9 17h1M14 17h1"/>',
    'catracas' => '<path d="M4 4h4v16H4zM8 12h12M14 8l4 4-4 4"/>',
    'pessoas' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'relatorios' => '<path d="M4 20V10M10 20V4M16 20v-6M22 20H2"/>',
    'alertas' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0"/>',
    'perfis' => '<path d="M12 2l9 4v6c0 5-4 9-9 10-5-1-9-5-9-10V6z"/>',
    'termos' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h8"/>',
    'usuarios' => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8"/>',
    'auditoria' => '<path d="M9 11l3 3 8-8M20 12v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    'assinatura' => '<path d="M2 7h20v12H2zM2 11h20M6 15h4"/>',
    'simulador' => '<path d="M5 3l14 9-14 9z"/>',
    'clientes' => '<path d="M3 21h18M6 21V9l6-5 6 5v12M10 21v-5h4v5"/>',
    'planos' => '<path d="M12 2l3 7h7l-5.5 4.5L18.5 21 12 16.5 5.5 21l2-7.5L2 9h7z"/>',
];

// Itens do menu: [rótulo, caminho, ícone, papéis que enxergam (vazio = todos)]
$menu = [
    ['Painel', '/painel', 'painel', []],
    ['Empresas', '/empresas', 'empresas', [Papel::CadastroEmpresa]],
    ['Catracas', '/catracas', 'catracas', [Papel::CadastroEmpresa]],
    ['Pessoas', '/pessoas', 'pessoas', [Papel::CadastroPessoa]],
    ['Relatórios', '/relatorios', 'relatorios', [Papel::Consulta]],
    ['Alertas', '/alertas', 'alertas', [Papel::Consulta]],
    ['Perfis', '/perfis', 'perfis', [Papel::Administrador]],
    ['Termos LGPD', '/termos', 'termos', [Papel::Administrador]],
    ['Usuários', '/usuarios', 'usuarios', [Papel::Administrador]],
    ['Auditoria', '/auditoria', 'auditoria', [Papel::Administrador]],
    ['Minha assinatura', '/assinatura', 'assinatura', [Papel::Administrador]],
];

if (Visao::compartilhado('simulador_disponivel', false)) {
    $menu[] = ['Simulador', '/simulador', 'simulador', [Papel::Administrador]];
}

// Modo plataforma: fora de qualquer organização, só a gestão comercial.
if ($usuario?->emModoPlataforma()) {
    $menu = [
        ['Clientes', '/organizacoes', 'clientes', [Papel::Plataforma]],
        ['Planos', '/planos', 'planos', [Papel::Plataforma]],
    ];
}

$itensVisiveis = $usuario === null || $usuario->deveTrocarSenha ? [] : array_filter(
    $menu,
    static fn (array $item): bool => $item[3] === [] || $usuario->temPapel(...$item[3]),
);
$situacaoAssinatura = Visao::compartilhado('situacao_assinatura');

// Versão no endereço do CSS/JS (data de alteração do arquivo): quando o arquivo muda,
// o navegador baixa o novo em vez de reaproveitar a cópia antiga do cache.
$recurso = static function (string $caminho): string {
    $arquivo = dirname(__DIR__, 2) . '/public' . $caminho;

    return is_file($arquivo) ? $caminho . '?v=' . filemtime($arquivo) : $caminho;
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title><?= e($titulo ?? 'CadGestor') ?> · CadGestor</title>
    <link rel="stylesheet" href="<?= e($recurso('/assets/css/app.css')) ?>">
    <script src="<?= e($recurso('/assets/js/app.js')) ?>" defer></script>
</head>
<?php if ($usuario === null) : ?>
<body class="pagina-publica">
    <header class="topo-publico">
        <a class="marca" href="/"><span class="marca-simbolo" aria-hidden="true">CG</span>CadGestor</a>
        <nav aria-label="Acesso">
            <?php if ($caminhoAtual !== '/login') : ?><a href="/login">Entrar</a><?php endif; ?>
            <?php if ($caminhoAtual !== '/cadastro') : ?><a href="/cadastro">Contratar</a><?php endif; ?>
        </nav>
    </header>
    <main class="conteudo">
        <?php foreach ($mensagens as $mensagem) : ?>
            <div class="alerta alerta-<?= e($mensagem['tipo']) ?> cartao-estreito" role="status"><?= e($mensagem['mensagem']) ?></div>
        <?php endforeach; ?>
        <?= $conteudo ?>
    </main>
    <footer class="rodape">
        CadGestor · controle de acesso ·
        <a href="/termos-de-uso">Termos de Uso</a> · <a href="/privacidade">Privacidade</a>
    </footer>
</body>
<?php else : ?>
<body>
<div class="estrutura">
    <aside class="lateral">
        <a class="marca" href="/"><span class="marca-simbolo" aria-hidden="true">CG</span>CadGestor</a>
        <?php if ($itensVisiveis !== []) : ?>
            <nav class="menu" aria-label="Menu principal">
                <?php foreach ($itensVisiveis as [$rotulo, $caminho, $icone]) : ?>
                    <?php $ativo = $caminhoAtual === $caminho || str_starts_with($caminhoAtual, $caminho . '/'); ?>
                    <a href="<?= e($caminho) ?>"<?= $ativo ? ' aria-current="page"' : '' ?>>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                             stroke-linejoin="round" aria-hidden="true"><?= $icones[$icone] ?></svg>
                        <?= e($rotulo) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
        <div class="conta">
            <?php if ($usuario->organizacaoNome !== null) : ?>
                <span class="conta-organizacao" title="<?= e($usuario->organizacaoNome) ?>"><?= e($usuario->organizacaoNome) ?></span>
            <?php elseif ($usuario->emModoPlataforma()) : ?>
                <span class="conta-organizacao">Plataforma</span>
            <?php endif; ?>
            <span class="conta-nome"><?= e($usuario->primeiroNome()) ?></span>
            <div class="conta-links">
                <a href="/senha">Trocar senha</a>
                <form method="post" action="/sair" class="form-inline">
                    <?= csrf_campo() ?>
                    <button type="submit" class="botao-link">Sair</button>
                </form>
            </div>
        </div>
    </aside>
    <div class="area">
        <?php if ($situacaoAssinatura?->aviso !== null && !str_starts_with($caminhoAtual, '/assinatura')) : ?>
            <div class="faixa-assinatura" role="status">
                <?= e($situacaoAssinatura->aviso) ?> <a href="/assinatura">Minha assinatura</a>
            </div>
        <?php endif; ?>
        <main class="conteudo">
            <?php foreach ($mensagens as $mensagem) : ?>
                <div class="alerta alerta-<?= e($mensagem['tipo']) ?>" role="status"><?= e($mensagem['mensagem']) ?></div>
            <?php endforeach; ?>
            <?= $conteudo ?>
        </main>
        <footer class="rodape">CadGestor · controle de acesso</footer>
    </div>
</div>
</body>
<?php endif; ?>
</html>
