<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertPersonRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'fullName'     => ['required', 'string', 'max:100'],
            'birth'        => ['nullable', 'string', 'regex:/^\d{4}(\s*-\s*\d{4})?$/'],
            'death'        => ['nullable', 'integer', 'between:1500,2100'],
            'gender'       => ['nullable', 'in:M,F,U'],
            'ancestry_url' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'birth.regex' => 'Birth must be a 4-digit year, optionally a range like 1880-1885.',
        ];
    }

    /**
     * Parse the `ancestry_url` field into a (atreeid, ancestryid) pair.
     * Accepts three formats:
     *   - https://.../family-tree/tree/{tree}/...?cfpid={person}
     *   - https://.../family-tree/person/tree/{tree}/person/{person}/...
     *   - {person}:1030:{tree}   (raw ID triple)
     *
     * @return array{atreeid:int, ancestryid:int}|null  null when blank
     * @throws \InvalidArgumentException when non-blank but doesn't match
     */
    public function ancestryLink(): ?array
    {
        $raw = trim((string) ($this->validated()['ancestry_url'] ?? ''));
        if ($raw === '') {
            return null;
        }
        if (preg_match('#/family-tree/tree/(\d+).*\?cfpid=(-?\d+)#', $raw, $m)) {
            return ['atreeid' => (int) $m[1], 'ancestryid' => (int) $m[2]];
        }
        if (preg_match('#/family-tree/person/tree/(\d+)/person/(-?\d+)#', $raw, $m)) {
            return ['atreeid' => (int) $m[1], 'ancestryid' => (int) $m[2]];
        }
        if (preg_match('/^(-?\d+):1030:(\d+)$/', $raw, $m)) {
            return ['atreeid' => (int) $m[2], 'ancestryid' => (int) $m[1]];
        }
        throw new \InvalidArgumentException(
            'Ancestry URL must be a Family Tree person link or a {person}:1030:{tree} triple.',
        );
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
