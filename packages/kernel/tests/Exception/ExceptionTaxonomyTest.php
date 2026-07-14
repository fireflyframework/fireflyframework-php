<?php

declare(strict_types=1);

use Firefly\Kernel\Error\ErrorCategory;
use Firefly\Kernel\Error\ErrorSeverity;
use Firefly\Kernel\Error\FieldError;
use Firefly\Kernel\Exception\Business\BusinessException;
use Firefly\Kernel\Exception\Business\ConflictException;
use Firefly\Kernel\Exception\Business\PreconditionFailedException;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Kernel\Exception\External\ExternalServiceException;
use Firefly\Kernel\Exception\External\IntegrationException;
use Firefly\Kernel\Exception\FireflyException;
use Firefly\Kernel\Exception\Framework\BeanCreationException;
use Firefly\Kernel\Exception\Framework\BeanNotFoundException;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Kernel\Exception\Framework\PluginException;
use Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException;
use Firefly\Kernel\Exception\Infrastructure\DataAccessException;
use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;
use Firefly\Kernel\Exception\Infrastructure\RateLimitExceededException;
use Firefly\Kernel\Exception\Infrastructure\ServiceUnavailableException;
use Firefly\Kernel\Exception\Infrastructure\TimeoutException;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Kernel\Exception\Security\InvalidTokenException;
use Firefly\Kernel\Exception\Security\SecurityException;
use Firefly\Kernel\Exception\Security\TokenExpiredException;

dataset('taxonomy', [
    // class, expected code, expected status, expected category, expected severity
    [BusinessException::class, 'BUSINESS_ERROR', 422, ErrorCategory::Business, ErrorSeverity::Warning],
    [ValidationException::class, 'VALIDATION_ERROR', 422, ErrorCategory::Validation, ErrorSeverity::Warning],
    [ResourceNotFoundException::class, 'RESOURCE_NOT_FOUND', 404, ErrorCategory::Business, ErrorSeverity::Warning],
    [ConflictException::class, 'CONFLICT', 409, ErrorCategory::Business, ErrorSeverity::Warning],
    [PreconditionFailedException::class, 'PRECONDITION_FAILED', 412, ErrorCategory::Business, ErrorSeverity::Warning],
    [SecurityException::class, 'SECURITY_ERROR', 403, ErrorCategory::Security, ErrorSeverity::Warning],
    [AuthenticationException::class, 'AUTHENTICATION_FAILED', 401, ErrorCategory::Security, ErrorSeverity::Warning],
    [AuthorizationException::class, 'ACCESS_DENIED', 403, ErrorCategory::Security, ErrorSeverity::Warning],
    [InvalidTokenException::class, 'INVALID_TOKEN', 401, ErrorCategory::Security, ErrorSeverity::Warning],
    [TokenExpiredException::class, 'TOKEN_EXPIRED', 401, ErrorCategory::Security, ErrorSeverity::Warning],
    [InfrastructureException::class, 'INFRASTRUCTURE_ERROR', 500, ErrorCategory::Infrastructure, ErrorSeverity::Error],
    [DataAccessException::class, 'DATA_ACCESS_ERROR', 500, ErrorCategory::Infrastructure, ErrorSeverity::Error],
    [ServiceUnavailableException::class, 'SERVICE_UNAVAILABLE', 503, ErrorCategory::Infrastructure, ErrorSeverity::Error],
    [TimeoutException::class, 'TIMEOUT', 504, ErrorCategory::Infrastructure, ErrorSeverity::Error],
    [CircuitBreakerOpenException::class, 'CIRCUIT_BREAKER_OPEN', 503, ErrorCategory::Infrastructure, ErrorSeverity::Error],
    [RateLimitExceededException::class, 'RATE_LIMIT_EXCEEDED', 429, ErrorCategory::Infrastructure, ErrorSeverity::Warning],
    [ExternalServiceException::class, 'EXTERNAL_SERVICE_ERROR', 502, ErrorCategory::External, ErrorSeverity::Error],
    [IntegrationException::class, 'INTEGRATION_ERROR', 502, ErrorCategory::External, ErrorSeverity::Error],
    [ConfigurationException::class, 'CONFIGURATION_ERROR', 500, ErrorCategory::Framework, ErrorSeverity::Critical],
    [BeanCreationException::class, 'BEAN_CREATION_ERROR', 500, ErrorCategory::Framework, ErrorSeverity::Critical],
    [BeanNotFoundException::class, 'BEAN_NOT_FOUND', 500, ErrorCategory::Framework, ErrorSeverity::Critical],
    [PluginException::class, 'PLUGIN_ERROR', 500, ErrorCategory::Plugin, ErrorSeverity::Error],
]);

it('fixes code/status/category/severity per typed exception', function (
    string $class,
    string $code,
    int $status,
    ErrorCategory $category,
    ErrorSeverity $severity,
) {
    $e = new $class('something happened');
    assert($e instanceof FireflyException);

    expect($e)->toBeInstanceOf(FireflyException::class)
        ->and($e->errorCode())->toBe($code)
        ->and($e->httpStatus())->toBe($status)
        ->and($e->category())->toBe($category)
        ->and($e->severity())->toBe($severity);
})->with('taxonomy');

it('lets ValidationException carry field errors', function () {
    $fields = [new FieldError('email', 'is required')];
    $e = new ValidationException('validation failed', $fields);

    expect($e)->toBeInstanceOf(BusinessException::class)
        ->and($e->fieldErrors())->toBe($fields);
});
