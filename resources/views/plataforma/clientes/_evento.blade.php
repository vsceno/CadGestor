{{-- Texto de um evento do histórico comercial, só com as chaves permitidas que o banco devolve. --}}
@php
    use App\Support\Formato;

    $antes = $evento->antes;
    $depois = $evento->depois;
    $eventos = ['baixa_manual' => 'Baixa manual de pagamento', 'pagamento_confirmado' => 'Pagamento confirmado', 'cobranca_vencida' => 'Cobrança vencida', 'cobranca_criada' => 'Cobrança gerada'];
@endphp
{{ match ($evento->acao) {
    'nota' => 'Anotação',
    'cadastro_cliente' => 'Contratou pelo site'.(isset($depois['plano']) ? ' · plano '.$depois['plano'] : ''),
    'email_confirmado' => 'E-mail confirmado',
    'assinatura_alterada' => match (true) {
        isset($depois['plano']) => 'Plano alterado: '.($antes['plano'] ?? '?').' → '.$depois['plano'],
        isset($depois['teste_ate']) => 'Teste grátis estendido até '.Formato::data($depois['teste_ate']).(isset($depois['dias']) ? " (+{$depois['dias']} dias)" : ''),
        ($depois['status'] ?? '') === 'cancelada' => 'Assinatura cancelada',
        default => 'Assinatura alterada',
    },
    'pagamento' => ($eventos[$depois['evento'] ?? ''] ?? 'Pagamento').(isset($depois['valor_centavos']) ? ' · '.Formato::moeda((int) $depois['valor_centavos']) : ''),
    'criar' => $evento->tabela === 'organizacao' ? 'Cliente cadastrado (venda manual)' : 'Administrador cadastrado pela plataforma',
    'alterar' => match (true) {
        array_key_exists('ativo', $depois) => $depois['ativo'] ? 'Cliente reativado' : 'Cliente desativado',
        isset($depois['campos']) => 'Cadastro alterado ('.count((array) $depois['campos']).' campo(s))',
        default => 'Cadastro alterado',
    },
    'redefinicao_solicitada' => ($depois['via'] ?? '') === 'novo_administrador' ? 'Link de primeira senha enviado ao administrador' : 'Link de nova senha enviado',
    'confirmacao_reenviada' => 'Confirmação de e-mail reenviada',
    'login_desbloqueado' => 'Login desbloqueado',
    default => ucfirst(str_replace('_', ' ', (string) $evento->acao)),
} }}
