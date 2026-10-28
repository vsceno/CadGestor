<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Design system: vitrine, segurança dos cabeçalhos, tema, idioma e
 * acessibilidade básica dos componentes.
 */
class DesignSystemTest extends TestCase
{
    public function test_vitrine_abre_em_portugues_com_todos_os_componentes(): void
    {
        $resposta = $this->get(route('design-system.index'));

        $resposta->assertOk()
            ->assertSee('<html lang="pt-BR"', false)
            ->assertSee('Pular para o conteúdo')
            ->assertSee('Buscar por nome ou CPF')
            ->assertSee('***.982.247-**')
            ->assertSee('R$ 2.580,00')
            ->assertDontSee('529.982.247-25');
    }

    public function test_csp_so_permite_recursos_do_proprio_servidor(): void
    {
        $resposta = $this->get(route('design-system.index'));
        $csp = (string) $resposta->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringNotContainsString('unsafe-inline', $csp);
        $resposta->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');

        // Nenhuma fonte, script ou estilo vindo de CDN.
        $this->assertDoesNotMatchRegularExpression('#(src|href)="https?://(?!127\.0\.0\.1|localhost)#', $resposta->getContent());
    }

    public function test_tema_escolhido_ja_sai_do_servidor(): void
    {
        $this->withUnencryptedCookie('cg_tema', 'escuro')
            ->get(route('design-system.index'))
            ->assertSee('data-tema="escuro"', false);

        $this->withUnencryptedCookie('cg_tema', 'qualquer-coisa')
            ->get(route('design-system.index'))
            ->assertDontSee('data-tema=', false);
    }

    public function test_menu_recolhido_e_lembrado(): void
    {
        $this->withUnencryptedCookie('cg_menu', 'recolhido')
            ->get(route('design-system.index'))
            ->assertSee('data-recolhido="sim"', false);
    }

    public function test_mensagens_de_validacao_em_portugues(): void
    {
        $this->post(route('design-system.validar'), [])
            ->assertSessionHasErrors([
                'nome' => 'Preencha o campo nome.',
                'email' => 'Preencha o campo e-mail.',
                'cnpj' => 'Preencha o campo CNPJ.',
            ]);
    }

    public function test_campo_liga_rotulo_ajuda_e_erro_para_o_leitor_de_tela(): void
    {
        View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['cnpj' => ['CNPJ inválido.']])));

        $html = Blade::render('<x-campo nome="cnpj" rotulo="CNPJ" mascara="cnpj" ajuda="Só números." obrigatorio />');

        $this->assertStringContainsString('<label for="cnpj"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="cnpj-ajuda cnpj-erro"', $html);
        $this->assertStringContainsString('data-mascara="cnpj"', $html);
        $this->assertStringContainsString('CNPJ inválido.', $html);
        $this->assertStringNotContainsString('(opcional)', $html);
    }

    public function test_tabela_vazia_mostra_o_proximo_passo_sem_rodape_nem_lote(): void
    {
        $this->get(route('design-system.index', ['estado' => 'vazio']))
            ->assertOk()
            ->assertSee('Nenhuma pessoa cadastrada ainda')
            ->assertSee('Cadastrar pessoa')
            ->assertDontSee('Mostrando 1 a 4')
            ->assertDontSee('data-barra-lote', false);
    }

    public function test_status_nunca_e_so_cor(): void
    {
        $html = Blade::render('<x-status tipo="negado">Negado</x-status>');

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('Negado', $html);
    }

    public function test_alerta_de_erro_nao_pode_ser_fechado(): void
    {
        $erro = Blade::render('<x-alerta tipo="erro">Falhou.</x-alerta>');
        $sucesso = Blade::render('<x-alerta tipo="sucesso">Salvo.</x-alerta>');

        $this->assertStringContainsString('role="alert"', $erro);
        $this->assertStringNotContainsString('data-fechar-alerta', $erro);
        $this->assertStringContainsString('data-fechar-alerta', $sucesso);
    }

    public function test_modal_que_exige_digitar_comeca_com_o_botao_bloqueado(): void
    {
        $html = Blade::render(
            '<x-modal-confirmacao id="m" titulo="Excluir?" acao="/x" metodo="DELETE" digitar="Cliente X">Impacto.</x-modal-confirmacao>',
            ['errors' => new ViewErrorBag],
        );

        $this->assertStringContainsString('data-exigir-texto="Cliente X"', $html);
        $this->assertMatchesRegularExpression('/data-botao-confirmar[^>]*disabled/', $html);
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
        $this->assertStringContainsString('name="_token"', $html);
    }
}
