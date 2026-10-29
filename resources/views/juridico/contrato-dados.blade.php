<x-layouts.publico titulo="Contrato de Tratamento de Dados" largura="max-w-3xl">
    <x-alerta tipo="atencao" titulo="MODELO para revisão jurídica" :fechavel="false">
        Versão {{ $versao }}. Não utilizar comercialmente antes da aprovação do advogado.
    </x-alerta>

    <article class="flex flex-col gap-4 leading-relaxed">
        <h1 class="text-2xl">Contrato de Tratamento de Dados Pessoais</h1>

        <h2 class="mt-2 text-lg">1. Papéis (LGPD, arts. 5º, VI e VII, e 39)</h2>
        <p>
            O <strong>Cliente é o CONTROLADOR</strong> dos dados das pessoas que cadastra (funcionários, alunos, prestadores
            e outros). O <strong>CadGestor é o OPERADOR</strong>: trata esses dados somente para executar o serviço e
            conforme as instruções do Cliente, dadas pelo uso do sistema.
        </p>

        <h2 class="mt-2 text-lg">2. Dados e finalidade</h2>
        <p>
            Dados cadastrais, vínculos, eventos de acesso, registros de consentimento e templates biométricos (dado pessoal
            sensível), tratados exclusivamente para o controle de acesso físico do Cliente.
        </p>

        <h2 class="mt-2 text-lg">3. Obrigações do Cliente</h2>
        <p>
            Definir a base legal de cada tratamento, coletar e registrar o consentimento quando exigido (inclusive o do
            responsável legal de menores), atender os titulares e manter os usuários e senhas sob controle.
        </p>

        <h2 class="mt-2 text-lg">4. Medidas de segurança do Operador</h2>
        <ul class="list-disc space-y-1 pl-6">
            <li>Ambiente de cada Cliente isolado no banco de dados (Row-Level Security);</li>
            <li>templates biométricos cifrados, com a chave fora do banco; nenhuma imagem de digital armazenada;</li>
            <li>senhas com Argon2id; tokens de catraca guardados só como hash;</li>
            <li>trilha de auditoria só de acréscimo; registros técnicos sem dados pessoais;</li>
            <li>a equipe do CadGestor não acessa o ambiente do Cliente.</li>
        </ul>

        <h2 class="mt-2 text-lg">5. Suboperadores</h2>
        <p>
            Hospedagem <em>[a definir]</em>, meio de pagamento <em>[a definir]</em> (apenas dados de cobrança do Cliente) e
            envio de e-mails <em>[a definir]</em>.
        </p>

        <h2 class="mt-2 text-lg">6. Incidentes</h2>
        <p>
            O Operador comunicará ao Cliente incidente de segurança que possa acarretar risco ou dano relevante em até
            <em>[prazo a definir]</em> horas, com as informações do art. 48 da LGPD.
        </p>

        <h2 class="mt-2 text-lg">7. Término</h2>
        <p>
            Encerrado o contrato, o Cliente pode exportar seus dados por <em>[prazo a definir]</em> dias; depois, o Operador
            os elimina, inclusive os templates biométricos, e fornece declaração de exclusão.
        </p>
    </article>
</x-layouts.publico>
