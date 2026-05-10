<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertPersonRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fullName' => ['required', 'string', 'max:100'],
            'birth'    => ['nullable', 'string', 'regex:/^\d{4}(\s*-\s*\d{4})?$/'],
            'death'    => ['nullable', 'integer', 'between:1500,2100'],
            'gender'   => ['nullable', 'in:M,F,U'],
        ];
    }

    public function messages(): array
    {
        return [
            'birth.regex' => 'Birth must be a 4-digit year, optionally a range like 1880-1885.',
        ];
    }

    /**
     * Parse the validated `birth` field into integer minBirth/maxBirth.
     *
     * @return array{minBirth: ?int, maxBirth: ?int}
     */
    public function birthYears(): array
    {
        $birth = trim((string) $this->validated()['birth'] ?? '');
        if ($birth === '') {
            return ['minBirth' => null, 'maxBirth' => null];
        }
        if (str_contains($birth, '-')) {
            [$min, $max] = array_map('trim', explode('-', $birth, 2));
            return ['minBirth' => (int) $min, 'maxBirth' => (int) $max];
        }
        $y = (int) $birth;
        return ['minBirth' => $y, 'maxBirth' => $y];
    }
}
