<?php

namespace App\Services;

use App\Support\Format;
use Illuminate\Support\Facades\DB;

class FamilyTreeService
{
    /**
     * How much of the neighbourhood the page is handed up front. The tree
     * opens on the focus and one generation of parents, so this is a
     * read-ahead buffer rather than a limit — anything past it is fetched by
     * expand() when a card's arrow is clicked. See resources/js/Pages/People/Tree.vue.
     */
    public const ANCESTOR_DEPTH = 6;

    public const DESCENDANT_DEPTH = 4;

    /** Guard on expand(), so a request can't ask for a runaway recursive CTE. */
    public const MAX_EXPAND_LEVELS = 12;

    /**
     * Build an Ancestry-style "Family View" neighbourhood around a focus:
     *   - focus
     *   - focus's siblings & half-siblings (other children of focus's parents)
     *   - focus's lineage up to ANCESTOR_DEPTH generations
     *   - focus's descendants down to DESCENDANT_DEPTH generations
     *   - spouses of anyone in scope (= co-parents of in-scope children)
     *
     * Output is the f3 (donatso/family-chart) shape: each person has
     * rels.parents / rels.children / rels.spouses pointing at other
     * person ids (string-cast for f3's string-id requirement).
     */
    public function build(int $focusId): ?array
    {
        $focus = DB::selectOne('
            SELECT id, fullName, gender, minBirth, maxBirth, death,
                   dnaSampleId, father, mother
            FROM people WHERE id = ?
        ', [$focusId]);
        if (! $focus) {
            return null;
        }

        $ids = [];
        $ids[$focusId] = true;

        foreach ($this->siblingIds($focusId, (int) ($focus->father ?? 0), (int) ($focus->mother ?? 0)) as $id) {
            $ids[$id] = true;
        }
        foreach ($this->ancestorIds($focusId, self::ANCESTOR_DEPTH) as $id) {
            $ids[$id] = true;
        }
        foreach ($this->descendantIds($focusId, self::DESCENDANT_DEPTH) as $id) {
            $ids[$id] = true;
        }
        // spouses = co-parents of anyone in scope
        foreach ($this->coParentIds(array_keys($ids)) as $id) {
            $ids[$id] = true;
        }

        return [
            'focus_id' => (string) $focusId,
            'focus' => $this->focusPayload($focus),
            'people' => $this->payloadFor(array_keys($ids)),
            'ancestor_depth' => self::ANCESTOR_DEPTH,
            'descendant_depth' => self::DESCENDANT_DEPTH,
        ];
    }

    /**
     * The people one step beyond what the page already holds, in the given
     * direction from $personId. The client merges these into its own copy of
     * the data; it knows what it has, so we don't try to work that out here
     * and simply return the whole step, already-held people included.
     *
     * Spouses of the new people come along, because f3 dereferences a spouse
     * id without checking it resolves — a rels entry naming someone the client
     * doesn't hold is a crash, not a gap.
     *
     * @return array{people: array<int, array>}
     */
    public function expand(int $personId, string $rel, int $levels = 1): array
    {
        $levels = max(1, min(self::MAX_EXPAND_LEVELS, $levels));

        $newIds = match ($rel) {
            'parents' => $this->ancestorIds($personId, $levels),
            'children' => $this->descendantIds($personId, $levels),
            'siblings' => $this->siblingIdsOf($personId),
            default => [],
        };
        if (! $newIds) {
            return ['people' => []];
        }

        return ['people' => $this->payloadFor([...$newIds, ...$this->coParentIds($newIds)])];
    }

    /**
     * f3 datums for exactly these people, each carrying its *complete*
     * relations — including relatives outside the set. That is deliberate:
     * the counts on the expand arrows come from these lists, so a card can
     * say "4 children" before any of the four have been fetched. The client
     * filters them down to what it holds before handing them to f3.
     *
     * @param  int[]  $ids
     */
    private function payloadFor(array $ids): array
    {
        if (! $ids) {
            return [];
        }
        $rows = $this->fetchPeople($ids);
        $ids = array_map(fn ($r) => (int) $r->id, $rows);
        $descending = $this->childrenAndSpouses($ids);

        $people = [];
        foreach ($rows as $row) {
            $id = (int) $row->id;
            $parents = [];
            if ($row->father) {
                $parents[] = (int) $row->father;
            }
            if ($row->mother) {
                $parents[] = (int) $row->mother;
            }
            $people[] = $this->datumPayload($id, $row, [
                'parents' => $parents,
                'children' => $descending[$id]['children'] ?? [],
                'spouses' => $descending[$id]['spouses'] ?? [],
            ]);
        }

        return $people;
    }

    /**
     * Everyone's children, and the co-parent of each of those children — one
     * pass over the rows that name any of $ids as a parent. Both lists are
     * unrestricted: a child or spouse outside $ids is still reported.
     *
     * @param  int[]  $ids
     * @return array<int, array{children: int[], spouses: int[]}>
     */
    private function childrenAndSpouses(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (! $ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select("
            SELECT id, father, mother FROM people
            WHERE father IN ($placeholders) OR mother IN ($placeholders)
        ", [...$ids, ...$ids]);

        $wanted = array_flip($ids);
        $out = [];
        foreach ($rows as $r) {
            $child = (int) $r->id;
            $f = $r->father ? (int) $r->father : 0;
            $m = $r->mother ? (int) $r->mother : 0;
            foreach ([[$f, $m], [$m, $f]] as [$parent, $other]) {
                if (! $parent || ! isset($wanted[$parent])) {
                    continue;
                }
                $out[$parent]['children'][$child] = true;
                if ($other) {
                    $out[$parent]['spouses'][$other] = true;
                }
            }
        }

        return array_map(fn ($r) => [
            'children' => array_keys($r['children'] ?? []),
            'spouses' => array_keys($r['spouses'] ?? []),
        ], $out);
    }

    /** @return int[] */
    private function siblingIds(int $focusId, int $fatherId, int $motherId): array
    {
        if (! $fatherId && ! $motherId) {
            return [];
        }
        $rows = DB::select('
            SELECT id FROM people
            WHERE id <> ?
              AND (
                (? <> 0 AND father = ?)
                OR (? <> 0 AND mother = ?)
              )
        ', [$focusId, $fatherId, $fatherId, $motherId, $motherId]);

        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /** siblingIds() for someone whose parents we haven't already looked up. */
    private function siblingIdsOf(int $personId): array
    {
        $row = DB::selectOne('SELECT father, mother FROM people WHERE id = ?', [$personId]);
        if (! $row) {
            return [];
        }

        return $this->siblingIds($personId, (int) ($row->father ?? 0), (int) ($row->mother ?? 0));
    }

    /** @return int[] — focus's lineage only (no aunts/uncles). */
    private function ancestorIds(int $focusId, int $depth): array
    {
        // Seed with the focus row so its father/mother are available
        // to the recursive step; MariaDB forbids mixing UNION & UNION ALL
        // in a recursive CTE, so we carry parent links in each row.
        $rows = DB::select('
            WITH RECURSIVE anc AS (
                SELECT id, father, mother, 0 AS gen FROM people WHERE id = ?
                UNION ALL
                SELECT p.id, p.father, p.mother, anc.gen + 1
                FROM people p
                JOIN anc ON p.id = anc.father OR p.id = anc.mother
                WHERE anc.gen < ?
            )
            SELECT DISTINCT id FROM anc WHERE id <> ?
        ', [$focusId, $depth, $focusId]);

        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /** @return int[] */
    private function descendantIds(int $focusId, int $depth): array
    {
        $rows = DB::select('
            WITH RECURSIVE des AS (
                SELECT id, 0 AS gen FROM people WHERE id = ?
                UNION ALL
                SELECT p.id, des.gen + 1
                FROM people p
                JOIN des ON p.father = des.id OR p.mother = des.id
                WHERE des.gen < ?
            )
            SELECT DISTINCT id FROM des WHERE id <> ?
        ', [$focusId, $depth, $focusId]);

        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /**
     * For each person in $ids, find anyone they share a child with —
     * those are their spouses for tree display purposes. We don't
     * cascade beyond one hop; spouses don't bring their own lineage.
     *
     * @param  int[]  $ids
     * @return int[]
     */
    private function coParentIds(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (! $ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = DB::select("
            SELECT DISTINCT father AS id FROM people WHERE mother IN ($placeholders) AND father IS NOT NULL
            UNION
            SELECT DISTINCT mother AS id FROM people WHERE father IN ($placeholders) AND mother IS NOT NULL
        ", [...$ids, ...$ids]);

        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /**
     * @param  int[]  $ids  — normalised to a list here, and in the other two
     *                      helpers that inline an IN clause: PDO binds
     *                      positionally, so the gappy array array_unique()
     *                      leaves behind is an "Invalid parameter number".
     */
    private function fetchPeople(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (! $ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return DB::select("
            SELECT id, fullName, gender, minBirth, maxBirth, death,
                   dnaSampleId, father, mother
            FROM people
            WHERE id IN ($placeholders)
        ", $ids);
    }

    private function datumPayload(int $id, object $row, array $rels): array
    {
        $gender = $row->gender === 'F' ? 'F' : 'M'; // f3 requires M|F

        return [
            'id' => (string) $id,
            'data' => [
                'gender' => $gender,
                'first name' => Format::displayLabel($row->fullName ?? null, null),
                'birthday' => Format::years($row->minBirth ?? null, $row->maxBirth ?? null, $row->death ?? null),
                '_person_id' => $id,
                '_dna_sample_id' => $row->dnaSampleId ? (int) $row->dnaSampleId : null,
            ],
            'rels' => [
                'parents' => array_values(array_map('strval', $rels['parents'])),
                'children' => array_values(array_map('strval', $rels['children'])),
                'spouses' => array_values(array_map('strval', $rels['spouses'])),
            ],
        ];
    }

    private function focusPayload(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'display_label' => Format::displayLabel($row->fullName ?? null, null),
            'years' => Format::years($row->minBirth ?? null, $row->maxBirth ?? null, $row->death ?? null),
        ];
    }
}
