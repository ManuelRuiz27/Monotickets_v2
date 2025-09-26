<?php

namespace Tests\Unit\Requests\Auth;

use App\Http\Requests\Auth\RefreshTokenRequest;
use Tests\Unit\Requests\FormRequestTestCase;

class RefreshTokenRequestTest extends FormRequestTestCase
{
    public function test_refresh_request_requires_token(): void
    {
        $request = new RefreshTokenRequest();
        $errors = $this->validate([], $request->rules());

        $this->assertArrayHasKey('refresh_token', $errors);
    }

    public function test_refresh_request_accepts_valid_token(): void
    {
        $request = new RefreshTokenRequest();
        $errors = $this->validate([
            'refresh_token' => 'token-value',
        ], $request->rules());

        $this->assertSame([], $errors);
    }
}
