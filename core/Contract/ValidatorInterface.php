<?php
declare(strict_types=1);

namespace Core\Contract;

interface ValidatorInterface
{
    /**
     * @param array<string, mixed>             $data
     * @param array<string, string|array>      $rules  поле => 'required|min:2|max:100'
     */
    public function validate(array $data, array $rules): ValidationResult;
}
