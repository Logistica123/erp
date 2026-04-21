<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\UsuarioPerfil;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

class AuthController
{
    /**
     * POST /api/erp/auth/login
     * Valida email + password. Si el usuario tiene MFA habilitado devuelve un
     * token pre-MFA con TTL corto que solo sirve para /auth/mfa/verify.
     * Si no tiene MFA, devuelve un token normal ya apto para /api/erp/*.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'Credenciales inválidas.'], 401);
        }

        $perfil = $user->erpPerfil;
        if (! $perfil || ! $perfil->acceso_erp) {
            return response()->json(['message' => 'Sin acceso al ERP.'], 403);
        }

        if ($perfil->estaBloqueado()) {
            return response()->json(['message' => 'Usuario bloqueado temporalmente.'], 423);
        }

        if ($perfil->mfa_habilitado) {
            $token = $user->createToken('erp:mfa_pending', ['mfa:challenge'], now()->addMinutes(5));

            return response()->json([
                'mfa_required' => true,
                'pre_token' => $token->plainTextToken,
                'expires_at' => $token->accessToken->expires_at,
            ]);
        }

        $perfil->update(['ultimo_login' => now(), 'ultimo_ip' => $request->ip(), 'intentos_fallidos' => 0]);
        $token = $user->createToken('erp:session');

        return response()->json([
            'mfa_required' => false,
            'token' => $token->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    /**
     * POST /api/erp/auth/mfa/verify
     * Recibe el pre-token y el código TOTP, y lo cambia por un token full.
     */
    public function verifyMfa(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $request->user();
        if (! $user || ! $user->currentAccessToken() || ! $user->tokenCan('mfa:challenge')) {
            return response()->json(['message' => 'Token pre-MFA inválido.'], 401);
        }

        $perfil = $user->erpPerfil;
        if (! $perfil || ! $perfil->mfa_habilitado || ! $perfil->mfa_secret) {
            return response()->json(['message' => 'MFA no configurado.'], 400);
        }

        $google2fa = new Google2FA();
        $valid = $google2fa->verifyKey($perfil->mfa_secret, $data['code']);

        if (! $valid) {
            $perfil->increment('intentos_fallidos');
            if ($perfil->intentos_fallidos >= 5) {
                $perfil->update(['bloqueado_hasta' => now()->addMinutes(15)]);
            }

            return response()->json(['message' => 'Código MFA inválido.'], 401);
        }

        $user->currentAccessToken()->delete();
        $perfil->update(['ultimo_login' => now(), 'ultimo_ip' => $request->ip(), 'intentos_fallidos' => 0]);
        $token = $user->createToken('erp:session:mfa_ok');

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    /**
     * POST /api/erp/auth/mfa/setup
     * Genera un secret nuevo y lo guarda en mfa_secret (no lo activa).
     * El usuario lo configura en su app autenticadora y confirma con /mfa/enable.
     */
    public function setupMfa(Request $request): JsonResponse
    {
        $user = $request->user();
        $perfil = $user->erpPerfil;

        if (! $perfil) {
            return response()->json(['message' => 'Sin perfil ERP.'], 403);
        }

        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();
        $perfil->update(['mfa_secret' => $secret, 'mfa_habilitado' => false]);

        $issuer = config('app.name');
        $otpauth = $google2fa->getQRCodeUrl($issuer, $user->email, $secret);

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $otpauth,
            'issuer' => $issuer,
        ]);
    }

    /**
     * POST /api/erp/auth/mfa/enable
     * Confirma que el usuario configuró la app autenticadora correctamente.
     */
    public function enableMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);
        $perfil = $request->user()->erpPerfil;

        if (! $perfil || ! $perfil->mfa_secret) {
            return response()->json(['message' => 'Primero ejecutá /mfa/setup.'], 400);
        }

        $google2fa = new Google2FA();
        if (! $google2fa->verifyKey($perfil->mfa_secret, $data['code'])) {
            return response()->json(['message' => 'Código inválido.'], 401);
        }

        $perfil->update(['mfa_habilitado' => true]);

        return response()->json(['message' => 'MFA activado.']);
    }

    /**
     * POST /api/erp/auth/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    /**
     * GET /api/erp/auth/me
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $perfil = $user->erpPerfil()->with('roles.permisos')->first();

        return response()->json([
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'perfil' => $perfil ? [
                'legajo' => $perfil->legajo,
                'mfa_habilitado' => $perfil->mfa_habilitado,
                'acceso_erp' => $perfil->acceso_erp,
                'ultimo_login' => $perfil->ultimo_login,
                'roles' => $perfil->roles->map(fn ($r) => [
                    'codigo' => $r->codigo,
                    'nombre' => $r->nombre,
                ]),
                'permisos' => $perfil->roles->pluck('permisos')->flatten()->pluck('codigo')->unique()->values(),
            ] : null,
        ]);
    }
}
