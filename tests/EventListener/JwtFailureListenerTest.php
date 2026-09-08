<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\EventListener\JwtFailureListener;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Response\JWTAuthenticationFailureResponse;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class JwtFailureListenerTest extends TestCase
{
    private function dispatch(?Response $original): AuthenticationFailureEvent
    {
        $event = new AuthenticationFailureEvent(new AuthenticationException(), $original);

        (new JwtFailureListener())($event);

        return $event;
    }

    /**
     * @return array<mixed>
     */
    private function getResponseBodyOfEvent(AuthenticationFailureEvent $event): array
    {
        return json_decode($event->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    public function testFailureIsRewrittenIntoTheApiErrorShape(): void
    {
        $event = $this->dispatch(new JWTAuthenticationFailureResponse('Expired JWT Token'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $event->getResponse()->getStatusCode());
        self::assertSame(['errors' => ['_' => ['Expired JWT Token']]], $this->getResponseBodyOfEvent($event));
    }

    public function testAuthenticateHeaderSurvivesTheRewrite(): void
    {
        $event = $this->dispatch(new JWTAuthenticationFailureResponse());

        // a 401 without it is not a valid challenge
        self::assertSame('Bearer', $event->getResponse()->headers->get('WWW-Authenticate'));
    }
}
