<?php

use App\Http\Controllers\Api\CatracaApiController;
use Illuminate\Support\Facades\Route;

/*
 * API dos dispositivos (catracas). Sem sessão: cada requisição prova a identidade
 * com o token da catraca (Authorization: Bearer cgt_...), guardado só como hash.
 * A catraca não envia nome nem CPF: envia o id da biometria que reconheceu.
 */
Route::prefix('catracas/v1')->middleware('catraca')->name('api.catracas.')->group(function (): void {
    Route::post('/heartbeat', [CatracaApiController::class, 'heartbeat'])->name('heartbeat');
    Route::get('/sincronizacao', [CatracaApiController::class, 'sincronizacao'])->name('sincronizacao');
    Route::post('/sincronizacao/{operacao}/resultado', [CatracaApiController::class, 'resultadoSincronizacao'])
        ->whereUuid('operacao')
        ->name('sincronizacao.resultado');
    Route::post('/acessos', [CatracaApiController::class, 'acesso'])->name('acessos');
    Route::post('/eventos-offline', [CatracaApiController::class, 'eventosOffline'])->name('eventos-offline');
});
