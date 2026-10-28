<?php

declare(strict_types=1);

namespace CadGestor\Biometria;

enum TipoBiometria: string
{
    case Digital = 'digital';
    case Facial = 'facial';

    public function rotulo(): string
    {
        return match ($this) {
            self::Digital => 'Digital',
            self::Facial => 'Facial',
        };
    }
}
