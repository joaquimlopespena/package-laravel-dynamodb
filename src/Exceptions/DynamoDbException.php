<?php

namespace Joaquim\LaravelDynamoDb\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Base exception class for all DynamoDB-related exceptions.
 *
 * Provides automatic logging and detailed context for debugging.
 */
class DynamoDbException extends Exception
{
    /**
     * Additional context data for the exception.
     */
    protected array $context = [];

    /**
     * Custom error code.
     */
    protected ?string $errorCode = null;

    /**
     * Suggestion for resolving the error.
     */
    protected ?string $suggestion = null;

    /**
     * Create a new DynamoDbException instance.
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = [],
        ?string $errorCode = null,
        ?string $suggestion = null
    ) {
        parent::__construct($message, $code, $previous);

        $this->context = $context;
        $this->errorCode = $errorCode;
        $this->suggestion = $suggestion;

        // Automatic logging
        $this->logException();
    }

    /**
     * Log the exception with context.
     */
    protected function logException(): void
    {
        if (! $this->shouldLog()) {
            return;
        }

        try {
            $logContext = array_merge($this->context, [
                'exception' => get_class($this),
                'error_code' => $this->errorCode,
                'file' => $this->getFile(),
                'line' => $this->getLine(),
            ]);

            if ($this->suggestion) {
                $logContext['suggestion'] = $this->suggestion;
            }

            // Remove sensitive data if configured
            if (config('database-dynamodb.hide_sensitive_logs', false)) {
                $logContext = $this->removeSensitiveData($logContext);
            }

            Log::error($this->getMessage(), $logContext);
        } catch (\Throwable $e) {
            // Avoid throwing exceptions during logging
        }
    }

    /**
     * Determine if the exception should be logged.
     */
    protected function shouldLog(): bool
    {
        return app()->bound('log') && config('database-dynamodb.log_exceptions', true);
    }

    /**
     * Remove sensitive data from context.
     */
    protected function removeSensitiveData(array $context): array
    {
        $sensitiveKeys = ['password', 'secret', 'key', 'token', 'credentials'];

        foreach ($sensitiveKeys as $key) {
            if (isset($context[$key])) {
                $context[$key] = '***HIDDEN***';
            }
        }

        return $context;
    }

    /**
     * Get the exception context.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get the custom error code.
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Get the suggestion for resolving the error.
     */
    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    /**
     * Get a detailed error message with context.
     */
    public function getDetailedMessage(): string
    {
        $message = $this->getMessage();

        if ($this->errorCode) {
            $message .= " [Error Code: {$this->errorCode}]";
        }

        if ($this->suggestion) {
            $message .= "\n\nSuggestion: {$this->suggestion}";
        }

        if (! empty($this->context)) {
            $message .= "\n\nContext: ".json_encode($this->context, JSON_PRETTY_PRINT);
        }

        return $message;
    }
}
