<?php

namespace App\Console\Commands;

use App\Services\Biometria\CifradorBiometria;
use Illuminate\Console\Command;

/**
 * Cria a chave de cifra dos templates biométricos. Nunca sobrescreve: o arquivo
 * existente só ganha uma versão nova (rotação), e as antigas continuam valendo.
 */
class GerarChaveBiometria extends Command
{
    protected $signature = 'biometria:gerar-chave {--nova-versao : Acrescenta uma versão nova (rotação) ao arquivo existente}';

    protected $description = 'Cria o arquivo de chaves de cifra dos templates biométricos (fora do git)';

    public function handle(): int
    {
        $caminho = CifradorBiometria::resolverCaminho(config('biometria.chaves_arquivo'), base_path());
        $existe = is_file($caminho);

        if ($existe && ! $this->option('nova-versao')) {
            $this->components->info('O arquivo de chaves já existe. Nada foi alterado. Use --nova-versao para rotacionar.');

            return self::SUCCESS;
        }

        $versao = 1;

        if ($existe) {
            $versoes = [];

            foreach (file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
                if (preg_match('/^(\d+):/', trim($linha), $encontrado) === 1) {
                    $versoes[] = (int) $encontrado[1];
                }
            }

            $versao = $versoes === [] ? 1 : max($versoes) + 1;
        }

        if (! is_dir(dirname($caminho))) {
            mkdir(dirname($caminho), 0700, true);
        }

        file_put_contents($caminho, CifradorBiometria::gerarLinhaChave($versao).PHP_EOL, FILE_APPEND | LOCK_EX);

        $this->components->info("Chave versão {$versao} criada em {$caminho}.");

        return self::SUCCESS;
    }
}
