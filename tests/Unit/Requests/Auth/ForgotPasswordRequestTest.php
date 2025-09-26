<?php

namespace Tests\Unit\Requests\Auth;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use Tests\Unit\Requests\FormRequestTestCase;

class ForgotPasswordRequestTest extends FormRequestTestCase
{
    public function test_forgot_password_requires_email(): void
    {
        $request = new ForgotPasswordRequest();
        $errors = $this->validate([], $request->rules());

        $this->assertArrayHasKey('email', $errors);
    }

    public function test_forgot_password_requires_valid_email(): void
    {
        $request = new ForgotPasswordRequest();
        $errors = $this->validate([
            'email' => 'invalid-email',
        ], $request->rules());

        $this->assertArrayHasKey('email', $errors);
    }

    public function test_forgot_password_accepts_valid_email(): void
    {
        $request = new ForgotPasswordRequest();
        $errors = $this->validate([
            'email' => 'user@example.com',
        ], $request->rules());

        $this->assertSame([], $errors);
    }
}
