<?php

declare(strict_types=1);

namespace App\Validator;

use App\Repository\CategoryRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class CategoryExistsValidator extends ConstraintValidator
{
    public function __construct(private readonly CategoryRepository $categories)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof CategoryExists) {
            throw new UnexpectedTypeException($constraint, CategoryExists::class);
        }

        // null $value is acceptable in optional parameter
        if (null === $value) {
            return;
        }

        if (null === $this->categories->find($value)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ id }}', (string) $value)
                ->addViolation();
        }
    }
}
