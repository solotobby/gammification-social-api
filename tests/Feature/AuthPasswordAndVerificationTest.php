<?php

namespace Tests\Feature;

use App\Mail\AccountVerifiedMail;
use App\Mail\PasswordChangedMail;
use App\Mail\SendPasswordResetOTP;
use App\Mail\SendUserOTP;
use App\Mail\WelcomeAccountMail;
use App\Models\Level;
use App\Models\User;
use App\Models\UserOTP;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AuthPasswordAndVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected Level $basicLevel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basicLevel = Level::create([
            'name' => 'Basic',
            'amount' => 0,
            'reg_bonus' => 0,
            'ref_bonus' => 0,
            'min_withdrawal' => 0,
            'earning_per_view' => 0,
            'earning_per_like' => 0,
            'earning_per_comment' => 0,
        ]);

        $this->artisan('passport:client', [
            '--personal' => true,
            '--name' => 'Payhankey Personal Access Client',
            '--provider' => 'users',
            '--no-interaction' => true,
        ]);
    }

    public function test_registration_sends_verification_otp_only(): void
    {
        Mail::fake();

        $payload = [
            'name' => 'Tobi Solomon',
            'username' => 'tobisolomon',
            'email' => 'tobi@payhankey.com',
            'password' => 'SecurePass123!',
        ];

        $response = $this->postJson('/v1/register', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Otp Sent to the email Supplied',
            ]);

        $user = User::where('email', 'tobi@payhankey.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);

        $this->assertDatabaseHas('user_o_t_p_s', [
            'user_id' => $user->id,
            'type' => UserOTP::TYPE_VERIFICATION,
            'is_used' => false,
        ]);

        Mail::assertSent(SendUserOTP::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        // Welcome email is NOT sent at registration; it is sent after verification
        Mail::assertNotSent(WelcomeAccountMail::class);
    }

    public function test_verify_otp_marks_email_verified_and_sends_welcome_email(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'member@payhankey.com',
            'email_verified_at' => null,
        ]);

        $otp = '123456';
        UserOTP::create([
            'user_id' => $user->id,
            'otp' => $otp,
            'type' => UserOTP::TYPE_VERIFICATION,
            'expires_at' => now()->addMinutes(30),
            'is_used' => false,
        ]);

        $response = $this->postJson('/v1/verify/otp', [
            'id' => $user->id,
            'otp' => $otp,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'OTP verified successfully',
            ])
            ->assertJsonStructure([
                'data' => ['user_id', 'token'],
            ]);

        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->assertDatabaseHas('user_o_t_p_s', [
            'user_id' => $user->id,
            'otp' => $otp,
            'is_used' => true,
        ]);

        // Welcome email sent after verification
        Mail::assertSent(WelcomeAccountMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_verify_otp_rejects_expired_or_invalid_otp(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email_verified_at' => null]);

        UserOTP::create([
            'user_id' => $user->id,
            'otp' => '999999',
            'type' => UserOTP::TYPE_VERIFICATION,
            'expires_at' => now()->subMinute(),
            'is_used' => false,
        ]);

        $response = $this->postJson('/v1/verify/otp', [
            'id' => $user->id,
            'otp' => '999999',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid or expired OTP',
            ]);

        $this->assertNull($user->fresh()->email_verified_at);
        Mail::assertNotSent(WelcomeAccountMail::class);
    }

    public function test_resend_otp_sends_new_verification_code(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email_verified_at' => null]);

        $response = $this->postJson('/v1/resend/otp', [
            'id' => $user->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'OTP sent successfully',
            ]);

        $this->assertDatabaseHas('user_o_t_p_s', [
            'user_id' => $user->id,
            'type' => UserOTP::TYPE_VERIFICATION,
            'is_used' => false,
        ]);

        Mail::assertSent(SendUserOTP::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_forgot_password_sends_password_reset_otp_email(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@payhankey.com',
            'password' => Hash::make('OldPassword123!'),
        ]);

        $response = $this->postJson('/v1/forgot-password', [
            'email' => 'john@payhankey.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Password reset code sent to your email address.',
                'data' => [
                    'email' => 'john@payhankey.com',
                    'expires_in_minutes' => 15,
                ],
            ]);

        $this->assertDatabaseHas('user_o_t_p_s', [
            'user_id' => $user->id,
            'type' => UserOTP::TYPE_PASSWORD_RESET,
            'is_used' => false,
        ]);

        Mail::assertSent(SendPasswordResetOTP::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_forgot_password_returns_404_for_unknown_email(): void
    {
        Mail::fake();

        $response = $this->postJson('/v1/forgot-password', [
            'email' => 'nonexistent@payhankey.com',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'We could not find an account associated with this email address.',
            ]);

        Mail::assertNotSent(SendPasswordResetOTP::class);
    }

    public function test_verify_forgot_password_otp_validates_code(): void
    {
        $user = User::factory()->create([
            'email' => 'verify@payhankey.com',
        ]);

        UserOTP::create([
            'user_id' => $user->id,
            'otp' => '654321',
            'type' => UserOTP::TYPE_PASSWORD_RESET,
            'expires_at' => now()->addMinutes(15),
            'is_used' => false,
        ]);

        // Wrong OTP
        $failResponse = $this->postJson('/v1/verify/forgot-password-otp', [
            'email' => 'verify@payhankey.com',
            'otp' => '000000',
        ]);
        $failResponse->assertStatus(422);

        // Correct OTP
        $passResponse = $this->postJson('/v1/verify/forgot-password-otp', [
            'email' => 'verify@payhankey.com',
            'otp' => '654321',
        ]);
        $passResponse->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'OTP verified successfully. You can now reset your password.',
            ]);
    }

    public function test_reset_password_updates_password_and_sends_alert(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'resetuser@payhankey.com',
            'password' => Hash::make('OldPassword123!'),
            'email_verified_at' => now(),
        ]);

        UserOTP::create([
            'user_id' => $user->id,
            'otp' => '112233',
            'type' => UserOTP::TYPE_PASSWORD_RESET,
            'expires_at' => now()->addMinutes(15),
            'is_used' => false,
        ]);

        $response = $this->postJson('/v1/reset-password', [
            'email' => 'resetuser@payhankey.com',
            'otp' => '112233',
            'password' => 'NewBrandPass123!',
            'password_confirmation' => 'NewBrandPass123!',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Password reset successfully. You can now log in with your new password.',
            ]);

        $this->assertTrue(Hash::check('NewBrandPass123!', $user->fresh()->password));

        $this->assertDatabaseHas('user_o_t_p_s', [
            'user_id' => $user->id,
            'otp' => '112233',
            'is_used' => true,
        ]);

        Mail::assertSent(PasswordChangedMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });

        // Verify login works with new password
        $loginResponse = $this->postJson('/v1/login', [
            'email' => 'resetuser@payhankey.com',
            'password' => 'NewBrandPass123!',
        ]);
        $loginResponse->assertStatus(200);

        // Verify old password fails
        $oldLoginResponse = $this->postJson('/v1/login', [
            'email' => 'resetuser@payhankey.com',
            'password' => 'OldPassword123!',
        ]);
        $oldLoginResponse->assertStatus(401);
    }

    public function test_reset_password_rejects_used_or_expired_otp(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'usedotp@payhankey.com',
            'password' => Hash::make('OldPassword123!'),
        ]);

        UserOTP::create([
            'user_id' => $user->id,
            'otp' => '445566',
            'type' => UserOTP::TYPE_PASSWORD_RESET,
            'expires_at' => now()->addMinutes(15),
            'is_used' => true, // already used
        ]);

        $response = $this->postJson('/v1/reset-password', [
            'email' => 'usedotp@payhankey.com',
            'otp' => '445566',
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid or expired OTP code.',
            ]);

        $this->assertTrue(Hash::check('OldPassword123!', $user->fresh()->password));
        Mail::assertNotSent(PasswordChangedMail::class);
    }

    public function test_change_password_requires_correct_current_password(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password' => Hash::make('CurrentSecret123!'),
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/v1/user/change-password', [
                'current_password' => 'WrongPassword999!',
                'password' => 'NewBrandPass123!',
                'password_confirmation' => 'NewBrandPass123!',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'The provided current password does not match our records.',
            ]);

        $this->assertTrue(Hash::check('CurrentSecret123!', $user->fresh()->password));
        Mail::assertNotSent(PasswordChangedMail::class);
    }

    public function test_change_password_rejects_identical_password(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password' => Hash::make('CurrentSecret123!'),
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/v1/user/change-password', [
                'current_password' => 'CurrentSecret123!',
                'password' => 'CurrentSecret123!',
                'password_confirmation' => 'CurrentSecret123!',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'New password cannot be the same as your current password.',
            ]);

        Mail::assertNotSent(PasswordChangedMail::class);
    }

    public function test_change_password_updates_password_and_sends_alert(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'password' => Hash::make('CurrentSecret123!'),
        ]);

        $response = $this->actingAs($user, 'api')
            ->postJson('/v1/user/change-password', [
                'current_password' => 'CurrentSecret123!',
                'password' => 'BrandNewPassword456!',
                'password_confirmation' => 'BrandNewPassword456!',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Password changed successfully.',
            ]);

        $this->assertTrue(Hash::check('BrandNewPassword456!', $user->fresh()->password));

        Mail::assertSent(PasswordChangedMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email);
        });
    }

    public function test_cannot_reset_password_with_verification_otp(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'isolation@payhankey.com',
            'password' => Hash::make('OldPassword123!'),
        ]);

        // Create a VERIFICATION OTP
        UserOTP::create([
            'user_id' => $user->id,
            'otp' => '998877',
            'type' => UserOTP::TYPE_VERIFICATION,
            'expires_at' => now()->addMinutes(15),
            'is_used' => false,
        ]);

        // Attempt to reset password with the verification OTP
        $response = $this->postJson('/v1/reset-password', [
            'email' => 'isolation@payhankey.com',
            'otp' => '998877',
            'password' => 'HackedPassword123!',
            'password_confirmation' => 'HackedPassword123!',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid or expired OTP code.',
            ]);

        $this->assertTrue(Hash::check('OldPassword123!', $user->fresh()->password));
    }

    public function test_cannot_verify_email_with_password_reset_otp(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'isolation2@payhankey.com',
            'email_verified_at' => null,
        ]);

        // Create a PASSWORD_RESET OTP
        UserOTP::create([
            'user_id' => $user->id,
            'otp' => '776655',
            'type' => UserOTP::TYPE_PASSWORD_RESET,
            'expires_at' => now()->addMinutes(15),
            'is_used' => false,
        ]);

        // Attempt to verify email using the password reset OTP
        $response = $this->postJson('/v1/verify/otp', [
            'id' => $user->id,
            'otp' => '776655',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'Invalid or expired OTP',
            ]);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_api_v1_alias_routes_work_identically(): void
    {
        Mail::fake();

        $user = User::factory()->create([
            'email' => 'alias@payhankey.com',
            'password' => Hash::make('Password123!'),
        ]);

        $response = $this->postJson('/api/v1/forgot-password', [
            'email' => 'alias@payhankey.com',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);
    }
}

