<?php

namespace App\Services;

use App\Support\Format;
use Illuminate\Support\Facades\DB;

class PersonDetailService
{
    public function __construct(private KinshipLabelService $kinship) {}

    public function get(int $personId): ?array
    {
        $rows = DB::select('
            SELECT
              p.id,
              p.fullName,
              p.dnaSampleId,
              p.gender,
              ds.displayName AS dnaName,
              ds.userUUID,
              ds.managed AS is_managed_sample,
              p.minBirth,
              p.maxBirth,
              p.death,
              p.father AS father_id,
              pf.fullName AS father_name,
              dsf.displayName AS father_dnaName,
              pf.minBirth AS father_minBirth,
              pf.maxBirth AS father_maxBirth,
              pf.death AS father_death,
              pf.gender AS father_gender,
              p.mother AS mother_id,
              pm.fullName AS mother_name,
              dsm.displayName AS mother_dnaName,
              pm.minBirth AS mother_minBirth,
              pm.maxBirth AS mother_maxBirth,
              pm.death AS mother_death,
              pm.gender AS mother_gender
            FROM people p
            LEFT JOIN dna_samples ds ON ds.id = p.dnaSampleId
            LEFT JOIN people pf ON pf.id = p.father
            LEFT JOIN dna_samples dsf ON dsf.id = pf.dnaSampleId
            LEFT JOIN people pm ON pm.id = p.mother
            LEFT JOIN dna_samples dsm ON dsm.id = pm.dnaSampleId
            WHERE p.id = ?
        ', [$personId]);

        if (!$rows) {
            return null;
        }
        $row = (array) $rows[0];
        $row['display_label'] = Format::displayLabel($row['fullName'] ?? null, $row['dnaName'] ?? null);
        $row['years'] = Format::years($row['minBirth'] ?? null, $row['maxBirth'] ?? null, $row['death'] ?? null);
        $row['father_display_label'] = $row['father_id']
            ? Format::displayLabel($row['father_name'] ?? null, $row['father_dnaName'] ?? null)
            : null;
        $row['father_years'] = $row['father_id']
            ? Format::years($row['father_minBirth'] ?? null, $row['father_maxBirth'] ?? null, $row['father_death'] ?? null)
            : '';
        $row['mother_display_label'] = $row['mother_id']
            ? Format::displayLabel($row['mother_name'] ?? null, $row['mother_dnaName'] ?? null)
            : null;
        $row['mother_years'] = $row['mother_id']
            ? Format::years($row['mother_minBirth'] ?? null, $row['mother_maxBirth'] ?? null, $row['mother_death'] ?? null)
            : '';
        return $row;
    }

    public function children(int $personId): array
    {
        $rows = DB::select('
            SELECT
              c.id AS child_id,
              c.fullName AS child_name,
              c.dnaSampleId AS child_dnaSampleId,
              ds_c.displayName AS child_dnaName,
              c.minBirth AS child_minBirth,
              c.maxBirth AS child_maxBirth,
              c.death AS child_death,
              c.gender AS child_gender,
              CASE WHEN c.father = ? THEN c.mother ELSE c.father END AS spouse_id,
              CASE WHEN c.father = ? THEN pm.fullName ELSE pf.fullName END AS spouse_name,
              CASE WHEN c.father = ? THEN dsm.displayName ELSE dsf.displayName END AS spouse_dnaName,
              CASE WHEN c.father = ? THEN pm.minBirth ELSE pf.minBirth END AS spouse_minBirth,
              CASE WHEN c.father = ? THEN pm.maxBirth ELSE pf.maxBirth END AS spouse_maxBirth,
              CASE WHEN c.father = ? THEN pm.death ELSE pf.death END AS spouse_death,
              CASE WHEN c.father = ? THEN pm.gender ELSE pf.gender END AS spouse_gender
            FROM people c
            LEFT JOIN dna_samples ds_c ON ds_c.id = c.dnaSampleId
            LEFT JOIN people pf ON pf.id = c.father
            LEFT JOIN dna_samples dsf ON dsf.id = pf.dnaSampleId
            LEFT JOIN people pm ON pm.id = c.mother
            LEFT JOIN dna_samples dsm ON dsm.id = pm.dnaSampleId
            WHERE c.father = ? OR c.mother = ?
            ORDER BY spouse_id, c.fullName
        ', array_fill(0, 9, $personId));

        $spouses = [];
        foreach ($rows as $r) {
            $sid = $r->spouse_id;
            $key = $sid ?? 'unknown';
            if (!isset($spouses[$key])) {
                $spouses[$key] = [
                    'spouse_id' => $sid,
                    'spouse_display_label' => $sid
                        ? Format::displayLabel($r->spouse_name ?? null, $r->spouse_dnaName ?? null)
                        : null,
                    'spouse_years' => $sid
                        ? Format::years($r->spouse_minBirth ?? null, $r->spouse_maxBirth ?? null, $r->spouse_death ?? null)
                        : '',
                    'spouse_gender' => $sid ? $r->spouse_gender : null,
                    'children' => [],
                ];
            }
            $spouses[$key]['children'][] = [
                'child_id' => $r->child_id,
                'display_label' => Format::displayLabel($r->child_name ?? null, $r->child_dnaName ?? null),
                'years' => Format::years($r->child_minBirth ?? null, $r->child_maxBirth ?? null, $r->child_death ?? null),
                'gender' => $r->child_gender,
            ];
        }
        return array_values($spouses);
    }

    public function siblings(int $personId, ?int $fatherId, ?int $motherId): array
    {
        $base = '
            SELECT p.id, p.fullName, p.dnaSampleId, p.minBirth, p.maxBirth, p.death, p.gender, ds.displayName AS dnaName
            FROM people p
            LEFT JOIN dna_samples ds ON ds.id = p.dnaSampleId
        ';

        $decorate = function (array $rows): array {
            return array_map(function ($r) {
                $row = (array) $r;
                $row['display_label'] = Format::displayLabel($row['fullName'] ?? null, $row['dnaName'] ?? null);
                $row['years'] = Format::years($row['minBirth'] ?? null, $row['maxBirth'] ?? null, $row['death'] ?? null);
                return $row;
            }, $rows);
        };

        $full = [];
        if ($fatherId && $motherId) {
            $full = $decorate(DB::select(
                $base . 'WHERE p.id != ? AND p.father = ? AND p.mother = ? ORDER BY p.fullName',
                [$personId, $fatherId, $motherId]
            ));
        }

        $halfFather = [];
        if ($fatherId) {
            $halfFather = $decorate(DB::select(
                $base . 'WHERE p.id != ? AND p.father = ? AND (p.mother IS NULL OR p.mother != ?) ORDER BY p.fullName',
                [$personId, $fatherId, $motherId ?? 0]
            ));
        }

        $halfMother = [];
        if ($motherId) {
            $halfMother = $decorate(DB::select(
                $base . 'WHERE p.id != ? AND p.mother = ? AND (p.father IS NULL OR p.father != ?) ORDER BY p.fullName',
                [$personId, $motherId, $fatherId ?? 0]
            ));
        }

        return ['full' => $full, 'half_father' => $halfFather, 'half_mother' => $halfMother];
    }

    /**
     * Set of people.id values connected to this person via shared
     * ancestry — the person themself plus every ancestor reachable
     * by walking up father/mother edges, no depth cap.
     *
     * Used by the DNA matches page to flag rows whose linked person
     * is in the title person's ancestor tree (i.e. there is a
     * documented family-tree connection on top of the DNA match).
     *
     * Returned as a map keyed by id for O(1) lookup on the client.
     *
     * @return array<int, true>
     */
    public function connectedPeopleSet(int $personId): array
    {
        $rows = DB::select('
            WITH RECURSIVE peopleTree AS (
                SELECT id, mother, father FROM people WHERE id = ?
                UNION
                SELECT p.id, p.mother, p.father
                  FROM people p
                  INNER JOIN peopleTree pt
                    ON pt.mother = p.id OR pt.father = p.id
            )
            SELECT id FROM peopleTree
        ', [$personId]);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->id] = true;
        }
        return $out;
    }

    public function ancestryTrees(int $personId): array
    {
        // LEFT JOIN gedcom_tree so a gedcom_people row referencing a tree
        // we don't have metadata for still surfaces — UI renders the link
        // with an "Unknown Tree" label so the user can still click through.
        // Soft-deleted trees (disabled=1) are dropped; a NULL gedcom_tree
        // row (never fetched) is kept.
        return array_map(fn ($r) => (array) $r, DB::select('
            SELECT gp.atreeid, gt.name, gp.ancestryid
            FROM gedcom_people gp
            LEFT JOIN gedcom_tree gt ON gt.atreeid = gp.atreeid
            WHERE gp.peopleid = ?
              AND (gt.disabled IS NULL OR gt.disabled = 0)
            ORDER BY gt.name IS NULL, gt.name, gp.atreeid
        ', [$personId]));
    }

    /**
     * @return array|null  null when the dna_sample id is missing from the table
     */
    public function eyeMatches(?int $dnaSampleId): ?array
    {
        if (!$dnaSampleId) {
            return [];
        }
        $exists = DB::selectOne('SELECT 1 AS x FROM dna_samples WHERE id = ?', [$dnaSampleId]);
        if (!$exists) {
            return null;
        }
        // dna_matches2 is directional. "Managed eyes that match this sample"
        // = rows where sample1 is a managed eye and sample2 = this sample.
        // The cM / cluster / kinship are from the eye's perspective, which
        // is the authoritative side (the one whose Ancestry session loaded
        // the match).
        $rows = DB::select('
            SELECT
              m.sample1 AS eye_id,
              ds_eye.displayName AS eye_name,
              ds_eye.photoUrl AS eye_photoUrl,
              ds_eye.gender AS eye_gender,
              ds_eye.paternalCluster AS eye_paternalCluster,
              ds_eye.userUUID AS eye_userUUID,
              admin.userUUID AS eye_admin_userUUID,
              p_eye.id AS person_id,
              p_eye.fullName AS person_name,
              p_eye.gender AS person_gender,
              m.sharedCentimorgans,
              m.numSharedSegments,
              m.matchClusterCode,
              m.predictedKinships,
              m.ignored,
              dn.notes
            FROM dna_matches2 m
            JOIN dna_samples ds_eye
              ON ds_eye.id = m.sample1
             AND ds_eye.managed IS NOT NULL
             AND ds_eye.managed > 0
            LEFT JOIN people p_eye ON p_eye.dnaSampleId = ds_eye.id
            LEFT JOIN dna_samples admin ON admin.id = ds_eye.adminid
            LEFT JOIN dna_notes dn ON dn.sample = ? AND dn.mgmtsample = ds_eye.id
            WHERE m.sample2 = ?
            ORDER BY m.sharedCentimorgans DESC, ds_eye.displayName ASC
        ', [$dnaSampleId, $dnaSampleId]);

        // The focus person's effective gender drives the kinship label
        // (sample2 in the kinship row is the focus's dna sample).
        $focusGenderRow = DB::selectOne('
            SELECT p.gender AS person_gender, s.gender AS sample_gender
              FROM dna_samples s
              LEFT JOIN people p ON p.dnaSampleId = s.id
             WHERE s.id = ?
        ', [$dnaSampleId]);
        $focusGender = Format::effectiveGender(
            $focusGenderRow->person_gender ?? null,
            $focusGenderRow->sample_gender ?? null,
        );

        $rows = array_map(function ($r) use ($dnaSampleId, $focusGender) {
            $row = (array) $r;
            $row['sample2'] = $dnaSampleId;
            $row['focus_gender'] = $focusGender;
            $row['display_label'] = Format::displayLabel($row['person_name'] ?? null, $row['eye_name'] ?? null);
            $row['ignored'] = (bool) ($row['ignored'] ?? false);
            $row['effective_gender'] = Format::effectiveGender($row['person_gender'] ?? null, $row['eye_gender'] ?? null);
            return $row;
        }, $rows);

        // Kinship rows are (sample1=eye_id, sample2=this person). Look
        // them up keyed on eye_id; the gender that drives label choice
        // is the focus person's, which is constant per page.
        $this->kinship->decorate($rows, 'eye_id', 'sample2', 'focus_gender');
        return $rows;
    }
}
