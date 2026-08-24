<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Mail\SendUserOTP;
use App\Models\AccessCode;
use App\Models\Level;
use App\Models\Referral;
use App\Models\User;
use App\Models\UserLevel;
use App\Models\UserOTP;
use App\Models\Wallet;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;


class AuthController extends Controller
{



    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'username' => [
                'required',
                'string',
                'min:3',
                'max:50',
                'alpha_dash',
                'unique:users,username'
            ],
            'email' => [
                'required',
                'email:rfc,dns',
                'max:255',
                'unique:users,email'
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:255'
            ],
            'referral_code' => [
                'nullable',
                'string',
                'exists:users,referral_code'
            ],
        ]);

        try {

            /**
             * Cache this in production.
             */
            $level = Level::query()
                ->select([
                    'id',
                    'name',
                    'amount',
                    'reg_bonus'
                ])
                ->where('name', 'Basic')
                ->firstOrFail();

            $referrerId = null;

            if (!empty($validated['referral_code'])) {

                $referrerId = User::query()
                    ->where('referral_code', $validated['referral_code'])
                    ->value('id');
            }
            $otp = random_int(100000, 999999);

            $user = DB::transaction(function () use (
                $validated,
                $level,
                $referrerId,
                $otp
            ) {


                /**
                 * Create user
                 */
                $user = User::query()->create([
                    'name' => trim($validated['name']),
                    'username' => strtolower(trim($validated['username'])),
                    'email' => strtolower(trim($validated['email'])),
                    'password' => Hash::make($validated['password']),
                    'referral_code' => $this->generateReferralCode(),
                ]);

                /**
                 * Create wallet
                 */
                Wallet::query()->create([
                    'user_id' => $user->id,
                    'balance' => $level->reg_bonus,
                    'promoter_balance' => 0,
                    'referral_balance' => 0,
                    'currency' => 'USD',
                    'level' => $level->name,
                ]);

                /**
                 * Attach level
                 */
                UserLevel::query()->create([
                    'user_id' => $user->id,
                    'level_id' => $level->id,
                    'plan_name' => $level->name,
                    'next_payment_date' => now()->addYear(),
                ]);

                /**
                 * Create access code
                 */
                $accessCode = AccessCode::query()->create([
                    'tx_id' => (string) Str::uuid(),
                    'name' => $level->name,
                    'email' => $user->email,
                    'amount' => $level->amount,
                    'code' => $otp,
                    'level_id' => $level->id,
                    'is_active' => false,
                ]);

                /**
                 * Update user
                 */
                $user->update([
                    'access_code_id' => $accessCode->id
                ]);

                /**
                 * Referral
                 */
                if ($referrerId) {

                    Referral::query()->create([
                        'user_id' => $user->id,
                        'referral_id' => $referrerId,
                    ]);
                }


                $sendOTP = UserOTP::create([
                    'user_id' => $user->id,
                    'otp' => $otp,
                    'expires_at' => now()->addMinutes(30),
                ]);

                if ($sendOTP) {

                    Mail::to($user->email)->send(new SendUserOTP($otp));
                }

                return $user;
            }, 3);

            /**
             * Fire registration event
             */
            event(new Registered($user));

            /**
             * Create token after transaction commit
             */
            // $token = $user->createToken('PayhankeyApi')->accessToken;

            return response()->json([
                'success' => true,
                'message' => 'Otp Sent to the email Supplied',
                'data' => [
                    'id' => $user->id,
                    'otp' => $otp
                    // 'name' => $user->name,
                    // 'username' => $user->username,
                    // 'email' => $user->email,
                    // 'referral_code' => $user->referral_code,
                    // 'access_token' => $token,
                ],
            ], 201);
        } catch (Throwable $e) {

            Log::error('Registration failed', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(), //'Unable to process registration at this time.'
            ], 500);
        }
    }




    private function generateReferralCode(): string
    {
        do {

            $code = strtoupper(Str::random(8));
        } while (
            User::query()
            ->where('referral_code', $code)
            ->exists()
        );

        return $code;
    }

    public function verifyOTP(Request $request)
    {
        $validated = $request->validate([
            'otp' => ['required', 'string', 'size:6'],
            'id' => ['required', 'string'],

        ]);

        try {
            $fetch = UserOTP::where('user_id', $validated['id'])->where('otp', $validated['otp'])
                ->where('is_used', false)
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (!$fetch) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired OTP'
                ], 422);
            }
            $fetch->is_used = true;
            $fetch->save();

            $user = User::findOrFail($validated['id']);

            $user->email_verified_at = now();
            $user->save();

            // $user->update([
            //     'email_verified_at' => now()
            // ]);

            $token = $user->createToken('PayhankeyApi')->accessToken;

            return response()->json([
                'success' => true,
                'message' => 'OTP verified successfully',
                'data' => [
                    'user_id' => $user->id,
                    'token' => $token
                ]
            ]);
        } catch (Throwable $e) {

            Log::error('Otp verification Problem', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Authentication service unavailable'
            ], 500);
        }
    }

    public function resendOTP(Request $request)
    {
        $validated = $request->validate([
            'id' => ['required', 'string']
        ]);

        try {

             $user = User::where('id', $validated['id'])->first();

             if(!$user){
                
                return response()->json([
                    'status' => false,
                    'message' => 'User not valid'
                ], 401);
            
             }

            $otp = random_int(100000, 999999);
            $sendOTP = UserOTP::create([
                'user_id' => $validated['id'],
                'otp' => $otp,
                'expires_at' => now()->addMinutes(30),
            ]);
           

            if ($sendOTP) {

                Mail::to($user->email)->send(new SendUserOTP($otp));
            }

            return response()->json([
                'success' => true,
                'message' => 'OTP sent successfully',
                'data' => [
                    'id' => $user->id,
                    // 'token' => $token
                ]
            ]);
        } catch (Throwable $e) {

            Log::error('Resend Otp verification Problem', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email:rfc,dns', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $key = 'login:' . $request->ip();

        // 🚨 Rate limiting (very important for high traffic & brute force protection)
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json([
                'message' => 'Too many login attempts. Try again later.'
            ], 429);
        }

        RateLimiter::hit($key, 60);

        try {

            // ⚡ Only select required columns (performance optimization)
            $user = User::query()
                ->select(['id', 'email', 'password', 'name', 'username', 'email_verified_at'])
                ->where('email', $request->email)
                ->first();

            // ❌ Avoid revealing whether email exists
            if (!$user || !Hash::check($request->password, $user->password)) {
                return response()->json([
                    'message' => 'Invalid credentials'
                ], 401);
            }

            if ($user->email_verified_at === null) {
                return response()->json([
                    'message' => 'Email address Not verified'
                ], 401);
            }

            // 🧹 clear rate limit on success
            RateLimiter::clear($key);

            // 🔐 Token generation (Passport)
            $token = $user->createToken('auth_token')->accessToken;

            return response()->json([
                'message' => 'Login successful',
                // 'token_type' => 'Bearer',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'email' => $user->email,
                    'access_token' => $token,
                ]
            ]);
        } catch (Throwable $e) {

            Log::error('Login failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Authentication service unavailable',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function logout(Request $request)
    {
        try {

            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'message' => 'Unauthenticated'
                ], 401);
            }

            // 🔥 Revoke current access token
            $token = $user->token();

            if ($token) {
                $token->revoke();
            }

            // Optional: revoke refresh tokens too (recommended for security)
            if (method_exists($token, 'refreshToken')) {
                $token->refreshToken()?->revoke();
            }

            return response()->json([
                'message' => 'Logged out successfully'
            ]);
        } catch (Throwable $e) {

            Log::error('Logout failed', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Logout failed'
            ], 500);
        }
    }
}
