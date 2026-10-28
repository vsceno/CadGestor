@php
    use App\Support\Formato;

    $menu = [
        ['rotulo' => 'Início', 'href' => '#', 'icone' => 'inicio'],
        ['rotulo' => 'Pessoas', 'href' => '#', 'icone' => 'pessoas'],
        ['rotulo' => 'Portaria ao vivo', 'href' => '#', 'icone' => 'portaria'],
        ['rotulo' => 'Eventos e alertas', 'href' => '#', 'icone' => 'eventos'],
        ['rotulo' => 'Empresas', 'href' => '#', 'icone' => 'empresas'],
        ['rotulo' => 'Unidades e catracas', 'href' => '#', 'icone' => 'catraca'],
        ['rotulo' => 'Regras de acesso', 'href' => '#', 'icone' => 'regras'],
        ['rotulo' => 'Relatórios', 'href' => '#', 'icone' => 'relatorios'],
        ['rotulo' => 'LGPD', 'href' => '#', 'icone' => 'lgpd'],
        ['rotulo' => 'Usuários', 'href' => '#', 'icone' => 'usuarios'],
        ['rotulo' => 'Design system', 'href' => route('design-system.index'), 'icone' => 'painel', 'ativo' => true],
    ];
    $atalhos = [
        ['rotulo' => 'Início', 'href' => '#', 'icone' => 'inicio'],
        ['rotulo' => 'Pessoas', 'href' => '#', 'icone' => 'pessoas'],
        ['rotulo' => 'Portaria', 'href' => '#', 'icone' => 'portaria'],
        ['rotulo' => 'Alertas', 'href' => '#', 'icone' => 'alertas'],
    ];
    $contexto = ['rotulo' => 'Unidade', 'atual' => 'Matriz (fictícia)', 'opcoes' => [
        ['rotulo' => 'Matriz (fictícia)', 'href' => '#', 'ativo' => true],
        ['rotulo' => 'Filial Centro (fictícia)', 'href' => '#'],
    ]];
    $rotuloSituacao = ['ativo' => 'Ativo', 'atencao' => 'Vence em breve', 'negado' => 'Vínculo vencido', 'inativo' => 'Inativo'];
    $cores = [
        ['bg-primaria', 'Azul', 'Ação principal'],
        ['bg-sucesso', 'Verde', 'Liberado ou ativo'],
        ['bg-perigo', 'Vermelho', 'Negado ou erro'],
        ['bg-atencao', 'Âmbar', 'Atenção'],
        ['bg-inativo', 'Cinza', 'Inativo ou arquivado'],
    ];
@endphp

<x-layouts.app titulo="Design system" :menu="$menu" :atalhos="$atalhos" :contexto="$contexto" :alertas="3"
    :usuario="['nome' => 'Usuário de demonstração', 'papel' => 'Administrador']" buscaAcao="#">

    <x-slot:mensagens>
        @if (session('sucesso'))
            <x-alerta tipo="sucesso" titulo="Tudo certo">{{ session('sucesso') }}</x-alerta>
        @endif
        @if ($errors->any())
            <x-alerta tipo="erro" titulo="Não foi possível enviar">Corrija os campos destacados no formulário abaixo.</x-alerta>
        @endif
    </x-slot:mensagens>

    <x-migalhas :itens="[['rotulo' => 'Início', 'href' => '#'], ['rotulo' => 'Configurações', 'href' => '#'], ['rotulo' => 'Design system']]" />

    <x-cabecalho-pagina titulo="Design system" subtitulo="Componentes e estados usados em todas as telas do CadGestor. Dados fictícios.">
        <x-slot:acoes>
            <x-botao icone="calendario">Últimos 30 dias</x-botao>
            <x-botao icone="baixar">Exportar</x-botao>
            <x-botao variante="primaria" icone="adicionar">Ação principal</x-botao>
        </x-slot:acoes>
    </x-cabecalho-pagina>

    <x-abas :abas="[
        ['rotulo' => 'Normal', 'href' => route('design-system.index'), 'ativa' => $estado === 'normal'],
        ['rotulo' => 'Vazio', 'href' => route('design-system.index', ['estado' => 'vazio']), 'ativa' => $estado === 'vazio'],
        ['rotulo' => 'Carregando', 'href' => route('design-system.index', ['estado' => 'carregando']), 'ativa' => $estado === 'carregando'],
    ]" rotulo="Estados da tela" />

    {{-- Indicadores --}}
    @if ($estado === 'carregando')
        <x-esqueleto formato="cards" :quantidade="4" />
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-indicador tipo="destaque" rotulo="Receita recorrente" :valor="Formato::moeda(258000)" icone="financeiro" variacao="+R$ 499,00 neste mês" tendencia="alta" />
            <x-indicador rotulo="Pessoas ativas" :valor="Formato::numero($estado === 'vazio' ? 0 : 1284)" icone="pessoas" :variacao="$estado === 'vazio' ? null : '+32 nesta semana'" tendencia="alta" />
            <x-indicador rotulo="Negações hoje" :valor="$estado === 'vazio' ? '0' : '17'" icone="erro" tipo="perigo" :variacao="$estado === 'vazio' ? 'Nenhuma negação hoje' : '5 fora do horário'" />
            <x-indicador rotulo="Vínculos vencendo" :valor="$estado === 'vazio' ? '0' : '8'" icone="atencao" tipo="atencao" :variacao="$estado === 'vazio' ? 'Nenhum nos próximos 7 dias' : 'nos próximos 7 dias'" href="#" />
        </div>
    @endif

    {{-- Tabela --}}
    <x-cartao titulo="Pessoas" descricao="Tabela padrão: cabeçalho fixo, ordenação, filtros em chips, colunas, densidade e seleção em lote." :preenchimento="false">
        <div class="p-5">
            @if ($estado === 'carregando')
                <x-esqueleto formato="tabela" :quantidade="4" />
            @else
                <x-tabela id="demo-pessoas" legenda="Pessoas cadastradas (fictícias)" :vazia="$estado === 'vazio'"
                    :colunas="['perfil' => 'Perfil', 'empresa' => 'Empresa', 'validade' => 'Validade']">
                    <x-slot:filtros>
                        <div class="flex flex-wrap items-center gap-2">
                            <x-chip :ativo="true" remover="#">Situação: Ativo</x-chip>
                            <x-chip :ativo="true" remover="#">Perfil: Funcionário</x-chip>
                            <x-chip href="#">Empresa</x-chip>
                            <x-chip href="#">Validade</x-chip>
                        </div>
                    </x-slot:filtros>
                    <x-slot:lote>
                        <x-botao tamanho="pequeno" icone="baixar">Exportar selecionadas</x-botao>
                        <x-botao tamanho="pequeno" variante="perigo" icone="bloquear" data-abrir-modal="modal-lote">Bloquear acesso</x-botao>
                    </x-slot:lote>
                    <x-slot:cabecalho>
                        <tr>
                            <x-tabela.selecionar-todos />
                            <x-tabela.coluna ordenavel="nome">Nome</x-tabela.coluna>
                            <x-tabela.coluna>CPF</x-tabela.coluna>
                            <x-tabela.coluna coluna="perfil" ordenavel="perfil">Perfil</x-tabela.coluna>
                            <x-tabela.coluna coluna="empresa">Empresa</x-tabela.coluna>
                            <x-tabela.coluna>Situação</x-tabela.coluna>
                            <x-tabela.coluna coluna="validade" ordenavel="validade">Validade</x-tabela.coluna>
                            <x-tabela.coluna><span class="sr-only">Ações</span></x-tabela.coluna>
                        </tr>
                    </x-slot:cabecalho>
                    <x-slot:vazio>
                        <x-estado-vazio titulo="Nenhuma pessoa cadastrada ainda"
                            descricao="Cadastre a primeira pessoa e defina o vínculo e as regras de acesso dela. Leva cerca de 2 minutos.">
                            <x-slot:acao><x-botao variante="primaria" icone="adicionar" href="#">Cadastrar pessoa</x-botao></x-slot:acao>
                        </x-estado-vazio>
                    </x-slot:vazio>

                    @foreach ($pessoas as $p)
                        <tr>
                            <x-tabela.selecionar :valor="$p['id']" :rotulo="'Selecionar '.$p['nome']" />
                            <td>
                                <div class="flex items-center gap-3">
                                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-primaria-suave text-sm font-semibold text-primaria" aria-hidden="true">
                                        {{ mb_substr($p['nome'], 0, 1) }}
                                    </span>
                                    <a href="#" class="font-medium text-texto no-underline hover:text-primaria hover:underline">{{ $p['nome'] }}</a>
                                </div>
                            </td>
                            <td class="numeros whitespace-nowrap text-suave">{{ Formato::cpfMascarado($p['cpf']) }}</td>
                            <td data-coluna="perfil">{{ $p['perfil'] }}</td>
                            <td data-coluna="empresa">{{ $p['empresa'] }}</td>
                            <td><x-status :tipo="$p['situacao']">{{ $rotuloSituacao[$p['situacao']] }}</x-status></td>
                            <td data-coluna="validade" class="numeros whitespace-nowrap">{{ $p['validade'] ? Formato::data($p['validade']) : 'Sem prazo' }}</td>
                            <td class="text-right">
                                <x-botao variante="fantasma" tamanho="pequeno" icone="editar" :rotulo="'Editar '.$p['nome']" href="#" />
                            </td>
                        </tr>
                    @endforeach

                    <x-slot:rodape>
                        <span>Mostrando 1 a 4 de 4 pessoas</span>
                        <div class="flex gap-2">
                            <x-botao tamanho="pequeno" icone="voltar" aria-disabled="true">Anterior</x-botao>
                            <x-botao tamanho="pequeno" aria-disabled="true">Próxima <x-icone nome="avancar" class="size-4" /></x-botao>
                        </div>
                    </x-slot:rodape>
                </x-tabela>
            @endif
        </div>
    </x-cartao>

    <div class="grid gap-6 xl:grid-cols-2">
        {{-- Formulário --}}
        <x-cartao titulo="Formulário" descricao="Rótulos sempre visíveis, erro ao lado do campo, máscaras e rascunho automático. Envie vazio para ver a validação.">
            <form method="POST" action="{{ route('design-system.validar') }}" class="flex flex-col gap-4" data-rascunho="demo-formulario" novalidate>
                @csrf
                <x-alerta tipo="info" data-aviso-rascunho hidden>Recuperamos o que você tinha digitado nesta aba.</x-alerta>
                <x-campo nome="nome" rotulo="Razão social" obrigatorio autocomplete="organization" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-campo nome="cnpj" rotulo="CNPJ" mascara="cnpj" obrigatorio />
                    <x-campo nome="telefone" rotulo="Telefone" mascara="telefone" ajuda="Com DDD." />
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_8rem]">
                    <x-campo nome="email" tipo="email" rotulo="E-mail" obrigatorio placeholder="nome@empresa.com.br" />
                    <x-selecao nome="uf" rotulo="UF" obrigatorio :opcoes="['CE' => 'CE', 'PE' => 'PE', 'SP' => 'SP']" />
                </div>
                <x-campo nome="cpf" rotulo="CPF do responsável" mascara="cpf" data-sem-rascunho
                    ajuda="Dado pessoal: não fica no rascunho do navegador." />
                <x-campo nome="observacoes" tipo="textarea" rotulo="Observações" />
                <div class="flex flex-wrap justify-end gap-2">
                    <x-botao href="{{ route('design-system.index') }}">Cancelar</x-botao>
                    <x-botao variante="primaria" type="submit" icone="marcado">Salvar</x-botao>
                </div>
            </form>
        </x-cartao>

        <div class="flex flex-col gap-6">
            {{-- Botões e status --}}
            <x-cartao titulo="Botões e status">
                <div class="flex flex-col gap-5">
                    <div class="flex flex-wrap gap-2">
                        <x-botao variante="primaria" icone="adicionar">Principal</x-botao>
                        <x-botao>Secundário</x-botao>
                        <x-botao variante="fantasma">Fantasma</x-botao>
                        <x-botao variante="perigo" icone="excluir">Perigo</x-botao>
                        <x-botao disabled>Desabilitado</x-botao>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <x-botao tamanho="pequeno">Pequeno</x-botao>
                        <x-botao tamanho="grande" variante="primaria" icone="liberar">Grande (portaria)</x-botao>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <x-status tipo="ativo">Liberado</x-status>
                        <x-status tipo="negado">Negado</x-status>
                        <x-status tipo="atencao">Atenção</x-status>
                        <x-status tipo="inativo">Inativo</x-status>
                        <x-status tipo="info">Em análise</x-status>
                    </div>
                </div>
            </x-cartao>

            {{-- Alertas --}}
            <x-cartao titulo="Mensagens">
                <div class="flex flex-col gap-3">
                    <x-alerta tipo="sucesso" desfazer="#">Pessoa inativada.</x-alerta>
                    <x-alerta tipo="erro" titulo="Não foi possível conectar à catraca Portaria 1">
                        Verifique o cabo de rede e a energia do equipamento. Os acessos continuam pelo modo offline.
                    </x-alerta>
                    <x-alerta tipo="atencao" titulo="3 solicitações LGPD vencem em 2 dias">Responda antes do prazo legal.</x-alerta>
                    <x-alerta tipo="info">O relatório será enviado para o seu e-mail quando ficar pronto.</x-alerta>
                </div>
            </x-cartao>

            {{-- Confirmações --}}
            <x-cartao titulo="Confirmações" descricao="Ações irreversíveis descrevem o impacto. Excluir exige digitar o nome.">
                <div class="flex flex-wrap gap-2">
                    <x-botao variante="perigo" icone="bloquear" data-abrir-modal="modal-bloquear">Bloquear cliente</x-botao>
                    <x-botao variante="perigo" icone="excluir" data-abrir-modal="modal-excluir">Excluir cliente</x-botao>
                </div>
            </x-cartao>
        </div>
    </div>

    {{-- Cores --}}
    <x-cartao titulo="Cores com significado fixo" descricao="Nunca aparecem sozinhas: sempre acompanhadas de texto ou ícone.">
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            @foreach ($cores as [$token, $nome, $significado])
                <div class="flex items-center gap-3 rounded-controle border border-borda p-3">
                    <span class="size-10 shrink-0 rounded-controle {{ $token }}" aria-hidden="true"></span>
                    <span class="text-sm leading-tight">
                        <span class="block font-semibold">{{ $nome }}</span>
                        <span class="text-suave">{{ $significado }}</span>
                    </span>
                </div>
            @endforeach
        </div>
        <p class="mt-4 text-sm text-suave">
            Formatos brasileiros: {{ Formato::data('2026-10-28') }} · {{ Formato::hora('2026-10-28 14:05:00') }} ·
            {{ Formato::moeda(129000) }} · {{ Formato::cnpj('11222333000181') }} · {{ Formato::telefone('85999990000') }} ·
            CPF mascarado {{ Formato::cpfMascarado('52998224725') }}
        </p>
    </x-cartao>

    <x-modal-confirmacao id="modal-bloquear" titulo="Bloquear o cliente Construtora Exemplo?" acao="#" confirmar="Bloquear cliente">
        <strong class="text-texto">1.284 pessoas perderão o acesso</strong> imediatamente e os usuários do cliente não conseguirão entrar.
        Você pode desbloquear depois.
    </x-modal-confirmacao>

    <x-modal-confirmacao id="modal-excluir" titulo="Excluir o cliente Construtora Exemplo?" acao="#" metodo="DELETE"
        confirmar="Excluir definitivamente" digitar="Construtora Exemplo">
        Esta ação não pode ser desfeita. <strong class="text-texto">Todos os cadastros, regras e históricos</strong> do cliente serão apagados.
    </x-modal-confirmacao>

    <x-modal-confirmacao id="modal-lote" titulo="Bloquear o acesso das pessoas selecionadas?" acao="#" confirmar="Bloquear acesso">
        As pessoas selecionadas não passarão mais nas catracas até serem liberadas de novo.
    </x-modal-confirmacao>
</x-layouts.app>
