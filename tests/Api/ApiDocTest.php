<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Tests\ApiTestCase;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ApiDocTest extends ApiTestCase
{
    private const RESPONSE_REF = '#/components/responses/';

    /**
     * Fetches generated OpenApi spec.
     *
     * @return array<mixed>
     */
    private function spec(): array
    {
        // with token so only testDocsArePublic depends on the docs being public
        $this->request('GET', '/api/doc.json', token: $this->tokenForNewUser('docs@domain.pl'));
        self::assertResponseIsSuccessful();

        return $this->responseBody();
    }

    /**
     * Returns endpoints as array, based on $spec argument.
     *
     * @param array<mixed> $spec
     *
     * @return array<string, array<mixed>> operations keyed "METHOD /path"
     */
    private function operations(array $spec): array
    {
        $paths = $spec['paths'] ?? null;
        self::assertIsArray($paths);
        $operations = [];

        foreach ($paths as $path => $methods) {
            foreach (\is_array($methods) ? $methods : [] as $method => $operation) {
                if (\is_array($operation)) {
                    $operations[strtoupper((string) $method).' '.$path] = $operation;
                }
            }
        }

        return $operations;
    }

    /**
     * Returns one section of the OpenAPI spec's components, e.g. 'schemas' or 'responses', keyed by name.
     *
     * @param array<mixed> $spec
     *
     * @return array<mixed>
     */
    private function components(array $spec, string $kind): array
    {
        $components = $spec['components'][$kind] ?? null;
        self::assertIsArray($components);

        return $components;
    }

    public function testDocsArePublic(): void
    {
        $this->request('GET', '/api/doc.json');
        self::assertResponseIsSuccessful();
        $spec = $this->responseBody();
        self::assertIsString($spec['openapi']);
        self::assertStringStartsWith('3.', $spec['openapi']);

        $this->request('GET', '/api/doc');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/html; charset=UTF-8');
    }

    public function testEveryApiRouteIsDocumented(): void
    {
        $expected = [];

        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/') || str_starts_with($name, 'api_doc')) {
                continue;
            }

            foreach ($route->getMethods() as $method) {
                $expected[] = $method.' '.$route->getPath();
            }
        }

        self::assertNotEmpty($expected);
        self::assertEqualsCanonicalizing($expected, array_keys($this->operations($this->spec())));
    }

    public function testOnlyRegisterAndLoginArePublic(): void
    {
        $spec = $this->spec();
        self::assertSame([['Bearer' => []]], $spec['security']);

        $public = array_filter(
            $this->operations($spec),
            static fn (array $operation): bool => [] === ($operation['security'] ?? null),
        );

        self::assertEqualsCanonicalizing(['POST /api/register', 'POST /api/login'], array_keys($public));
    }

    public function testEveryOperationDeclaresItsResponses(): void
    {
        $spec = $this->spec();
        $sharedResponses = $this->components($spec, 'responses');
        $problems = [];

        foreach ($this->operations($spec) as $name => $operation) {
            $responses = \is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
            // keep status codes only - json_decode turns "200" keys into ints, placeholder 'default' stays string
            $statuses = array_filter(array_keys($responses), \is_int(...));

            if (\array_key_exists('default', $responses)) {
                $problems[] = $name.' declares no response, it got a placeholder from OA';
            }

            if ([] === array_filter($statuses, static fn (int $status): bool => $status >= 200 && $status < 300)) {
                $problems[] = $name.' declares no success response';
            }

            if ([] !== ($operation['security'] ?? null) && !\in_array(401, $statuses, true)) {
                $problems[] = $name.' needs a token but does not declare 401';
            }

            // every error points at a shared response, so every error has the one shape
            foreach ($statuses as $status) {
                $ref = \is_array($responses[$status]) ? $responses[$status]['$ref'] ?? null : null;
                $shared = \is_string($ref) && str_starts_with($ref, self::RESPONSE_REF)
                    && \array_key_exists(substr($ref, \strlen(self::RESPONSE_REF)), $sharedResponses);

                if ($status >= 400 && !$shared) {
                    $problems[] = $name.' '.$status.' is not a shared error response';
                }
            }
        }

        self::assertSame([], $problems);
    }

    public function testMoneyIsDocumentedAsString(): void
    {
        $schemas = $this->components($this->spec(), 'schemas');

        $expected = [
            'ProductRequest.price' => 'string',
            'Product.price' => 'string',
            'ProductListItem.price' => 'string',
            'OrderSummary.totalPrice' => 'string',
            'Order.totalPrice' => 'string',
            'OrderItem.unitPrice' => 'string',
        ];

        $actual = [];

        foreach (array_keys($expected) as $field) {
            [$schema, $property] = explode('.', $field);
            $actual[$field] = $schemas[$schema]['properties'][$property]['type'] ?? null;
        }

        self::assertSame($expected, $actual);

        // Symfony strips ^ and $ from the pattern it generates for HTML, and the docs reuse that pattern;
        // unanchored, it could match anywhere in a string, so I check if they survived (htmlPattern is set by hand)
        $pattern = $schemas['ProductRequest']['properties']['price']['pattern'] ?? null;
        self::assertIsString($pattern);
        self::assertMatchesRegularExpression('/^\^.*\$$/', $pattern);
    }

    // group missing from models.names makes nelmio fall back to Product2, Order2...
    public function testEverySchemaHasItsAlias(): void
    {
        $numbered = array_filter(
            array_keys($this->components($this->spec(), 'schemas')),
            static fn (int|string $name): bool => 1 === preg_match('/\d$/', (string) $name),
        );

        self::assertSame([], array_values($numbered));
    }

    // nelmio documents a property with a default as optional
    // so NotBlank/NotNull fields must be listed in #[OA\Schema(required: [...])]
    public function testRequestFieldsRequiredByConstraintsAreDocumentedAsRequired(): void
    {
        $validator = static::getContainer()->get(ValidatorInterface::class);
        $checked = 0;
        $undocumented = [];

        foreach ($this->components($this->spec(), 'schemas') as $name => $schema) {
            $class = 'App\\Dto\\'.$name;

            if (!class_exists($class)) {
                continue;
            }

            $required = \is_array($schema) ? $schema['required'] ?? [] : [];
            $metadata = $validator->getMetadataFor($class);
            self::assertInstanceOf(ClassMetadataInterface::class, $metadata);

            foreach ($metadata->getConstrainedProperties() as $property) {
                foreach ($metadata->getPropertyMetadata($property) as $propertyMetadata) {
                    foreach ($propertyMetadata->getConstraints() as $constraint) {
                        $requiredByConstraint = $constraint instanceof NotBlank || $constraint instanceof NotNull;

                        if ($requiredByConstraint && !\in_array($property, (array) $required, true)) {
                            $undocumented[] = $name.'.'.$property;
                        }
                    }
                }
            }

            ++$checked;
        }

        self::assertGreaterThan(0, $checked);
        self::assertSame([], $undocumented);
    }
}
