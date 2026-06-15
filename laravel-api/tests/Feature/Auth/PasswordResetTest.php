<?php

namespace Tests\Feature\Auth;

use App\Models\Customer;
use App\Models\Employee;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    /** AC-04.1 */
    public function test_forgot_password_sends_link_for_existing_customer(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/shop/auth/forgot-password', ['email' => 'jane@example.com'])
            ->assertOk();

        Notification::assertSentTo($customer, ResetPassword::class);
    }

    /** AC-04.2 — không lộ tồn tại: vẫn 200, không gửi gì */
    public function test_forgot_password_is_generic_for_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/shop/auth/forgot-password', ['email' => 'nobody@example.com'])
            ->assertOk();

        Notification::assertNothingSent();
    }

    /** AC-04.3 — đổi pass + revoke toàn bộ token cũ */
    public function test_reset_password_changes_password_and_revokes_tokens(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'jane@example.com',
            'password' => Hash::make('oldpass1'),
        ]);
        $customer->createToken('shop'); // token cũ
        $this->assertSame(1, $customer->tokens()->count());

        $token = Password::broker('customers')->createToken($customer);

        $this->postJson('/api/shop/auth/reset-password', [
            'token' => $token,
            'email' => 'jane@example.com',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertOk();

        $customer->refresh();
        $this->assertTrue(Hash::check('newpass123', $customer->password));
        $this->assertSame(0, $customer->tokens()->count());
    }

    /** AC-04.4 */
    public function test_reset_with_invalid_token_fails(): void
    {
        Customer::factory()->create(['email' => 'jane@example.com']);

        $this->postJson('/api/shop/auth/reset-password', [
            'token' => 'totally-invalid-token',
            'email' => 'jane@example.com',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertStatus(422);
    }

    /** AC-04.5 — token Shop không dùng được cho CRM */
    public function test_customer_reset_token_cannot_be_used_on_crm(): void
    {
        $customer = Customer::factory()->create([
            'email' => 'same@example.com',
            'password' => Hash::make('oldpass1'),
        ]);
        $employee = Employee::factory()->create([
            'email' => 'same@example.com',
            'password' => Hash::make('oldpass1'),
        ]);

        $token = Password::broker('customers')->createToken($customer);

        $this->postJson('/api/crm/auth/reset-password', [
            'token' => $token,
            'email' => 'same@example.com',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertStatus(422);

        // mật khẩu employee không đổi
        $this->assertTrue(Hash::check('oldpass1', $employee->fresh()->password));
    }

    /** CRM broker hoạt động độc lập */
    public function test_crm_forgot_and_reset_for_employee(): void
    {
        Notification::fake();
        $employee = Employee::factory()->create([
            'email' => 'emp@example.com',
            'password' => Hash::make('oldpass1'),
        ]);

        $this->postJson('/api/crm/auth/forgot-password', ['email' => 'emp@example.com'])
            ->assertOk();
        Notification::assertSentTo($employee, ResetPassword::class);

        $token = Password::broker('employees')->createToken($employee);
        $this->postJson('/api/crm/auth/reset-password', [
            'token' => $token,
            'email' => 'emp@example.com',
            'password' => 'newpass123',
            'password_confirmation' => 'newpass123',
        ])->assertOk();

        $this->assertTrue(Hash::check('newpass123', $employee->fresh()->password));
    }

    public function test_reset_validation_requires_fields(): void
    {
        $this->postJson('/api/shop/auth/reset-password', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['token', 'email', 'password']);
    }
}
