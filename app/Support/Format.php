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

    public static function clusterClass(?string $code): string
    {
        if (!$code) {
            return '';
        }
        $total = 0;
        for ($i = 0, $n = strlen($code); $i < $n; $i++) {
            $total += ord($code[$i]);
        }
        return 'cluster-' . (($total % 10) + 1);
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
}
