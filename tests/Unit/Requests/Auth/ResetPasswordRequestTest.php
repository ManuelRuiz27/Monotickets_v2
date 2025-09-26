<?php

namespace Tests\Unit\Requests\Auth;

use App\Http\Requests\Auth\ResetPasswordRequest;
use Tests\Unit\Requests\FormRequestTestCase;

class ResetPasswordRequestTest extends FormRequestTestCase
{
    public function test_reset_password_requires_all_fields(): void
    {
        $request = new ResetPasswordRequest();
        $errors = $this->validate([], $request->rules());

        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('token', $errors);
        $this->assertArrayHasKey('password', $errors);
    }

    public function test_reset_password_requires_confirmed_password(): void
    {
        $request = new ResetPasswordRequest();
        $errors = $this->validate([
            'email' => 'user@example.com',
            'token' => 'token-value',
            'password' => 'secret123',
        ], $request->rules());

        $this->assertArrayHasKey('password', $errors);
    }

    public function test_reset_password_accepts_valid_payload(): void
    {
        $request = new ResetPasswordRequest();
        $errors = $this->validate([
            'email' => 'user@example.com',
            'token' => 'token-value',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
        ], $request->rules());

        $this->assertSame([], $errors);
    }
}
