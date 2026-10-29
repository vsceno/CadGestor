<x-layouts.publico titulo="Termos de Uso" largura="max-w-3xl">
    <x-alerta tipo="atencao" titulo="MODELO para revisão jurídica" :fechavel="false">
        Versão {{ $versao }}. Não utilizar comercialmente antes da aprovação do advogado.
    </x-alerta>

    <article class="flex flex-col gap-4 leading-relaxed">
        <h1 class="text-2xl">Termos de Uso do CadGestor</h1>

        <h2 class="mt-2 text-lg">1. Objeto</h2>
        <p>
            O CadGestor é um software de controle de acesso físico oferecido como serviço (SaaS). A empresa contratante
            ("Cliente") recebe um ambiente próprio e isolado, no qual cadastra e administra suas empresas, catracas,
            pessoas, vínculos, consentimentos e usuários.
        </p>

        <h2 class="mt-2 text-lg">2. Conta e responsabilidade pelo ambiente</h2>
        <p>
            O Cliente é o único responsável pelo próprio ambiente: pelos usuários que cria, pelos dados que insere, pela
            base legal e pelo consentimento dos titulares, e pela guarda das senhas e dos tokens das catracas. A equipe
            do CadGestor não acessa o ambiente do Cliente.
        </p>

        <h2 class="mt-2 text-lg">3. Planos, teste grátis e pagamento</h2>
        <p>
            O Cliente escolhe um plano mensal com limites de pessoas e catracas ativas. O teste grátis dura o número de
            dias informado no cadastro. A cobrança é feita por um meio de pagamento parceiro; o CadGestor não recebe nem
            armazena dados de cartão. A troca de plano vale a partir da fatura seguinte.
        </p>

        <h2 class="mt-2 text-lg">4. Atraso de pagamento</h2>
        <p>
            Em caso de atraso: até o 6º dia, o serviço segue normal, com aviso; do 7º ao 14º dia, o sistema fica em modo
            somente leitura; do 15º ao 29º dia, o sistema fica suspenso, mas <strong>as catracas continuam
            funcionando</strong>; a partir do 30º dia, as catracas também deixam de liberar acessos. Com o pagamento,
            tudo volta ao normal. <em>[Prazos provisórios: confirmar.]</em>
        </p>

        <h2 class="mt-2 text-lg">5. Cancelamento e devolução dos dados</h2>
        <p>
            O Cliente pode cancelar a qualquer momento em "Minha assinatura". Com o cancelamento, as catracas param e o
            ambiente fica disponível apenas para exportação dos dados por <em>[prazo a definir]</em> dias, após os quais
            os dados são excluídos de forma definitiva.
        </p>

        <h2 class="mt-2 text-lg">6. Emergências</h2>
        <p>
            A liberação das catracas em caso de incêndio ou emergência deve ser garantida por dispositivo físico
            independente do software, sob responsabilidade do Cliente.
        </p>

        <h2 class="mt-2 text-lg">7. Disponibilidade e limitação de responsabilidade</h2>
        <p>
            <em>[Cláusulas de nível de serviço, manutenção programada, limitação de responsabilidade, reajuste e foro: a
            redigir pelo jurídico.]</em>
        </p>

        <h2 class="mt-2 text-lg">8. Proteção de dados</h2>
        <p>
            O tratamento de dados pessoais dos titulares cadastrados pelo Cliente segue o
            <a href="{{ route('documentos.contrato-dados') }}">Contrato de Tratamento de Dados</a>, parte integrante
            destes Termos.
        </p>
    </article>
</x-layouts.publico>
