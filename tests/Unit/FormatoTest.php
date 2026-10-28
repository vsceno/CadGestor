<?php

namespace Tests\Unit;

use App\Support\Formato;
use Tests\TestCase;

/**
 * Padrões brasileiros de exibição.
 */
class FormatoTest extends TestCase
{
    public function test_datas_e_horas_no_padrao_brasileiro_e_no_fuso_da_aplicacao(): void
    {
        $this->assertSame('28/10/2026', Formato::data('2026-10-28'));
        $this->assertSame('', Formato::data(null));
        // 17:05 UTC = 14:05 em Fortaleza (UTC-3), relógio de 24 horas.
        $this->assertSame('28/10/2026 14:05', Formato::dataHora('2026-10-28T17:05:00Z'));
        $this->assertSame('28/10/2026 14:05:09', Formato::dataHora('2026-10-28T17:05:09Z', true));
        $this->assertSame('21:30', Formato::hora('2026-10-29T00:30:00Z'));
    }

    public function test_moeda_e_numeros(): void
    {
        $this->assertSame('R$ 1.290,00', Formato::moeda(129000));
        $this->assertSame('R$ 0,99', Formato::moeda(99));
        $this->assertSame('1.284', Formato::numero(1284));
        $this->assertSame('12,50', Formato::numero(12.5, 2));
    }

    public function test_cpf_sai_mascarado_por_padrao(): void
    {
        $this->assertSame('***.982.247-**', Formato::cpfMascarado('529.982.247-25'));
        $this->assertSame('', Formato::cpfMascarado('123'));
        $this->assertSame('529.982.247-25', Formato::cpf('52998224725'));
    }

    public function test_cnpj_cep_e_telefone(): void
    {
        $this->assertSame('11.222.333/0001-81', Formato::cnpj('11222333000181'));
        $this->assertSame('12.ABC.345/01DE-35', Formato::cnpj('12abc34501de35'), 'Aceita o CNPJ alfanumérico.');
        $this->assertSame('60115-170', Formato::cep('60115170'));
        $this->assertSame('(85) 3222-1100', Formato::telefone('8532221100'));
        $this->assertSame('(85) 99999-0000', Formato::telefone('85999990000'));
    }
}
