<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Retenção de titulares
    |--------------------------------------------------------------------------
    |
    | Dias sem vínculo em aberto até o titular ser anonimizado. PROVISÓRIO: o
    | prazo definitivo depende da revisão jurídica (ver docs/revisao-seguranca.md).
    | Os eventos de acesso não são apagados; eles ficam sem identificação.
    |
    */

    'dias_retencao' => (int) env('MANUTENCAO_DIAS_RETENCAO', 30),

    /*
    |--------------------------------------------------------------------------
    | Catracas sem contato
    |--------------------------------------------------------------------------
    |
    | Minutos sem falar com o servidor até o aviso de catraca offline.
    |
    */

    'minutos_sem_contato' => (int) env('MANUTENCAO_MINUTOS_SEM_CONTATO', 30),

];
