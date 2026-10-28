<?php
/**
 * @var string|null $token
 * @var array<string, string>|null $erros
 */

$erros ??= [];
?>
<section class="cartao cartao-estreito cartao-acesso">
    <?php if ($token === null) : ?>
        <h1>Link inválido</h1>
        <div class="alerta alerta-erro" role="alert">
            Este link expirou ou já foi usado. Cada link vale uma única vez, por tempo limitado.
        </div>
        <p><a class="botao botao-primario" href="/esqueci-senha">Pedir um novo link</a></p>
    <?php else : ?>
        <h1>Criar nova senha</h1>
        <p class="subtitulo">Ao salvar, você sai de todos os aparelhos em que estava conectado.</p>

        <form method="post" action="/redefinir-senha" class="formulario" autocomplete="off">
            <?= csrf_campo() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">

            <label for="senha">Nova senha</label>
            <input type="password" id="senha" name="senha" required autofocus maxlength="128" autocomplete="new-password"
                   aria-describedby="dica-senha"<?= invalido($erros, 'senha') ?>>
            <p class="dica" id="dica-senha">Mínimo de 12 caracteres. Uma frase longa é mais fácil de lembrar e mais segura.</p>
            <?= erro_campo($erros, 'senha') ?>

            <label for="confirmacao">Confirme a nova senha</label>
            <input type="password" id="confirmacao" name="confirmacao" required maxlength="128" autocomplete="new-password"
                <?= invalido($erros, 'confirmacao') ?>>
            <?= erro_campo($erros, 'confirmacao') ?>

            <button type="submit" class="botao botao-primario botao-bloco">Salvar nova senha</button>
        </form>
    <?php endif; ?>
</section>
