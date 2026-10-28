# Adaptadores de fabricantes

Este é o ponto de extensão para catracas reais. Para cada fabricante/modelo:

1. Crie uma classe aqui (ex.: `AdaptadorFabricanteX.php`) implementando
   `CadGestor\Biometria\IdentificadorBiometrico`.
2. Implemente os métodos **apenas com base na documentação oficial do SDK/API do
   fabricante**. Não suponha formatos de template, limites ou endpoints.
3. Registre o adaptador em `config/servicos.php` e selecione-o pela variável
   `IDENTIFICADOR_BIOMETRICO` do `.env`.

Pontos que dependem do fabricante e precisam ser confirmados:

| Item | Por que importa |
|---|---|
| Formato do template (ISO 19794-2/-5, proprietário) | Define o que o sistema guarda e envia |
| Quem faz o matching (dispositivo ou servidor) | Muda o contrato da API `/api/v1/acesso` |
| 1:N (identificação) ou 1:1 (verificação) | Muda o que o dispositivo envia |
| Exige a foto para gerar o template facial? | Decide se a tabela `foto_facial` precisa existir |
| Limite de usuários por dispositivo | Afeta a sincronização |
| Comportamento offline (lista local) | Afeta o modo de falha |

Enquanto isso, use `IdentificadorSimulado` (`IDENTIFICADOR_BIOMETRICO=simulado`).
