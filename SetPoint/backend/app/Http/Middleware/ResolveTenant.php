<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Monta o escopo de empresa/filial do usuário autenticado. Header opcional: X-Branch-Id. */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->active) {
            abort(401, 'Sessão inválida');
        }

        $branchId = $request->header('X-Branch-Id');
        $context = new TenantContext($user, $branchId !== null && $branchId !== '' ? (int) $branchId : null);

        if ($context->requestedBranchId && ! in_array($context->requestedBranchId, $context->accessibleBranchIds(), true)) {
            abort(403, 'Sem acesso a esta filial');
        }
        TenantContext::set($context);

        return $next($request);
    }

    public function terminate(): void
    {
        TenantContext::set(null);
    }
}
