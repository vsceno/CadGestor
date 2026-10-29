<x-layouts.publico titulo="Política de Privacidade" largura="max-w-3xl">
    <x-alerta tipo="atencao" titulo="MODELO para revisão jurídica" :fechavel="false">
        Versão {{ $versao }}. Não utilizar comercialmente antes da aprovação do advogado.
    </x-alerta>

    <article class="flex flex-col gap-4 leading-relaxed">
        <h1 class="text-2xl">Política de Privacidade</h1>

        <h2 class="mt-2 text-lg">1. A quem se aplica</h2>
        <p>
            Esta política trata dos dados que o CadGestor coleta como <strong>controlador</strong>: os dados das empresas
            contratantes e dos usuários que acessam o sistema. Os dados das pessoas cadastradas por cada Cliente nas
            catracas seguem o <a href="{{ route('documentos.contrato-dados') }}">Contrato de Tratamento de Dados</a>, e o
            controlador deles é o próprio Cliente.
        </p>

        <h2 class="mt-2 text-lg">2. Dados coletados</h2>
        <ul class="list-disc space-y-1 pl-6">
            <li>Da empresa: razão social, CNPJ, plano e histórico de faturas;</li>
            <li>dos usuários: nome, e-mail, senha (só o hash), data do último acesso e IP das ações registradas na auditoria;</li>
            <li>dados de pagamento: ficam com o meio de pagamento parceiro, e não com o CadGestor.</li>
        </ul>

        <h2 class="mt-2 text-lg">3. Finalidades e bases legais</h2>
        <p>
            Execução do contrato (conta, cobrança e suporte), cumprimento de obrigações legais (fiscais) e segurança
            (prevenção a fraudes e registro de acessos ao sistema).
        </p>

        <h2 class="mt-2 text-lg">4. Cookies</h2>
        <p>
            Apenas o cookie de sessão, necessário para manter o login, e os cookies de preferência de tela (tema e menu
            recolhido), que não contêm dados pessoais. Não há cookies de publicidade.
        </p>

        <h2 class="mt-2 text-lg">5. Direitos e contato</h2>
        <p>
            Para exercer seus direitos (art. 18 da LGPD), fale com o encarregado: <em>[nome e e-mail do DPO]</em>.
        </p>
    </article>
</x-layouts.publico>
