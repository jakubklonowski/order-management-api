<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\ApiExceptionListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiExceptionListenerTest extends TestCase
{
    private const LEAKY_MESSAGE = 'secret internal detail';

    // returns ExceptionEvent as substitute for an api route returning http status 500
    private function dispatch(string $path, \Throwable $exception): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );

        (new ApiExceptionListener())($event);

        return $event;
    }

    /**
     * @return array<mixed>
     */
    private function getResponseBodyOfEvent(ExceptionEvent $event): array
    {
        return json_decode($event->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testUnexpectedThrowableBecomesJsonServerError(): void
    {
        $event = $this->dispatch('/api/orders', new \RuntimeException(self::LEAKY_MESSAGE));

        self::assertNotNull($event->getResponse());
        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $event->getResponse()->getStatusCode());
        self::assertSame(['errors' => ['_' => ['Internal Server Error']]], $this->getResponseBodyOfEvent($event));
    }

    public function testServerErrorDoesNotLeakTheExceptionMessage(): void
    {
        $event = $this->dispatch('/api/orders', new \RuntimeException(self::LEAKY_MESSAGE));

        self::assertStringNotContainsString(self::LEAKY_MESSAGE, $event->getResponse()->getContent());
    }

    public function testHttpExceptionKeepsItsOwnStatusAndShape(): void
    {
        $event = $this->dispatch('/api/orders/1', new NotFoundHttpException(self::LEAKY_MESSAGE));

        self::assertSame(Response::HTTP_NOT_FOUND, $event->getResponse()->getStatusCode());
        self::assertSame(['errors' => ['_' => ['Not Found']]], $this->getResponseBodyOfEvent($event));
    }

    public function testNonApiPathIsLeftToSymfony(): void
    {
        $event = $this->dispatch('/health', new \RuntimeException(self::LEAKY_MESSAGE));

        self::assertNull($event->getResponse());
    }
}
