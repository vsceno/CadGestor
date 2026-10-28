<?php

use App\Http\Controllers\DesignSystemController;
use Illuminate\Support\Facades\Route;

/*
 * Fase 0: só a vitrine do design system. As telas do sistema entram nas
 * próximas fases, montadas com os componentes de resources/views/components.
 */
Route::get('/', function () {
    return app()->isProduction() ? abort(404) : redirect()->route('design-system.index');
});

// Vitrine de componentes: nunca existe em produção.
if (! app()->isProduction()) {
    Route::get('/design-system', [DesignSystemController::class, 'index'])->name('design-system.index');
    Route::post('/design-system/validar', [DesignSystemController::class, 'validar'])->name('design-system.validar');
}
