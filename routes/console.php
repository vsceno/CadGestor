<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Catracas sem contato são avisadas logo; vínculos e retenção rodam de madrugada, uma vez por dia.
Schedule::command('manutencao:executar --tarefa=catracas')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('manutencao:executar --tarefa=vinculos --tarefa=retencao')->dailyAt('03:00')->withoutOverlapping();
