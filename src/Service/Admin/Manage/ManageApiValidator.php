<?php

declare(strict_types=1);

namespace App\Service\Admin\Manage;

use App\Exception\Admin\ManageApiException;
use App\Service\Admin\ImportApiInputValidator;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Runs the entity constraints (the very ones the back-office enforces) and turns violations into a 422.
 */
final readonly class ManageApiValidator
{
    public function __construct(
        private ValidatorInterface $validator,
        private ImportApiInputValidator $fieldErrors,
    ) {
    }

    public function assertInput(ApiInput $input): void
    {
        if ([] !== $input->errors()) {
            throw ManageApiException::invalid($input->errors());
        }
    }

    public function assertEntity(object $entity): void
    {
        $errors = $this->fieldErrors->toFieldErrors($this->validator->validate($entity));

        if ([] !== $errors) {
            throw ManageApiException::invalid($errors);
        }
    }
}
