<?php

namespace App\Http\Controllers\Crm\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\PasswordResetService;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    private const BROKER = 'employees';

    private const GENERIC_MESSAGE = 'If the email exists, a reset link has been sent.';

    public function __construct(
        private readonly PasswordResetService $passwords,
    ) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->passwords->sendResetLink(self::BROKER, $request->validated('email'));

        return response()->json(['message' => self::GENERIC_MESSAGE]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $ok = $this->passwords->reset(self::BROKER, $request->validated());

        if (! $ok) {
            return response()->json(['message' => 'Invalid or expired reset token.'], 422);
        }

        return response()->json(['message' => 'Password has been reset.']);
    }
}
