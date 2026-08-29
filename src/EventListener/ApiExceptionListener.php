<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

#[AsEventListener]
final class ApiExceptionListener
{
    // key for errors that belong to no particular field
    public const GENERAL = '_';

    public function __invoke(ExceptionEvent $event): void
    {
        // checks if exception source is api
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();

        // checks if it's http exception, returns otherwise
        if (!$exception instanceof HttpExceptionInterface) {
            return;
        }

        $previous = $exception->getPrevious();

        // mapping denormalization and constraint fails
        $errors = $previous instanceof ValidationFailedException
            ? $this->groupViolationsByProperty($previous)
            : [self::GENERAL => [$exception->getMessage() ?: (Response::$statusTexts[$exception->getStatusCode()] ?? 'Error')]];

        $event->setResponse(new JsonResponse(['errors' => $errors], $exception->getStatusCode(), $exception->getHeaders()));
    }

    /**
     * @return array<string, list<string>>
     */
    private function groupViolationsByProperty(ValidationFailedException $exception): array
    {
        $errors = [];

        foreach ($exception->getViolations() as $violation) {
            $errors[$violation->getPropertyPath() ?: self::GENERAL][] = (string) $violation->getMessage();
        }

        return $errors;
    }
}
