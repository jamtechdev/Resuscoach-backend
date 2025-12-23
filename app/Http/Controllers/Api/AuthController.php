<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\PasswordResetEmailRequest;
use App\Http\Requests\PasswordResetRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'is_admin' => false,
        ]);

        // Send email verification notification
        $user->sendEmailVerificationNotification();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
            'message' => 'Registration successful. Please verify your email address.',
        ], 201);
    }

    /**
     * Login user and return authentication token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        // Only allow regular users to login via API (not admins)
        if ($user->is_admin) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials',
            ], 401);
        }

        // Check if email is verified
        if (!$user->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Please verify your email address before logging in.',
                'data' => [
                    'email_verified' => false,
                    'user_id' => $user->id,
                ],
            ], 403);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'data' => [
                'user' => new UserResource($user),
                'token' => $token,
            ],
        ]);
    }

    /**
     * Get the authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new UserResource($request->user()),
        ]);
    }

    /**
     * Logout the authenticated user.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Send password reset link to user's email.
     */
    public function sendPasswordResetLink(PasswordResetEmailRequest $request): JsonResponse
    {
        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'success' => true,
                'message' => 'Password reset link has been sent to your email address.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unable to send password reset link. Please try again later.',
        ], 500);
    }

    /**
     * Reset user password with token.
     */
    public function resetPassword(PasswordResetRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'success' => true,
                'message' => 'Password has been reset successfully.',
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Invalid or expired reset token.',
        ], 400);
    }

    /**
     * Verify user email address.
     */
    public function verifyEmail(Request $request, int $id, string $hash)
    {
        $user = User::findOrFail($id);

        if ($user->hasVerifiedEmail()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email already verified.',
                ], 400);
            }
            return $this->renderVerificationPage('already_verified', $user);
        }

        // Verify the hash matches the user's email
        if (!hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid verification link.',
                ], 400);
            }
            return $this->renderVerificationPage('invalid', $user);
        }

        // Mark email as verified
        if ($user->markEmailAsVerified()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Email verified successfully.',
                    'data' => new UserResource($user),
                ]);
            }
            return $this->renderVerificationPage('success', $user);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to verify email.',
            ], 500);
        }
        return $this->renderVerificationPage('error', $user);
    }

    /**
     * Render a user-friendly verification page.
     */
    private function renderVerificationPage(string $status, User $user)
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        $messages = [
            'success' => [
                'title' => 'Email Verified Successfully!',
                'message' => 'Your email address has been verified. You can now log in to your account.',
                'icon' => '✓',
                'color' => 'green',
            ],
            'already_verified' => [
                'title' => 'Email Already Verified',
                'message' => 'Your email address was already verified. You can log in to your account.',
                'icon' => 'ℹ',
                'color' => 'blue',
            ],
            'invalid' => [
                'title' => 'Invalid Verification Link',
                'message' => 'This verification link is invalid or has expired. Please request a new verification email.',
                'icon' => '✗',
                'color' => 'red',
            ],
            'error' => [
                'title' => 'Verification Failed',
                'message' => 'We encountered an error while verifying your email. Please try again or contact support.',
                'icon' => '⚠',
                'color' => 'orange',
            ],
        ];

        $content = $messages[$status] ?? $messages['error'];

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$content['title']} - ResusCoach</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }
        .icon {
            font-size: 64px;
            margin-bottom: 20px;
            color: {$content['color']};
        }
        h1 {
            color: #1a202c;
            font-size: 28px;
            margin-bottom: 16px;
            font-weight: 600;
        }
        p {
            color: #4a5568;
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        .button {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 14px 32px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 16px;
            transition: background 0.3s;
            margin-top: 10px;
        }
        .button:hover {
            background: #5568d3;
        }
        .button-secondary {
            background: #e2e8f0;
            color: #4a5568;
            margin-left: 10px;
        }
        .button-secondary:hover {
            background: #cbd5e0;
        }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            color: #718096;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">{$content['icon']}</div>
        <h1>{$content['title']}</h1>
        <p>{$content['message']}</p>
        <div>
            <a href="{$frontendUrl}/login" class="button">Go to Login</a>
            <a href="{$frontendUrl}" class="button button-secondary">Go to Home</a>
        </div>
        <div class="footer">
            <p>ResusCoach - Medical Exam Preparation Platform</p>
        </div>
    </div>
</body>
</html>
HTML;

        return response($html, 200)->header('Content-Type', 'text/html');
    }

    /**
     * Resend email verification notification.
     */
    public function resendVerificationEmail(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json([
                'success' => false,
                'message' => 'Email already verified.',
            ], 400);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json([
            'success' => true,
            'message' => 'Verification email has been sent.',
        ]);
    }
}
