<?php

namespace App\Services;

use App\Support\Format;
use Illuminate\Support\Facades\DB;

class FamilyTreeService
{
    public const ANCESTOR_DEPTH = 6;
    public const DESCENDANT_DEPTH = 4;

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
        if (!$focus) {
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

        $rows = $this->fetchPeople(array_keys($ids));
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int) $r->id] = $r;
        }

        $scope = array_flip(array_keys($byId));
        $rels = []; // id => [parents, children, spouses]
        foreach ($byId as $id => $r) {
            $rels[$id] = [
                'parents' => [],
                'children' => [],
                'spouses' => [],
            ];
        }

        // parents (filtered to scope)
        foreach ($byId as $id => $r) {
            $f = $r->father ? (int) $r->father : null;
            $m = $r->mother ? (int) $r->mother : null;
            if ($f && isset($scope[$f])) {
                $rels[$id]['parents'][] = $f;
                $rels[$f]['children'][] = $id;
            }
            if ($m && isset($scope[$m])) {
                $rels[$id]['parents'][] = $m;
                $rels[$m]['children'][] = $id;
            }
        }

        // spouses: for each person, the other parent of each of their children
        foreach ($rels as $pid => &$r) {
            $seen = [];
            foreach ($r['children'] as $cid) {
                foreach ($rels[$cid]['parents'] as $parentId) {
                    if ($parentId === $pid) {
                        continue;
                    }
                    if (!isset($seen[$parentId])) {
                        $seen[$parentId] = true;
                        $r['spouses'][] = $parentId;
                    }
                }
            }
        }
        unset($r);

        $people = [];
        foreach ($byId as $id => $row) {
            $people[] = $this->datumPayload($id, $row, $rels[$id]);
        }

        return [
            'focus_id' => (string) $focusId,
            'focus' => $this->focusPayload($focus),
            'people' => $people,
            'ancestor_depth' => self::ANCESTOR_DEPTH,
            'descendant_depth' => self::DESCENDANT_DEPTH,
        ];
    }

    /** @return int[] */
    private function siblingIds(int $focusId, int $fatherId, int $motherId): array
    {
        if (!$fatherId && !$motherId) {
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

    /** @return int[] — focus's lineage only (no aunts/uncles). */
    private function ancestorIds(int $focusId, int $depth): array
    {
        // Seed with the focus row so its father/mother are available
        // to the recursive step; MariaDB forbids mixing UNION & UNION ALL
        // in a recursive CTE, so we carry parent links in each row.
        $rows = DB::select("
            WITH RECURSIVE anc AS (
                SELECT id, father, mother, 0 AS gen FROM people WHERE id = ?
                UNION ALL
                SELECT p.id, p.father, p.mother, anc.gen + 1
                FROM people p
                JOIN anc ON p.id = anc.father OR p.id = anc.mother
                WHERE anc.gen < ?
            )
            SELECT DISTINCT id FROM anc WHERE id <> ?
        ", [$focusId, $depth, $focusId]);
        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /** @return int[] */
    private function descendantIds(int $focusId, int $depth): array
    {
        $rows = DB::select("
            WITH RECURSIVE des AS (
                SELECT id, 0 AS gen FROM people WHERE id = ?
                UNION ALL
                SELECT p.id, des.gen + 1
                FROM people p
                JOIN des ON p.father = des.id OR p.mother = des.id
                WHERE des.gen < ?
            )
            SELECT DISTINCT id FROM des WHERE id <> ?
        ", [$focusId, $depth, $focusId]);
        return array_map(fn ($r) => (int) $r->id, $rows);
    }

    /**
     * For each person in $ids, find anyone they share a child with —
     * those are their spouses for tree display purposes. We don't
     * cascade beyond one hop; spouses don't bring their own lineage.
     *
     * @param int[] $ids
     * @return int[]
     */
    private function coParentIds(array $ids): array
    {
        if (!$ids) {
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

    /** @param int[] $ids */
    private function fetchPeople(array $ids): array
    {
        if (!$ids) {
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
