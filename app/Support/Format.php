<?php

namespace App\Support;

class Format
{
    public static function createdDate(?int $ms): string
    {
        if (!$ms) {
            return '';
        }
        try {
            return date('Y-m-d', (int) ($ms / 1000));
        } catch (\Throwable) {
            return '';
        }
    }

    public static function years(?int $minBirth, ?int $maxBirth, ?int $death): string
    {
        if (!$minBirth && !$maxBirth && !$death) {
            return '';
        }
        if ($minBirth && $maxBirth && $minBirth !== $maxBirth) {
            $birth = $minBirth . '/' . $maxBirth;
        } else {
            $birth = (string) ($minBirth ?: $maxBirth ?: '?');
        }
        $deathStr = $death ? (string) $death : '?';
        return "({$birth}-{$deathStr})";
    }

    public static function displayLabel(?string $personName, ?string $dnaName): string
    {
        if ($personName) {
            return $personName;
        }
        if ($dnaName) {
            return "[{$dnaName}]";
        }
        return '(UNKNOWN)';
    }

    /**
     * Returns the gender we should display for a sample, with the linked
     * people row winning over dna_samples when both are present.
     * Output is normalised to 'M', 'F', or '' (unknown / non-binary).
     */
    public static function effectiveGender(?string $personGender, ?string $sampleGender): string
    {
        foreach ([$personGender, $sampleGender] as $g) {
            if ($g === null) {
                continue;
            }
            $g = strtoupper(trim($g));
            if ($g === 'M' || $g === 'F') {
                return $g;
            }
        }
        return '';
    }
}
