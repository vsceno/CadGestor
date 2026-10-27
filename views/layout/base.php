<?php
/**
 * Layout base de todas as páginas.
 *
 * @var string $conteudo HTML já renderizado da view interna
 * @var string $titulo
 */
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($titulo ?? 'CadGestor') ?> · CadGestor</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <header class="topo">
        <a class="marca" href="/">CadGestor</a>
    </header>
    <main class="conteudo">
        <?= $conteudo ?>
    </main>
    <footer class="rodape">CadGestor · controle de acesso</footer>
</body>
</html>
