<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Chaves de cifra dos templates biométricos
    |--------------------------------------------------------------------------
    |
    | Arquivo com uma chave por linha ("versao:base64"). Fica FORA do banco,
    | FORA do git e FORA de public/: quem rouba só o banco leva um cofre sem
    | a chave. Gere com: php artisan biometria:gerar-chave
    |
    */

    'chaves_arquivo' => env('BIOMETRIA_CHAVES_ARQUIVO', 'storage/chaves/biometria.key'),

];
