<?php

namespace Tests\Unit\Requests\Auth;

use App\Http\Requests\Auth\LoginRequest;
use Tests\Unit\Requests\FormRequestTestCase;

class LoginRequestTest extends FormRequestTestCase
{
    public function test_login_request_requires_email_and_password(): void
    {
        $request = new LoginRequest();
        $errors = $this->validate([], $request->rules());

        $this->assertArrayHasKey('email', $errors);
        $this->assertArrayHasKey('password', $errors);
    }

    public function test_login_request_requires_valid_email(): void
    {
        $request = new LoginRequest();
        $errors = $this->validate([
            'email' => 'invalid-email',
            'password' => 'secret',
        ], $request->rules());

        $this->assertArrayHasKey('email', $errors);
    }

    public function test_login_request_accepts_valid_payload(): void
    {
        $request = new LoginRequest();
        $errors = $this->validate([
            'email' => 'user@example.com',
            'password' => 'secret',
        ], $request->rules());

        $this->assertSame([], $errors);
    }
}
