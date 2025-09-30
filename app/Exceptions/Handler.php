<?php

namespace App\Exceptions;

use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontReport = [];

    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        // Additional exception handling callbacks can be registered here if needed.
    }

    public function render($request, Throwable $exception): SymfonyResponse
    {
        if (! $request instanceof Request) {
            $request = Request::createFromBase($request);
        }

        if ($request->expectsJson()) {
            return $this->renderJson($request, $exception);
        }

        return parent::render($request, $exception);
    }

    protected function unauthenticated($request, AuthenticationException $exception): JsonResponse
    {
        if (! $request instanceof Request) {
            $request = Request::createFromBase($request);
        }

        $status = SymfonyResponse::HTTP_UNAUTHORIZED;

        return ApiResponse::error(
            'UNAUTHENTICATED',
            'Authentication is required to access this resource.',
            null,
            $status,
            $this->resolveTraceId($request, $status)
        );
    }

    private function renderJson(Request $request, Throwable $exception): JsonResponse
    {
        if ($exception instanceof ValidationException) {
            $status = $exception->status;

            if ($status === SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY && $this->isConflictValidation($exception)) {
                $status = SymfonyResponse::HTTP_CONFLICT;
            }

            return ApiResponse::error(
                $this->mapErrorCode($exception, $status),
                'Validation failed for the given request.',
                $exception->errors(),
                $status,
                $this->resolveTraceId($request, $status)
            );
        }

        $status = $this->determineStatusCode($exception);

        return ApiResponse::error(
            $this->mapErrorCode($exception, $status),
            $this->mapMessage($exception, $status),
            $this->extractDetails($exception),
            $status,
            $this->resolveTraceId($request, $status)
        );
    }

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

    private function determineStatusCode(Throwable $exception): int
    {
        return match (true) {
            $exception instanceof AuthenticationException => SymfonyResponse::HTTP_UNAUTHORIZED,
            $exception instanceof AuthorizationException => SymfonyResponse::HTTP_FORBIDDEN,
            $exception instanceof ModelNotFoundException => SymfonyResponse::HTTP_NOT_FOUND,
            $exception instanceof ValidationException => SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY,
            $exception instanceof ThrottleRequestsException => SymfonyResponse::HTTP_TOO_MANY_REQUESTS,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    private function mapErrorCode(Throwable $exception, int $status): string
    {
        return match (true) {
            $exception instanceof ValidationException && $status === SymfonyResponse::HTTP_CONFLICT => 'CONFLICT',
            $exception instanceof ValidationException => 'VALIDATION_ERROR',
            $exception instanceof AuthenticationException => 'UNAUTHENTICATED',
            $exception instanceof AuthorizationException => 'FORBIDDEN',
            $exception instanceof ModelNotFoundException => 'NOT_FOUND',
            $exception instanceof ThrottleRequestsException => 'RATE_LIMIT_EXCEEDED',
            default => $this->defaultErrorCode($status),
        };
    }

    private function mapMessage(Throwable $exception, int $status): string
    {
        return match ($status) {
            SymfonyResponse::HTTP_UNAUTHORIZED => 'Authentication is required to access this resource.',
            SymfonyResponse::HTTP_FORBIDDEN => 'You do not have permission to perform this action.',
            SymfonyResponse::HTTP_NOT_FOUND => 'The requested resource was not found.',
            SymfonyResponse::HTTP_CONFLICT => 'A resource conflict occurred.',
            SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY => 'Validation failed for the given request.',
            SymfonyResponse::HTTP_TOO_MANY_REQUESTS => 'Too many requests were made. Please try again later.',
            SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR => 'Unexpected server error.',
            SymfonyResponse::HTTP_SERVICE_UNAVAILABLE => 'The service is temporarily unavailable.',
            default => $this->messageFromException($exception, $status),
        };
    }

    private function messageFromException(Throwable $exception, int $status): string
    {
        $message = trim($exception->getMessage());

        if ($message !== '') {
            return $message;
        }

        return SymfonyResponse::$statusTexts[$status] ?? 'An unexpected error occurred.';
    }

    private function extractDetails(Throwable $exception): ?array
    {
        if ($exception instanceof ThrottleRequestsException) {
            $retryAfter = $exception->getHeaders()['Retry-After'] ?? null;

            return $retryAfter !== null ? ['retry_after' => $retryAfter] : null;
        }

        return null;
    }

    private function resolveTraceId(Request $request, int $status): ?string
    {
        $candidates = [
            $request->attributes->get('traceId'),
            $request->attributes->get('request_id'),
            $request->attributes->get('requestId'),
            $request->headers->get('X-Request-Id'),
            $request->headers->get('X-Request-ID'),
            $request->headers->get('X-Correlation-Id'),
        ];

        $traceId = Arr::first(array_filter($candidates, static fn ($value) => $value !== null && $value !== ''));

        if ($traceId === null && $status >= SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR) {
            $traceId = (string) Str::uuid();
            $request->attributes->set('traceId', $traceId);
        }

        return $traceId !== null ? (string) $traceId : null;
    }

    private function defaultErrorCode(int $status): string
    {
        return match ($status) {
            SymfonyResponse::HTTP_BAD_REQUEST => 'BAD_REQUEST',
            SymfonyResponse::HTTP_NOT_ACCEPTABLE => 'NOT_ACCEPTABLE',
            SymfonyResponse::HTTP_GONE => 'RESOURCE_GONE',
            SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR => 'INTERNAL_SERVER_ERROR',
            SymfonyResponse::HTTP_SERVICE_UNAVAILABLE => 'SERVICE_UNAVAILABLE',
            default => Str::upper(Str::snake(SymfonyResponse::$statusTexts[$status] ?? 'error')),
        };
    }
}
