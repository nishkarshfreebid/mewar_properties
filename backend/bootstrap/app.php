<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*')
                ? null
                : route('login')
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) =>
                $request->is('api/*') || $request->expectsJson()
        );

        // Validation errors.
        $exceptions->render(function (
            ValidationException $e,
            Request $request
        ) {
            if (!$request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->status);
        });

        // Authentication errors.
        $exceptions->render(function (
            AuthenticationException $e,
            Request $request
        ) {
            if (!$request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'data' => null,
            ], 401);
        });

        // Authorization errors.
        $exceptions->render(function (
            AuthorizationException $e,
            Request $request
        ) {
            if (!$request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to perform this action.',
                'data' => null,
            ], 403);
        });

        // HTTP exceptions.
        $exceptions->render(function (
            HttpExceptionInterface $e,
            Request $request
        ) {
            if (!$request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();

            $message = match ($status) {
                403 => 'You are not authorized to perform this action.',
                404 => 'The requested resource was not found.',
                405 => 'This HTTP method is not allowed.',
                419 => 'Your session has expired.',
                429 => 'Too many requests. Please try again later.',
                default => $status >= 500
                    ? 'Something went wrong. Please try again later.'
                    : ($e->getMessage() ?: 'Request could not be processed.'),
            };

            return response()->json([
                'success' => false,
                'message' => $message,
                'data' => null,
            ], $status, $e->getHeaders());
        });

        // Unexpected API errors.
        $exceptions->render(function (
            Throwable $e,
            Request $request
        ) {
            if (!$request->is('api/*')) {
                return null;
            }

            return response()->json([
                'success' => false,
                'message' => 'Something went wrong. Please try again later.',
                'data' => null,
            ], 500);
        });

    })
    ->create();
