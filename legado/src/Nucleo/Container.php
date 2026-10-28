<?php

declare(strict_types=1);

namespace CadGestor\Nucleo;

use RuntimeException;

/**
 * Container de serviços mínimo: cada serviço é criado na primeira vez que
 * alguém pede e reaproveitado depois (singleton por requisição).
 */
final class Container
{
    /** @var array<string, callable(self): object> */
    private array $fabricas = [];

    /** @var array<string, object> */
    private array $instancias = [];

    /**
     * @template T of object
     * @param class-string<T>     $id
     * @param callable(self): T   $fabrica
     */
    public function registrar(string $id, callable $fabrica): void
    {
        $this->fabricas[$id] = $fabrica;
        unset($this->instancias[$id]);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    public function obter(string $id): object
    {
        if (!isset($this->instancias[$id])) {
            if (!isset($this->fabricas[$id])) {
                throw new RuntimeException("Serviço não registrado: {$id}");
            }

            $this->instancias[$id] = ($this->fabricas[$id])($this);
        }

        /** @var T */
        return $this->instancias[$id];
    }
}
