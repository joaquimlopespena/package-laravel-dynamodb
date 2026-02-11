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
     *
     * @var array
     */
    protected array $context = [];

    /**
     * Custom error code.
     *
     * @var string|null
     */
    protected ?string $errorCode = null;

    /**
     * Suggestion for resolving the error.
     *
     * @var string|null
     */
    protected ?string $suggestion = null;

    /**
     * Create a new DynamoDbException instance.
     *
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     * @param array $context
     * @param string|null $errorCode
     * @param string|null $suggestion
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
     *
     * @return void
     */
    protected function logException(): void
    {
        if (!$this->shouldLog()) {
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
     *
     * @return bool
     */
    protected function shouldLog(): bool
    {
        return app()->bound('log') && config('database-dynamodb.log_exceptions', true);
    }

    /**
     * Remove sensitive data from context.
     *
     * @param array $context
     * @return array
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
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Get the custom error code.
     *
     * @return string|null
     */
    public function getErrorCode(): ?string
    {
        return $this->errorCode;
    }

    /**
     * Get the suggestion for resolving the error.
     *
     * @return string|null
     */
    public function getSuggestion(): ?string
    {
        return $this->suggestion;
    }

    /**
     * Get a detailed error message with context.
     *
     * @return string
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

        if (!empty($this->context)) {
            $message .= "\n\nContext: " . json_encode($this->context, JSON_PRETTY_PRINT);
        }

        return $message;
    }
}
