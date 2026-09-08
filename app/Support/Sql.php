<?php

namespace App\Support;

class Sql
{
    /**
     * SELECT expression for a kit's *effective* paternal cluster — which
     * of its two parent clusters ('p1' / 'p2') is the paternal side.
     *
     * Two sources, ours first. `paternalCluster` is Ancestry's, written by
     * load-dna.pl only when their cluster API actually returns something —
     * an owner who never labelled their sides in Ancestry leaves it empty
     * forever, and 24 of our eyes are in that state, which is why their
     * ParentSide pills read 'P1' / 'P2' instead of PATERNAL / MATERNAL.
     * `paternalClusterOverride` is app-owned (deploy/paternal-cluster-
     * override.sql) and wins outright, so it also fixes the case where the
     * owner did label their sides and got them the wrong way round.
     *
     * NULLIF because the column defaults to '' rather than NULL on rows the
     * loader has never touched — a bare COALESCE would happily return the
     * empty string and both consumers would fail the /^p[12]$/ test anyway,
     * but silently, one layer further out.
     *
     * Aliased back to `paternalCluster` on purpose: the two places that
     * resolve a cluster code into a side — ClusterPill.vue and
     * DnaSampleService::parentSideFilter() — take this value as their input
     * and neither needs to know an override exists. Anything that wants
     * Ancestry's raw answer (the /eyes editor) must select the real column
     * under its own alias.
     */
    public static function effectivePaternalCluster(string $alias, string $as = 'paternalCluster'): string
    {
        return "COALESCE(NULLIF({$alias}.paternalClusterOverride,''), NULLIF({$alias}.paternalCluster,'')) AS {$as}";
    }
}
