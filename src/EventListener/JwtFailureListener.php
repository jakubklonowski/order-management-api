<?php

declare(strict_types=1);

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Lexik\Bundle\JWTAuthenticationBundle\Response\JWTAuthenticationFailureResponse;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rewrites LexikJWTAuthenticationBundle's authentication failures into the API's error shape.
 *
 * The bundle answers {"code": 401, "message": "..."} directly from the
 * firewall, so the response never becomes an exception and ApiExceptionListener
 * never sees it. Without this, an api consumer needs a second response parser
 * for exactly one status code.
 */
#[AsEventListener(event: Events::AUTHENTICATION_FAILURE)]
#[AsEventListener(event: Events::JWT_NOT_FOUND)]
#[AsEventListener(event: Events::JWT_INVALID)]
#[AsEventListener(event: Events::JWT_EXPIRED)]
final class JwtFailureListener
{
    public function __invoke(AuthenticationFailureEvent $event): void
    {
        $original = $event->getResponse();
        $status = $original?->getStatusCode() ?? Response::HTTP_UNAUTHORIZED;

        // bundle's wording stays as it doesn't leak internals while being
        // informative (missing, invalid, expired tokens)
        $message = $original instanceof JWTAuthenticationFailureResponse
            ? $original->getMessage()
            : Response::$statusTexts[$status] ?? 'Error';

        // fresh response will be built and bundle's headers need to come along
        $headers = $original?->headers->all() ?? [];

        $event->setResponse(new JsonResponse(
            ['errors' => [ApiExceptionListener::GENERAL => [$message]]],
            $status,
            $headers,
        ));
    }
}
