<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for business-rule violations. These are expected domain failures,
 * not bugs: they carry an HTTP status and field-level context so both the web
 * UI and JSON clients can react precisely.
 */
abstract class ErpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
        /** @var array<string, string> */
        public readonly array $fieldErrors = [],
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => $this->fieldErrors,
            ], $this->status);
        }

        return back()
            ->withInput()
            ->withErrors($this->fieldErrors ?: ['erp' => $this->getMessage()])
            ->with('error', $this->getMessage());
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->fieldErrors;
    }
}
