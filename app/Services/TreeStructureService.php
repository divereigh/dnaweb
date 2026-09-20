<?php

namespace App\Services;

use App\Support\PhoneticEncoder;
use Illuminate\Support\Facades\DB;

/**
 * The model behind /tree/{name} — a PHP port of ~/ancestry/program/print-tree.
 *
 * print-tree.c is thirty lines of glue: loadInitialTree() (utillib.c) pulls
 * every member of the named tree out of `people` + `tree_people`, wires the
 * parent links up between the rows it loaded, and genTree_show() (GenTree.c)
 * walks the result printing an ASCII forest. Everything below is that, in the
 * same order and with the same arithmetic, so the page agrees with the C.
 *
 * The shape of it:
 *
 *   - A tree is whatever `tree_people` says it is. Parent links come from
 *     `people.father` / `people.mother` but only resolve when the parent is
 *     *also* a member; a parent who is named but not a member collapses to the
 *     sentinel EXTERNAL (node 0), which is how "there was a parent here, we
 *     just aren't carrying them" is spelled.
 *   - Each node has two parent slots. PARENT1 is the DNA parent — the one
 *     `tree_people.dna` is set on, i.e. the line the tree is actually tracing.
 *     PARENT2 is the other one (usually a married-in spouse). The forest's
 *     spine runs up PARENT1 only.
 *   - Roots are the DNA members with no DNA parent of their own
 *     (genTree_nodeTopAncestor). Everything else hangs off one of them.
 *   - Setting a parent link narrows both parties' birth-year ranges against
 *     childbearing-age bounds, and the narrowing cascades to their own parents
 *     and children. A link whose years cannot be reconciled is *dropped* —
 *     that is why the years on this page can be tighter than `people` holds,
 *     and why a person can show fewer parents here than the database names.
 *     Those drops are collected into warnings[] rather than thrown away.
 *
 * Two deliberate departures from the C, both in the rendering half:
 *
 *   - The C caches a node's display string the first time it needs one, which
 *     is mid-load, so a node whose years are narrowed *afterwards* prints its
 *     stale range (6-7 nodes in a 2000-person tree). We format at the end, so
 *     the years here match print-tree's data dump rather than its ASCII half.
 *   - A person with two member parents sits in both parents' child lists and
 *     the C re-prints their whole subtree under each. Burton renders 17k rows
 *     from 2,988 people that way. Here the second and later appearances are
 *     stubs (repeat_of) pointing back at the one full copy, which bounds the
 *     page at one rendered row per person per parent.
 *
 * @see /home/damo/ancestry/program/print-tree.c
 * @see /home/damo/ancestry/program/GenTree.c — genTree_show, genTree_nodeShowR
 * @see /home/damo/ancestry/program/utillib.c — loadInitialTree, loadSQLResult
 */
class TreeStructureService
{
    public function __construct(private EyeSetService $eyeSet) {}

    /* GenTree.h */
    private const MIN_CHILDBEARING_AGE = 14;

    private const MAX_CHILDBEARING_AGE_MALE = 75;

    private const MAX_CHILDBEARING_AGE_FEMALE = 54;

    private const MAX_CHILD_COUNT = 20;

    private const GENDER_UNKNOWN = 0;

    private const GENDER_MALE = 1;

    private const GENDER_FEMALE = 2;

    private const INACTIVE = -1;

    private const EXTERNAL = 0;

    /* utillib.h — the birth window assumed for a kit with no dates at all. */
    private const DEFAULT_SAMPLE_MINBIRTH = 1910;

    /**
     * Nodes, indexed by node number. Node 0 is EXTERNAL: a real, mutable node
     * in the C too, which is why it is built here rather than special-cased.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $nodes = [];

    private int $nodeCount = 0;

    private int $curYear = 0;

    /** @var string[] */
    private array $warnings = [];

    private int $subTreeCount = 0;

    /**
     * Resolve the {tree} path segment. Name first — `tree.name` is unique and
     * is what print-tree takes — then id, so a tree that happens to be *named*
     * "23158" still wins over the tree whose id that is.
     */
    public function find(string $key): ?object
    {
        $row = DB::selectOne('SELECT id, name, description, colour, priority FROM tree WHERE name = ?', [$key]);
        if ($row) {
            return $row;
        }
        if (ctype_digit($key)) {
            return DB::selectOne('SELECT id, name, description, colour, priority FROM tree WHERE id = ?', [(int) $key]);
        }

        return null;
    }

    /**
     * Load a tree and lay it out. Returns the forest plus everything the page
     * needs to caption it.
     *
     * @return array{
     *     counts: array<string, int>,
     *     roots: array<int, array<string, mixed>>,
     *     unplaced: array<int, array<string, mixed>>,
     *     warnings: string[],
     * }
     */
    public function build(int $treeId): array
    {
        $this->load($treeId);
        $this->linkParents();
        $this->resolveSameAs();
        $this->initSubTrees();

        return $this->render();
    }

    /**
     * Shared DNA between one reference kit and every kit in this tree — the
     * numbers behind the heat map on /tree/{name}.
     *
     * The reference is named the way the rest of the page names people, by
     * `people.fullName`; an exact match wins, and anything else comes back as
     * a short list of candidates for the page to offer.
     *
     * `dna_matches2` is directional and only an eye carries outgoing rows, so
     * a reference that is not an eye would look like it matched nobody if we
     * only read one direction. Shared cM is symmetric, so both are read and
     * the larger kept — which is also what makes a pair loaded through two
     * different eyes agree.
     *
     * @return array{
     *     ref: array<string, mixed>|null,
     *     candidates: array<int, array<string, mixed>>,
     *     cm: array<int, int>,
     * }
     */
    public function heatmap(int $treeId, string $q): array
    {
        $q = trim($q);
        $empty = ['ref' => null, 'candidates' => [], 'cm' => []];
        if ($q === '') {
            return $empty;
        }

        $ref = DB::selectOne('
            SELECT p.id, p.fullName, p.dnaSampleId, ds.displayName
            FROM people p
            INNER JOIN dna_samples ds ON ds.id = p.dnaSampleId
            WHERE p.fullName = ?
            ORDER BY p.alt
            LIMIT 1
        ', [$q]);

        if (! $ref) {
            // No exact hit — offer what we do have, over the same lexical +
            // phonetic FULLTEXT pair the rest of the app searches names with.
            // A LIKE '%…%' here cost a second of full scan per keystroke.
            [$lex, $phon] = PhoneticEncoder::buildBoolean($q);
            if ($lex === '') {
                return $empty;
            }
            // The two MATCHes are UNIONed rather than OR'd: MariaDB scores an
            // OR'd pair row-by-row and returns 0, so neither index gets used.
            // Same workaround as PeopleSearchService.
            $rows = DB::select('
                SELECT p.id, p.fullName, ds.displayName
                FROM people p
                INNER JOIN dna_samples ds ON ds.id = p.dnaSampleId
                WHERE p.id IN (
                    SELECT p2.id FROM people p2 WHERE MATCH(p2.fullName) AGAINST (? IN BOOLEAN MODE)
                    UNION
                    SELECT p3.id FROM people p3 WHERE MATCH(p3.fullName_phonetic) AGAINST (? IN BOOLEAN MODE)
                )
                ORDER BY CHAR_LENGTH(p.fullName), p.fullName
                LIMIT 10
            ', [$lex, $phon !== '' ? $phon : '+__never_matches__']);

            return [
                'ref' => null,
                'candidates' => array_map(fn ($r) => [
                    'person_id' => (int) $r->id,
                    'name' => $r->fullName,
                    'dna_name' => $r->displayName,
                ], $rows),
                'cm' => [],
            ];
        }

        $refSample = (int) $ref->dnaSampleId;
        $sampleRows = DB::select('
            SELECT p.dnaSampleId AS sid
            FROM people p
            INNER JOIN tree_people tp ON tp.peopleId = p.id
            WHERE tp.treeId = ? AND p.dnaSampleId IS NOT NULL
        ', [$treeId]);
        $sampleIds = array_values(array_unique(array_map(fn ($r) => (int) $r->sid, $sampleRows)));

        // Absence of a row is only a zero when somebody actually looked.
        //
        // Ancestry records a pair only when it is a match, so "no row" is
        // ambiguous: it means either "not related" or "nobody has compared
        // these two kits". The pair has been compared exactly when one side
        // is an eye, because an eye's own match list is loaded in full — and
        // only then. dna_matches2.sample1 cannot stand in for that: match-of-
        // match loading writes ordinary matches into sample1 too, and those
        // carry only the slice of a kit's matches that its eye could see.
        // See EyeSetService, whose docblock is the long version.
        //
        // So a known zero is seeded for every kit that shares an eye with the
        // reference, and anything still missing afterwards is a kit we have
        // no reading for at all — which the page shows as neither.
        $eyes = array_flip($this->eyeSet->ids());
        $refIsEye = isset($eyes[$refSample]);

        $cm = [];
        foreach ($sampleIds as $sid) {
            if ($sid !== $refSample && ($refIsEye || isset($eyes[$sid]))) {
                $cm[$sid] = 0;
            }
        }

        if ($sampleIds) {
            $in = implode(',', array_fill(0, count($sampleIds), '?'));
            $rows = DB::select("
                SELECT sample2 AS sid, sharedCentimorgans AS cm
                FROM dna_matches2 WHERE sample1 = ? AND sample2 IN ($in)
                UNION ALL
                SELECT sample1 AS sid, sharedCentimorgans AS cm
                FROM dna_matches2 WHERE sample2 = ? AND sample1 IN ($in)
            ", [$refSample, ...$sampleIds, $refSample, ...$sampleIds]);

            foreach ($rows as $row) {
                $sid = (int) $row->sid;
                $value = (int) $row->cm;
                if ($sid !== $refSample && $value > ($cm[$sid] ?? 0)) {
                    $cm[$sid] = $value;
                }
            }
        }

        return [
            'ref' => [
                'person_id' => (int) $ref->id,
                'name' => $ref->fullName,
                'dna_name' => $ref->displayName,
                'dna_sample_id' => $refSample,
                'is_eye' => $refIsEye,
                'matches' => count(array_filter($cm)),
                'compared' => count($cm),
                'kits' => count($sampleIds),
            ],
            'candidates' => [],
            'cm' => $cm,
        ];
    }

    // ---------------------------------------------------------------- load

    /**
     * loadInitialTree() + the row-reading half of loadSQLResult().
     *
     * The GROUP BY is the C's, and it fixes the node numbering: rows come back
     * in `people.id` order and are numbered 1..N in that order, which is the
     * "#012" you see in print-tree's output. Keeping it means the numbers on
     * this page and in a terminal run agree.
     */
    private function load(int $treeId): void
    {
        $rows = DB::select('
            SELECT people.id AS recId,
                   people.fullName,
                   people.alt,
                   tree_people.ignParents,
                   IF(tree_people.ignParents = \'father\' OR tree_people.ignParents = \'both\', NULL, people.father) AS father,
                   IF(tree_people.ignParents = \'mother\' OR tree_people.ignParents = \'both\', NULL, people.mother) AS mother,
                   people.minBirth,
                   people.maxBirth,
                   people.death,
                   people.gender,
                   tree_people.dna,
                   tree_people.forceJoin,
                   tree_people.disable,
                   tree_people.noUnknownChildren,
                   people.notes,
                   people.sameAs,
                   dna_samples.id AS dnaSampleId,
                   dna_samples.displayName AS dnaName
            FROM people
            INNER JOIN tree_people ON people.id = tree_people.peopleId
            LEFT JOIN dna_samples ON dna_samples.id = people.dnaSampleId
            WHERE tree_people.treeId = ?
            GROUP BY people.id
            ORDER BY people.id
        ', [$treeId]);

        $this->curYear = (int) date('Y');
        $this->warnings = [];
        $this->nodes = [$this->blankNode(self::EXTERNAL)];
        $this->nodes[self::EXTERNAL]['name'] = 'EXTERNAL';

        $id = 0;
        foreach ($rows as $row) {
            $node = $this->blankNode(++$id);

            $node['recId'] = (int) $row->recId;
            $node['name'] = $row->fullName;
            $node['alt'] = (int) $row->alt;
            $node['ignParents'] = match ($row->ignParents) {
                'father' => 'F',
                'mother' => 'M',
                'both' => 'B',
                default => null,
            };
            $node['fatherRecId'] = (int) ($row->father ?? 0);
            $node['motherRecId'] = (int) ($row->mother ?? 0);
            $node['minBirth'] = (int) ($row->minBirth ?? 0);
            $node['maxBirth'] = (int) ($row->maxBirth ?? 0);
            $node['death'] = (int) ($row->death ?? 0);
            $node['gender'] = match ($row->gender) {
                'M' => self::GENDER_MALE,
                'F' => self::GENDER_FEMALE,
                default => self::GENDER_UNKNOWN,
            };
            $node['dna'] = (int) $row->dna;
            $node['forceJoin'] = (int) $row->forceJoin;
            $node['disable'] = (int) $row->disable;
            $node['noUnknownChildren'] = (int) $row->noUnknownChildren;
            $node['notes'] = $row->notes;
            $node['sameAsRecId'] = (int) ($row->sameAs ?? 0);
            $node['dnaSampleRecId'] = (int) ($row->dnaSampleId ?? 0);
            $node['dnaName'] = $row->dnaName;

            // A kit with no dates at all still needs a window, or every
            // childbearing-age test against it would be a no-op.
            if ($node['dnaSampleRecId']) {
                if ($node['minBirth'] === 0) {
                    $node['minBirth'] = self::DEFAULT_SAMPLE_MINBIRTH;
                }
                if ($node['maxBirth'] === 0) {
                    $node['maxBirth'] = $this->curYear;
                }
            }
            // Only one end known means a point, not a range.
            if (! $node['minBirth'] || ! $node['maxBirth']) {
                $node['maxBirth'] = $node['minBirth'];
            }
            $node['minBirth'] = min($node['minBirth'], $this->curYear);
            $node['maxBirth'] = min($node['maxBirth'], $this->curYear);
            $node['death'] = min($node['death'], $this->curYear);

            $this->nodes[$id] = $node;
        }

        $this->nodeCount = $id + 1;
    }

    /** @return array<string, mixed> */
    private function blankNode(int $id): array
    {
        return [
            'id' => $id,
            'recId' => 0,
            'name' => null,
            'alt' => 0,
            'ignParents' => null,
            'fatherRecId' => 0,
            'motherRecId' => 0,
            'minBirth' => 0,
            'maxBirth' => 0,
            'death' => 0,
            'gender' => self::GENDER_UNKNOWN,
            'dna' => 0,
            'forceJoin' => 0,
            'disable' => 0,
            'noUnknownChildren' => 0,
            'notes' => null,
            'sameAsRecId' => 0,
            'dnaSampleRecId' => 0,
            'dnaName' => null,
            'parent1' => self::INACTIVE,
            'parent2' => self::INACTIVE,
            'children' => [],
            'sameAs' => self::INACTIVE,
            'subTreeId' => self::INACTIVE,
        ];
    }

    // -------------------------------------------------------------- linking

    /**
     * The parent fixup from loadSQLResult(). The C scans every node against
     * every other looking for a recId match; a recId -> node index does the
     * same in one pass, but the candidates are still visited in ascending node
     * order because which slot a parent lands in depends on which was filled
     * first.
     */
    private function linkParents(): void
    {
        $byRecId = [];
        for ($id = 1; $id < $this->nodeCount; $id++) {
            $byRecId[$this->nodes[$id]['recId']] = $id;
        }

        for ($id1 = 1; $id1 < $this->nodeCount; $id1++) {
            $candidates = [];
            foreach (['fatherRecId', 'motherRecId'] as $key) {
                $recId = $this->nodes[$id1][$key];
                // A row that names itself as its own parent would send
                // topAncestor() into a loop; the C has no such guard because
                // the loaders never write one.
                if ($recId && isset($byRecId[$recId]) && $byRecId[$recId] !== $id1) {
                    $candidates[$byRecId[$recId]] = true;
                }
            }
            $candidates = array_keys($candidates);
            sort($candidates);

            foreach ($candidates as $id2) {
                if ($this->nodes[$id2]['dna']) {
                    if (! $this->isActive($this->nodes[$id1]['parent1'])) {
                        $this->setParent1($id1, $id2);
                    } else {
                        $this->setParent2($id1, $id2);
                    }

                    continue;
                }

                if (! $this->isActive($this->nodes[$id1]['parent2'])) {
                    $this->setParent2($id1, $id2);
                } elseif (! $this->isActive($this->nodes[$id1]['parent1'])) {
                    // PARENT1 is the DNA slot by definition, so a non-DNA
                    // parent can only go there by being promoted. The C warns
                    // and does it anyway rather than lose the link.
                    $this->nodes[$id2]['dna'] = 1;
                    if ($this->setParent1($id1, $id2)) {
                        $this->warnings[] = sprintf(
                            'DNA flag forced on during load: %s is used as the DNA parent of %s (tree_people.dna was 0, and no other DNA parent was available).',
                            $this->nodeName($id2),
                            $this->nodeName($id1),
                        );
                    }
                }
            }

            // A parent the database names but the tree does not carry still
            // occupies a slot — as EXTERNAL, so the page can say "there was
            // someone here" instead of silently showing an only child.
            $father = $this->nodes[$id1]['fatherRecId'];
            $mother = $this->nodes[$id1]['motherRecId'];
            if ($father && $mother) {
                if (! $this->isActive($this->nodes[$id1]['parent1'])) {
                    $this->nodes[$id1]['parent1'] = self::EXTERNAL;
                }
                if (! $this->isActive($this->nodes[$id1]['parent2'])) {
                    $this->nodes[$id1]['parent2'] = self::EXTERNAL;
                }
            } elseif ($father || $mother) {
                if (! $this->isActive($this->nodes[$id1]['parent1']) && ! $this->isActive($this->nodes[$id1]['parent2'])) {
                    $this->nodes[$id1]['parent2'] = self::EXTERNAL;
                }
            }
        }
    }

    /** genTree_nodeSetParent1() — the DNA slot. */
    private function setParent1(int $nodeId, int $parentId): bool
    {
        if (! $this->isActive($parentId)) {
            return false;
        }
        if ($this->isActive($this->nodes[$nodeId]['parent1'])) {
            return $this->nodes[$nodeId]['parent1'] === $parentId;
        }
        if (! $this->fixParentGender($parentId, $this->nodes[$nodeId]['parent2'])) {
            return false;
        }
        if (! $this->adjustBirthYearInfo($nodeId, $parentId)) {
            return false;
        }
        $this->nodes[$nodeId]['parent1'] = $parentId;
        $this->insChild($parentId, $nodeId);

        return true;
    }

    /** genTree_nodeSetParent2() — the other slot. */
    private function setParent2(int $nodeId, int $parentId): bool
    {
        if (! $this->isActive($parentId)) {
            return false;
        }
        if ($this->isActive($this->nodes[$nodeId]['parent2'])) {
            return $this->nodes[$nodeId]['parent2'] === $parentId;
        }
        if (! $this->fixParentGender($parentId, $this->nodes[$nodeId]['parent1'])) {
            return false;
        }
        if (! $this->adjustBirthYearInfo($nodeId, $parentId)) {
            return false;
        }
        $this->nodes[$nodeId]['parent2'] = $parentId;
        $this->insChild($parentId, $nodeId);

        return true;
    }

    private function insChild(int $parentId, int $childId): void
    {
        if (in_array($childId, $this->nodes[$parentId]['children'], true)) {
            return;
        }
        if (count($this->nodes[$parentId]['children']) >= self::MAX_CHILD_COUNT) {
            $this->warnings[] = sprintf(
                '%s already has %d children in this tree; %s could not be added.',
                $this->nodeName($parentId),
                self::MAX_CHILD_COUNT,
                $this->nodeName($childId),
            );

            return;
        }
        $this->nodes[$parentId]['children'][] = $childId;
    }

    /**
     * genTree_nodeFixParentGender(). Two parents must differ; an unknown one
     * takes the opposite of its partner. Same-gender pairs fail the link.
     */
    private function fixParentGender(int $parent1Id, int $parent2Id): bool
    {
        if (! isset($this->nodes[$parent1Id]) || ! isset($this->nodes[$parent2Id])) {
            return true;
        }
        if ($this->nodes[$parent1Id]['gender'] === self::GENDER_UNKNOWN) {
            $this->nodes[$parent1Id]['gender'] = $this->oppositeGender($parent2Id);
        }
        if ($this->nodes[$parent2Id]['gender'] === self::GENDER_UNKNOWN) {
            $this->nodes[$parent2Id]['gender'] = $this->oppositeGender($parent1Id);
        }

        return ! ($this->nodes[$parent1Id]['gender'] === $this->nodes[$parent2Id]['gender']
            && $this->nodes[$parent1Id]['gender'] !== self::GENDER_UNKNOWN);
    }

    private function oppositeGender(int $nodeId): int
    {
        return match ($this->nodes[$nodeId]['gender']) {
            self::GENDER_FEMALE => self::GENDER_MALE,
            self::GENDER_MALE => self::GENDER_FEMALE,
            default => self::GENDER_UNKNOWN,
        };
    }

    // ------------------------------------------------------- birth windows

    private function minChildBearingYear(int $id): int
    {
        return $this->nodes[$id]['minBirth'] ? $this->nodes[$id]['minBirth'] + self::MIN_CHILDBEARING_AGE : 0;
    }

    private function maxChildBearingYear(int $id): int
    {
        if (! $this->nodes[$id]['maxBirth']) {
            return 0;
        }

        return $this->nodes[$id]['maxBirth'] + ($this->nodes[$id]['gender'] === self::GENDER_FEMALE
            ? self::MAX_CHILDBEARING_AGE_FEMALE
            : self::MAX_CHILDBEARING_AGE_MALE);
    }

    private function minParentBirthYear(int $childId, int $parentGender): int
    {
        if (! $this->nodes[$childId]['minBirth']) {
            return 0;
        }

        return $this->nodes[$childId]['minBirth'] - ($parentGender === self::GENDER_FEMALE
            ? self::MAX_CHILDBEARING_AGE_FEMALE
            : self::MAX_CHILDBEARING_AGE_MALE);
    }

    private function maxParentBirthYear(int $childId): int
    {
        return $this->nodes[$childId]['maxBirth'] ? $this->nodes[$childId]['maxBirth'] - self::MIN_CHILDBEARING_AGE : 0;
    }

    /** genTree_nodeCheckBirthYearInfo() — can this pair be parent and child at all? */
    private function checkBirthYearInfo(int $childId, int $parentId): bool
    {
        if (! isset($this->nodes[$parentId])) {
            return false;
        }
        $minBirth = $this->nodes[$childId]['minBirth'];
        $maxBirth = $this->nodes[$childId]['maxBirth'];

        if ($this->minChildBearingYear($parentId) > $this->curYear) {
            $this->reject($childId, $parentId, sprintf(
                'the parent would not be old enough (earliest possible year of a child is %d)',
                $this->minChildBearingYear($parentId),
            ));

            return false;
        }
        if ($maxBirth && $this->nodes[$parentId]['minBirth'] && $maxBirth < $this->minChildBearingYear($parentId)) {
            $this->reject($childId, $parentId, sprintf(
                'the child is too old — born by %d, but the parent could not have a child before %d',
                $maxBirth,
                $this->minChildBearingYear($parentId),
            ));

            return false;
        }
        if ($minBirth && $this->nodes[$parentId]['maxBirth'] && $minBirth > $this->maxChildBearingYear($parentId)) {
            $this->reject($childId, $parentId, sprintf(
                'the child is too young — born no earlier than %d, but the parent could not have a child after %d',
                $minBirth,
                $this->maxChildBearingYear($parentId),
            ));

            return false;
        }
        if ($this->nodes[$parentId]['death'] && $this->nodes[$parentId]['death'] < $minBirth - 1) {
            $this->reject($childId, $parentId, sprintf(
                'the parent died in %d, before the child could be born (%d)',
                $this->nodes[$parentId]['death'],
                $minBirth,
            ));

            return false;
        }

        return true;
    }

    private function reject(int $childId, int $parentId, string $why): void
    {
        $this->warnings[] = sprintf(
            '%s is not shown as a parent of %s: %s.',
            $this->nodeName($parentId),
            $this->nodeName($childId),
            $why,
        );
    }

    /**
     * genTree_nodeAdjustBirthYearInfo(). Narrow both ends of the pair against
     * childbearing-age bounds, then push the narrowing outward — up through
     * the parent's own parents and down through the child's children — until
     * nothing moves. Returns false if any step is impossible, in which case
     * the caller abandons the link.
     *
     * Like the C, a failure part way through leaves the narrowing it already
     * did in place. That is visible: a rejected link can still have tightened
     * the years the page shows.
     */
    private function adjustBirthYearInfo(int $nodeId, int $parentId): bool
    {
        if (! isset($this->nodes[$nodeId]) || ! isset($this->nodes[$parentId])) {
            return true;
        }
        if (! $this->checkBirthYearInfo($nodeId, $parentId)) {
            return false;
        }
        if ($this->minChildBearingYear($parentId) > $this->curYear) {
            return false;
        }

        $parentChange = false;
        $nodeChange = false;

        if ($this->nodes[$nodeId]['minBirth'] && $this->nodes[$parentId]['minBirth']) {
            if ($this->nodes[$nodeId]['minBirth'] < $this->minChildBearingYear($parentId)) {
                $this->nodes[$nodeId]['minBirth'] = $this->minChildBearingYear($parentId);
                $nodeChange = true;
            }
            if ($this->nodes[$nodeId]['maxBirth'] > $this->maxChildBearingYear($parentId)) {
                $this->nodes[$nodeId]['maxBirth'] = $this->maxChildBearingYear($parentId);
                $nodeChange = true;
            }
            $parentGender = $this->nodes[$parentId]['gender'];
            if ($this->nodes[$parentId]['minBirth'] < $this->minParentBirthYear($nodeId, $parentGender)) {
                $this->nodes[$parentId]['minBirth'] = $this->minParentBirthYear($nodeId, $parentGender);
                $parentChange = true;
            }
            if ($this->nodes[$parentId]['maxBirth'] > $this->maxParentBirthYear($nodeId)) {
                $this->nodes[$parentId]['maxBirth'] = $this->maxParentBirthYear($nodeId);
                $parentChange = true;
            }
        } elseif (! $this->nodes[$nodeId]['minBirth'] && $this->nodes[$parentId]['minBirth']) {
            $this->nodes[$nodeId]['minBirth'] = $this->minChildBearingYear($parentId);
            $this->nodes[$nodeId]['maxBirth'] = $this->maxChildBearingYear($parentId);
            $nodeChange = true;
        } elseif ($this->nodes[$nodeId]['minBirth'] && ! $this->nodes[$parentId]['minBirth']) {
            $this->nodes[$parentId]['minBirth'] = $this->minParentBirthYear($nodeId, $this->nodes[$parentId]['gender']);
            $this->nodes[$parentId]['maxBirth'] = $this->maxParentBirthYear($nodeId);
            $parentChange = true;
        }

        $this->nodes[$nodeId]['maxBirth'] = min($this->nodes[$nodeId]['maxBirth'], $this->curYear);
        $this->nodes[$parentId]['maxBirth'] = min($this->nodes[$parentId]['maxBirth'], $this->curYear);

        if ($parentChange) {
            foreach (['parent1', 'parent2'] as $slot) {
                $grandparent = $this->nodes[$parentId][$slot];
                if ($this->isActive($grandparent) && ! $this->adjustBirthYearInfo($parentId, $grandparent)) {
                    return false;
                }
            }
        }
        if ($nodeChange) {
            foreach ($this->nodes[$nodeId]['children'] as $childId) {
                if (! $this->adjustBirthYearInfo($childId, $nodeId)) {
                    return false;
                }
            }
        }

        return true;
    }

    // -------------------------------------------------------------- sameAs

    /**
     * `people.sameAs` marks identical twins and duplicate kits. It may be
     * written one way, both ways, or as a chain, so the C normalises whatever
     * it finds into equivalence classes; members that arrived without parents
     * then borrow them from a member that has some, since "same person" means
     * the same parents.
     *
     * Only the borrowing changes the layout, so that is all that is kept here
     * — plus the partner list, which the page shows as a marker.
     */
    private function resolveSameAs(): void
    {
        $byRecId = [];
        for ($id = 1; $id < $this->nodeCount; $id++) {
            $byRecId[$this->nodes[$id]['recId']] = $id;
        }

        // Union-find over every declaration, in either direction.
        $parent = range(0, $this->nodeCount - 1);
        $find = function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };

        $inClass = [];
        for ($id = 1; $id < $this->nodeCount; $id++) {
            $recId = $this->nodes[$id]['sameAsRecId'];
            if (! $recId || ! isset($byRecId[$recId]) || $byRecId[$recId] === $id) {
                continue;
            }
            $other = $byRecId[$recId];
            $inClass[$id] = true;
            $inClass[$other] = true;
            $a = $find($id);
            $b = $find($other);
            if ($a !== $b) {
                $parent[$a] = $b;
            }
        }

        $classes = [];
        foreach (array_keys($inClass) as $id) {
            $classes[$find($id)][] = $id;
        }

        foreach ($classes as $members) {
            sort($members);
            if (count($members) < 2) {
                continue;
            }
            $count = count($members);
            foreach ($members as $i => $id) {
                $this->nodes[$id]['sameAs'] = $members[($i + 1) % $count];
            }
        }

        for ($id = 1; $id < $this->nodeCount; $id++) {
            if (! $this->isActive($this->nodes[$id]['sameAs'])) {
                continue;
            }
            if ($this->isActive($this->nodes[$id]['parent1']) || $this->isActive($this->nodes[$id]['parent2'])) {
                continue;
            }
            for ($partner = $this->nodes[$id]['sameAs']; $partner !== $id; $partner = $this->nodes[$partner]['sameAs']) {
                $p1 = $this->nodes[$partner]['parent1'];
                $p2 = $this->nodes[$partner]['parent2'];
                $hasReal = ($this->isActive($p1) && $p1 !== self::EXTERNAL) || ($this->isActive($p2) && $p2 !== self::EXTERNAL);
                if (! $hasReal) {
                    continue;
                }
                if ($this->isActive($p1) && $p1 !== self::EXTERNAL) {
                    $this->setParent1($id, $p1);
                }
                if ($this->isActive($p2) && $p2 !== self::EXTERNAL) {
                    $this->setParent2($id, $p2);
                }
                break;
            }
        }
    }

    // ------------------------------------------------------------ subtrees

    /**
     * genTree_initSubTrees(). A subtree number is a connected component of the
     * whole graph — parents, children and married-in partners alike. Two roots
     * sharing one means they are the same family drawn twice, joined through
     * somebody who is not on either spine.
     */
    private function initSubTrees(): void
    {
        for ($id = 1; $id < $this->nodeCount; $id++) {
            $this->nodes[$id]['subTreeId'] = self::INACTIVE;
        }
        $this->subTreeCount = 0;

        for ($id = 1; $id < $this->nodeCount; $id++) {
            if ($this->nodes[$id]['subTreeId'] === self::INACTIVE) {
                $this->floodSubTree($id, $this->subTreeCount++);
            }
        }
        // forceJoin members are pulled out into a subtree of their own, taking
        // their descendants with them, which is how the Perl side splits a
        // line that would otherwise merge into the one above it.
        for ($id = 1; $id < $this->nodeCount; $id++) {
            if ($this->nodes[$id]['forceJoin'] === 1) {
                $this->floodSubTreeDown($id, $this->subTreeCount++);
            }
        }
    }

    /** Iterative so a 3,000-person component cannot blow the PHP stack. */
    private function floodSubTree(int $startId, int $subTreeId): void
    {
        $stack = [$startId];
        while ($stack) {
            $id = array_pop($stack);
            if ($id === self::EXTERNAL || ! isset($this->nodes[$id]) || $this->nodes[$id]['subTreeId'] !== self::INACTIVE) {
                continue;
            }
            $this->nodes[$id]['subTreeId'] = $subTreeId;
            foreach ([$this->nodes[$id]['parent1'], $this->nodes[$id]['parent2']] as $p) {
                if ($this->isActive($p)) {
                    $stack[] = $p;
                }
            }
            foreach ($this->nodes[$id]['children'] as $c) {
                $stack[] = $c;
            }
        }
    }

    private function floodSubTreeDown(int $startId, int $subTreeId): void
    {
        $stack = [$startId];
        $seen = [];
        while ($stack) {
            $id = array_pop($stack);
            if (! isset($this->nodes[$id]) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $this->nodes[$id]['subTreeId'] = $subTreeId;
            foreach ($this->nodes[$id]['children'] as $c) {
                $stack[] = $c;
            }
        }
    }

    // -------------------------------------------------------------- render

    /**
     * genTree_show(). Roots are the DNA members whose top ancestor is
     * themselves; each is walked down its children, youngest generation last.
     *
     * @return array{counts: array<string, int>, roots: array<int, array<string, mixed>>, unplaced: array<int, array<string, mixed>>, warnings: string[]}
     */
    private function render(): array
    {
        $topAncestor = [];
        for ($id = 1; $id < $this->nodeCount; $id++) {
            $topAncestor[$id] = $this->nodes[$id]['dna'] ? $this->topAncestor($id) : self::INACTIVE;
        }

        $seen = [];
        $roots = [];
        for ($id = 1; $id < $this->nodeCount; $id++) {
            if (($topAncestor[$id] ?? self::INACTIVE) === $id) {
                $roots[] = $this->showNode($id, self::INACTIVE, 0, $seen);
            }
        }

        $samples = 0;
        $dna = 0;
        for ($id = 1; $id < $this->nodeCount; $id++) {
            $dna += $this->nodes[$id]['dna'] ? 1 : 0;
            $samples += $this->nodes[$id]['dnaSampleRecId'] ? 1 : 0;
        }

        // Anyone the walk never reached and who is not drawn as somebody's
        // partner either — usually a married-in person whose own children are
        // outside the tree, sometimes a member who should not be here at all.
        $partners = [];
        for ($id = 1; $id < $this->nodeCount; $id++) {
            foreach ([$this->nodes[$id]['parent1'], $this->nodes[$id]['parent2']] as $p) {
                if ($this->isActive($p) && $p !== self::EXTERNAL) {
                    $partners[$p] = true;
                }
            }
        }
        $unplaced = [];
        for ($id = 1; $id < $this->nodeCount; $id++) {
            if (! isset($seen[$id]) && ! isset($partners[$id])) {
                $unplaced[] = $this->nodeSummary($id);
            }
        }

        return [
            'counts' => [
                'people' => $this->nodeCount - 1,
                'dna' => $dna,
                'samples' => $samples,
                'roots' => count($roots),
                'subtrees' => $this->subTreeCount,
            ],
            'roots' => $roots,
            'unplaced' => $unplaced,
            'warnings' => array_values(array_unique($this->warnings)),
        ];
    }

    /**
     * genTree_nodeTopAncestor(). Walk up the DNA spine — PARENT1 first, then
     * PARENT2 if it happens to carry DNA — and stop at the first member who
     * has no DNA parent inside the tree. forceJoin stops the walk on the spot.
     */
    private function topAncestor(int $nodeId): int
    {
        $guard = [];
        while (true) {
            if (isset($guard[$nodeId])) {
                return $nodeId;
            }
            $guard[$nodeId] = true;

            if ($this->nodes[$nodeId]['forceJoin']) {
                return $nodeId;
            }
            foreach (['parent1', 'parent2'] as $slot) {
                $p = $this->nodes[$nodeId][$slot];
                if ($p !== self::EXTERNAL && $this->isActive($p) && $this->nodes[$p]['dna']) {
                    $nodeId = $p;

                    continue 2;
                }
            }

            return $nodeId;
        }
    }

    /**
     * genTree_nodeShowR(). One row, then its children sorted by earliest
     * possible birth year.
     *
     * @param  array<int, true>  $seen  node numbers already drawn in full
     * @return array<string, mixed>
     */
    private function showNode(int $nodeId, int $upperNodeId, int $depth, array &$seen): array
    {
        $row = $this->nodeSummary($nodeId);
        $row['depth'] = $depth;
        $row['partner'] = $this->partnerOf($nodeId, $upperNodeId);

        if (isset($seen[$nodeId])) {
            // Already drawn under the other parent. One copy is enough.
            $row['repeat_of'] = $nodeId;
            $row['children'] = [];
            $row['descendants'] = 0;

            return $row;
        }
        $seen[$nodeId] = true;

        $children = $this->nodes[$nodeId]['children'];
        usort($children, fn ($a, $b) => $this->nodes[$a]['minBirth'] <=> $this->nodes[$b]['minBirth']);

        $row['children'] = [];
        $descendants = 0;
        foreach ($children as $childId) {
            $child = $this->showNode($childId, $nodeId, $depth + 1, $seen);
            $descendants += 1 + $child['descendants'];
            $row['children'][] = $child;
        }
        $row['descendants'] = $descendants;

        return $row;
    }

    /**
     * The "(P: ...)" on a print-tree row: whichever parent is *not* the one we
     * arrived from, so a row reads as "child of the line above, with this
     * person". At a root there is no line above, so it is the married-in
     * partner — or PARENT1 when forceJoin split the line here.
     *
     * @return array<string, mixed>|null
     */
    private function partnerOf(int $nodeId, int $upperNodeId): ?array
    {
        $p1 = $this->nodes[$nodeId]['parent1'];
        $p2 = $this->nodes[$nodeId]['parent2'];
        $altId = self::INACTIVE;

        if ($this->isActive($upperNodeId)) {
            if ($this->isActive($p1) && $upperNodeId !== $p1) {
                $altId = $p1;
            } elseif ($this->isActive($p2) && $upperNodeId !== $p2) {
                $altId = $p2;
            }
        } elseif ($this->nodes[$nodeId]['forceJoin'] && $this->isActive($p1)) {
            $altId = $p1;
        } elseif ($this->isActive($p2)) {
            $altId = $p2;
        }

        if (! $this->isActive($altId)) {
            return null;
        }
        if ($altId === self::EXTERNAL) {
            return ['external' => true];
        }

        return $this->nodeSummary($altId);
    }

    /** @return array<string, mixed> */
    private function nodeSummary(int $id): array
    {
        $node = $this->nodes[$id];

        return [
            'n' => $id,
            'person_id' => $node['recId'],
            'name' => $node['name'] ?? 'Unknown',
            'alt' => $node['alt'],
            'years' => $this->nodeYears($id),
            'gender' => match ($node['gender']) {
                self::GENDER_MALE => 'M',
                self::GENDER_FEMALE => 'F',
                default => '',
            },
            'dna' => (bool) $node['dna'],
            'dna_sample_id' => $node['dnaSampleRecId'] ?: null,
            'dna_name' => $node['dnaName'],
            'disabled' => (bool) $node['disable'],
            'force_join' => (bool) $node['forceJoin'],
            'ign_parents' => match ($node['ignParents']) {
                'F' => 'father',
                'M' => 'mother',
                'B' => 'both',
                default => null,
            },
            // Named, not numbered: the node number is print-tree bookkeeping
            // and does not appear on the page.
            'same_as' => $this->isActive($node['sameAs']) ? ($this->nodes[$node['sameAs']]['name'] ?? null) : null,
            'subtree' => $node['subTreeId'],
            'notes' => $node['notes'],
        ];
    }

    /** The bracketed years in a print-tree row: "1831-1892", "1961/1995-?". */
    private function nodeYears(int $id): string
    {
        $min = $this->nodes[$id]['minBirth'];
        $max = $this->nodes[$id]['maxBirth'];
        $death = $this->nodes[$id]['death'];

        if ($min === $max || $min === 0 || $max === 0) {
            $birth = $min ?: $max;
            $years = $birth ? (string) $birth : '?';
        } else {
            $years = $min.'/'.$max;
        }

        return $years.'-'.($death ? (string) $death : '?');
    }

    private function isActive(int $id): bool
    {
        return $id >= 0;
    }

    /** How a person is named in the warnings. */
    private function nodeName(int $id): string
    {
        if ($id === self::EXTERNAL) {
            return 'someone outside this tree';
        }
        if (! isset($this->nodes[$id])) {
            return '';
        }
        $name = $this->nodes[$id]['name'] ?? 'Unknown';
        if ($this->nodes[$id]['alt']) {
            $name .= ':'.$this->nodes[$id]['alt'];
        }

        return sprintf('%s (%s)', $name, $this->nodeYears($id));
    }
}
