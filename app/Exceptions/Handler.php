<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Custom exception handler for the API.
 */
class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->renderable(function (ValidationException $exception, Request $request) {
            if (! $request->expectsJson()) {
                return null;
            }

            $status = $exception->status;

            if ($status === 422 && $this->isConflictValidation($exception)) {
                $status = 409;
            }

            return ApiResponse::error(
                'VALIDATION_ERROR',
                __('validation.generic_error'),
                $exception->errors(),
                $status
            );
        });
    }

    /**
     * Determine if the validation failure represents a conflict error.
     */
    private function isConflictValidation(ValidationException $exception): bool
    {
        $failed = $exception->validator?->failed();

        if (! is_array($failed)) {
            return false;
        }

        foreach ($failed as $rules) {
            if (array_key_exists('Unique', $rules)) {
                return true;
            }
        }

        return false;
    }
}
