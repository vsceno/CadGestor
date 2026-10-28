<?php
/**
 * @var array<string, string> $dados
 * @var array<string, string> $erros
 * @var list<array<string, mixed>> $planos
 * @var int $diasTeste
 */
?>
<section class="cartao">
    <h1>Contrate o CadGestor</h1>
    <p class="subtitulo">Controle de acesso por digital e reconhecimento facial, com LGPD.
        <strong><?= e($diasTeste) ?> dias grátis</strong>, sem cartão: a primeira cobrança só vence no fim do teste.</p>

    <?php if (isset($erros['geral'])) : ?>
        <div class="alerta alerta-erro" role="alert"><?= e($erros['geral']) ?></div>
    <?php endif; ?>

    <form method="post" action="/cadastro" class="formulario" autocomplete="on">
        <?= csrf_campo() ?>

        <fieldset>
            <legend>Plano</legend>
            <div class="planos">
                <?php foreach ($planos as $plano) : ?>
                    <label class="plano-opcao">
                        <input type="radio" name="plano" value="<?= e($plano['codigo']) ?>" required
                            <?= marcado(($dados['plano'] ?? '') === $plano['codigo']) ?>>
                        <span class="plano-nome"><?= e($plano['nome']) ?></span>
                        <span class="plano-preco"><?= e(formatar_moeda((int) $plano['preco_centavos'])) ?>/mês</span>
                        <span class="plano-limites">até <?= e($plano['limite_pessoas']) ?> pessoas ·
                            <?= e($plano['limite_catracas']) ?> catracas</span>
                        <?php if ($plano['descricao']) : ?><span class="dica"><?= e($plano['descricao']) ?></span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <?= erro_campo($erros, 'plano') ?>
        </fieldset>

        <fieldset>
            <legend>Sua empresa</legend>
            <label for="razao_social">Razão social</label>
            <input type="text" id="razao_social" name="razao_social" value="<?= e($dados['razao_social'] ?? '') ?>"
                   required maxlength="150" autocomplete="organization"<?= invalido($erros, 'razao_social') ?>>
            <?= erro_campo($erros, 'razao_social') ?>

            <label for="cnpj">CNPJ</label>
            <input type="text" id="cnpj" name="cnpj" value="<?= e($dados['cnpj'] ?? '') ?>" required maxlength="18"
                   data-mascara="cnpj"<?= invalido($erros, 'cnpj') ?>>
            <?= erro_campo($erros, 'cnpj') ?>
        </fieldset>

        <fieldset>
            <legend>Administrador da conta</legend>
            <label for="responsavel_nome">Nome completo</label>
            <input type="text" id="responsavel_nome" name="responsavel_nome" required maxlength="150" autocomplete="name"
                   value="<?= e($dados['responsavel_nome'] ?? '') ?>"<?= invalido($erros, 'responsavel_nome') ?>>
            <?= erro_campo($erros, 'responsavel_nome') ?>

            <label for="email">E-mail (será o seu login)</label>
            <input type="email" id="email" name="email" value="<?= e($dados['email'] ?? '') ?>" required maxlength="254"
                   autocomplete="email"<?= invalido($erros, 'email') ?>>
            <?= erro_campo($erros, 'email') ?>

            <label for="senha">Senha</label>
            <input type="password" id="senha" name="senha" required maxlength="128" autocomplete="new-password"
                <?= invalido($erros, 'senha') ?>>
            <p class="dica">Mínimo de 12 caracteres. Uma frase longa é mais fácil de lembrar e mais segura.</p>
            <?= erro_campo($erros, 'senha') ?>

            <label for="confirmacao">Confirme a senha</label>
            <input type="password" id="confirmacao" name="confirmacao" required maxlength="128" autocomplete="new-password"
                <?= invalido($erros, 'confirmacao') ?>>
            <?= erro_campo($erros, 'confirmacao') ?>
        </fieldset>

        <label class="opcao">
            <input type="checkbox" name="aceite" value="1" required>
            Li e aceito os <a href="/termos-de-uso" target="_blank" rel="noopener">Termos de Uso</a>, o
            <a href="/contrato-dados" target="_blank" rel="noopener">Contrato de Tratamento de Dados</a> e a
            <a href="/privacidade" target="_blank" rel="noopener">Política de Privacidade</a>, em nome da empresa.
        </label>
        <?= erro_campo($erros, 'aceite') ?>

        <div class="acoes">
            <button type="submit" class="botao botao-primario">Começar teste grátis</button>
            <a href="/login" class="botao">Já tenho conta</a>
        </div>
    </form>
</section>
