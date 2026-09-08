<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

// priority -64 makes it run after both Symfony logs the exception and after profiler captures it
// because setting response here stops the event;
// runs before -128 listener that would render HTML
#[AsEventListener(priority: -64)]
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

        // anything that's not HTTP exception is not a rejected request but a bug
        $status = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : Response::HTTP_INTERNAL_SERVER_ERROR;
        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        $previous = $exception->getPrevious();

        // mapping denormalization and constraint fails
        $errors = $previous instanceof ValidationFailedException
            ? $this->groupViolationsByProperty($previous)
            : [self::GENERAL => [Response::$statusTexts[$status] ?? 'Error']];

        $event->setResponse(new JsonResponse(['errors' => $errors], $status, $headers));
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
