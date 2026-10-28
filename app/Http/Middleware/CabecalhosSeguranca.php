<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeçalhos de segurança de todas as respostas.
 *
 * A CSP só permite recursos do próprio servidor: fontes, ícones, CSS e JS
 * vêm do build (public/build). Nenhum script inline, nenhum CDN. Em
 * desenvolvimento com "npm run dev", o servidor do Vite também é liberado.
 */
class CabecalhosSeguranca
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $resposta = $next($request);

        $origemVite = Vite::isRunningHot() ? $this->origemVite() : '';
        $vite = $origemVite === '' ? '' : ' '.$origemVite;
        $viteWs = $origemVite === '' ? '' : ' '.preg_replace('#^http#', 'ws', $origemVite);

        $resposta->headers->add([
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "script-src 'self'{$vite}",
                "style-src 'self'{$vite}",
                "font-src 'self'{$vite}",
                "img-src 'self' data: blob:",
                "connect-src 'self'{$vite}{$viteWs}",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "base-uri 'self'",
                "object-src 'none'",
            ]),
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ]);

        return $resposta;
    }

    /** Origem do servidor do Vite (arquivo public/hot), ex.: http://[::1]:5173. */
    private function origemVite(): string
    {
        $arquivo = public_path('hot');
        $partes = is_file($arquivo) ? parse_url(trim((string) file_get_contents($arquivo))) : false;

        if (! is_array($partes) || ! isset($partes['scheme'], $partes['host'])) {
            return '';
        }

        return $partes['scheme'].'://'.$partes['host'].(isset($partes['port']) ? ':'.$partes['port'] : '');
    }
}
