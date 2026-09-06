<?php

declare(strict_types=1);

namespace App\Validator;

use App\Repository\ProductRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ProductExistsValidator extends ConstraintValidator
{
    public function __construct(private readonly ProductRepository $products)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ProductExists) {
            throw new UnexpectedTypeException($constraint, ProductExists::class);
        }

        // missing product id problem belongs to NotNull constraint
        if (null === $value) {
            return;
        }

        if (null === $this->products->find($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ id }}', (string) $value)
                ->addViolation();
        }
    }
}
