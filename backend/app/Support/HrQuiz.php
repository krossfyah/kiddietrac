<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 6 (2026-11-27): COURSE QUIZZES. Tables hr_course_questions, hr_course_attempts;
 * hr_courses.pass_mark / max_attempts; hr_course_assignments.extra_attempts.
 *
 * A course with questions AND a pass mark has a quiz: the person can no longer just mark it complete; they finish it by
 * passing the quiz, and only then is the certificate issued (HrTalent::completeCourse, the one completion path).
 * Each try is kept (answers, score). Tries are unlimited unless the course sets a limit; a manager can allow one more.
 * The right answers never leave the server for the person taking it, and a result says how many were right, not which,
 * so a retry is a real retry.
 */
final class HrQuiz
{
    public const MAX_QUESTIONS = 50;

    public static function ready(): bool
    {
        static $ok = null;
        if ($ok === null) $ok = rescue(fn () => Schema::hasTable('hr_course_questions') && Schema::hasColumn('hr_courses', 'pass_mark'), false, false);

        return $ok;
    }

    /** Does this course have a quiz (questions and a pass mark)? */
    public static function has(object $course): bool
    {
        return self::ready() && ! empty($course->pass_mark)
            && DB::table('hr_course_questions')->where('course_id', $course->id)->exists();
    }

    /** question counts per course id. @return array<int,int> */
    public static function counts(array $courseIds): array
    {
        if (! self::ready() || ! $courseIds) return [];

        return DB::table('hr_course_questions')->whereIn('course_id', $courseIds)->groupBy('course_id')
            ->selectRaw('course_id, COUNT(*) n')->pluck('n', 'course_id')->map(fn ($v) => (int) $v)->all();
    }

    /** The full quiz for its editor (admins): with the right answers. */
    public static function forEditor(object $course): array
    {
        $qs = self::ready() ? DB::table('hr_course_questions')->where('course_id', $course->id)->orderBy('position')->orderBy('id')->get() : collect();

        return ['pass_mark' => $course->pass_mark !== null ? (int) $course->pass_mark : null, 'max_attempts' => $course->max_attempts !== null ? (int) $course->max_attempts : null,
            'questions' => $qs->map(fn ($q) => ['id' => (int) $q->id, 'prompt' => $q->prompt, 'kind' => $q->kind,
                'options' => json_decode((string) $q->options, true) ?: [], 'correct' => json_decode((string) $q->correct, true) ?: []])->values()->all()];
    }

    /**
     * Replace the quiz. $questions: [{prompt, kind single|multi, options[], correct[] (option indexes)}]. No questions
     * removes the quiz (back to "mark complete"). Earlier tries and certificates stay as they were.
     */
    public static function save(int $agencyId, object $course, ?int $passMark, ?int $maxAttempts, array $questions): array
    {
        abort_if(count($questions) > self::MAX_QUESTIONS, 422, 'A quiz can have up to ' . self::MAX_QUESTIONS . ' questions.');
        $rows = [];
        foreach (array_values($questions) as $i => $q) {
            $n = $i + 1;
            $prompt = trim((string) ($q['prompt'] ?? ''));
            abort_if(mb_strlen($prompt) < 3, 422, 'Question ' . $n . ' needs its wording.');
            abort_if(mb_strlen($prompt) > 600, 422, 'Question ' . $n . ' is too long.');
            $kind = ($q['kind'] ?? 'single') === 'multi' ? 'multi' : 'single';
            $opts = array_values(array_filter(array_map(fn ($o) => mb_substr(trim((string) $o), 0, 240), (array) ($q['options'] ?? [])), fn ($o) => $o !== ''));
            abort_if(count($opts) < 2, 422, 'Question ' . $n . ' needs at least two answers to choose from.');
            abort_if(count($opts) > 8, 422, 'Question ' . $n . ' can have up to 8 answers.');
            $right = array_values(array_unique(array_filter(array_map('intval', (array) ($q['correct'] ?? [])), fn ($x) => $x >= 0 && $x < count($opts))));
            sort($right);
            abort_if(! $right, 422, 'Tick the right answer for question ' . $n . '.');
            abort_if($kind === 'single' && count($right) !== 1, 422, 'Question ' . $n . ' has one right answer: tick just one, or let them choose several.');
            $rows[] = ['agency_id' => $agencyId, 'course_id' => (int) $course->id, 'position' => $n, 'prompt' => $prompt, 'kind' => $kind,
                'options' => json_encode($opts, JSON_UNESCAPED_UNICODE), 'correct' => json_encode($right), 'created_at' => now(), 'updated_at' => now()];
        }
        if ($rows) {
            abort_if($passMark === null || $passMark < 1 || $passMark > 100, 422, 'Set the pass mark (1 to 100%).');
            abort_if($maxAttempts !== null && ($maxAttempts < 1 || $maxAttempts > 20), 422, 'Tries can be 1 to 20, or no limit.');
        }
        DB::transaction(function () use ($course, $rows, $passMark, $maxAttempts) {
            DB::table('hr_course_questions')->where('course_id', $course->id)->delete();
            if ($rows) DB::table('hr_course_questions')->insert($rows);
            DB::table('hr_courses')->where('id', $course->id)->update(['pass_mark' => $rows ? $passMark : null,
                'max_attempts' => $rows ? $maxAttempts : null, 'updated_at' => now()]);
        });

        return ['questions' => count($rows), 'pass_mark' => $rows ? $passMark : null];
    }

    /** How many tries this assignment may have in all (null = no limit). */
    public static function allowed(object $course, object $a): ?int
    {
        return $course->max_attempts !== null ? (int) $course->max_attempts + (int) ($a->extra_attempts ?? 0) : null;
    }

    public static function used(int $assignmentId): int
    {
        return self::ready() ? (int) DB::table('hr_course_attempts')->where('assignment_id', $assignmentId)->count() : 0;
    }

    /** Tries per assignment id: [n, best score, passed]. @return array<int,array> */
    public static function triesFor(array $assignmentIds): array
    {
        if (! self::ready() || ! $assignmentIds) return [];
        $out = [];
        foreach (DB::table('hr_course_attempts')->whereIn('assignment_id', $assignmentIds)->groupBy('assignment_id')
            ->selectRaw('assignment_id, COUNT(*) n, MAX(score) best, MAX(passed) passed')->get() as $r) {
            $out[(int) $r->assignment_id] = ['n' => (int) $r->n, 'best' => (int) $r->best, 'passed' => (bool) $r->passed];
        }

        return $out;
    }

    /** What the person taking it sees: the questions without the answers, and where they stand. */
    public static function forTaker(object $course, object $a): array
    {
        if (! self::has($course)) return ['has_quiz' => false];
        $qs = DB::table('hr_course_questions')->where('course_id', $course->id)->orderBy('position')->orderBy('id')->get(['id', 'prompt', 'kind', 'options']);
        $used = self::used((int) $a->id);
        $allowed = self::allowed($course, $a);
        $last = DB::table('hr_course_attempts')->where('assignment_id', $a->id)->orderByDesc('id')->first(['score', 'passed', 'created_at']);

        return ['has_quiz' => true, 'pass_mark' => (int) $course->pass_mark, 'tries_used' => $used, 'tries_allowed' => $allowed,
            'can_try' => ! $a->completed_at && ($allowed === null || $used < $allowed),
            'last' => $last ? ['score' => (int) $last->score, 'passed' => (bool) $last->passed, 'at' => $last->created_at] : null,
            'questions' => $qs->map(fn ($q) => ['id' => (int) $q->id, 'prompt' => $q->prompt, 'kind' => $q->kind,
                'options' => json_decode((string) $q->options, true) ?: []])->values()->all()];
    }

    /**
     * One try. $answers: question id => option indexes. Refused when the course is done or no tries are left. A pass
     * completes the course (certificate) in the same step. Exact match per question; score = right / questions.
     */
    public static function submit(int $agencyId, object $course, object $a, array $answers, ?string $ackName, int $userId): array
    {
        abort_unless(self::has($course), 422, 'This course has no quiz.');
        $qs = DB::table('hr_course_questions')->where('course_id', $course->id)->orderBy('position')->get(['id', 'correct']);
        $res = DB::transaction(function () use ($agencyId, $course, $a, $answers, $qs, $userId) {
            $fresh = DB::table('hr_course_assignments')->where('id', $a->id)->lockForUpdate()->first();
            abort_if($fresh->completed_at, 422, 'You have already finished this course.');
            $allowed = self::allowed($course, $fresh);
            $used = self::used((int) $a->id);
            abort_if($allowed !== null && $used >= $allowed, 422, 'You have used all your tries. Ask your manager if you can have another.');
            $right = 0;
            $given = [];
            foreach ($qs as $q) {
                $want = array_map('intval', json_decode((string) $q->correct, true) ?: []);
                sort($want);
                $got = array_values(array_unique(array_map('intval', (array) ($answers[$q->id] ?? $answers[(string) $q->id] ?? []))));
                sort($got);
                $given[(int) $q->id] = $got;
                if ($got === $want) $right++;
            }
            $total = $qs->count();
            $score = $total ? (int) floor($right * 100 / $total) : 0;
            $passed = $score >= (int) $course->pass_mark;
            DB::table('hr_course_attempts')->insert(['agency_id' => $agencyId, 'assignment_id' => (int) $a->id, 'course_id' => (int) $course->id,
                'user_id' => $userId, 'answers' => json_encode($given), 'right' => $right, 'total' => $total, 'score' => $score, 'passed' => $passed, 'created_at' => now()]);

            return ['passed' => $passed, 'score' => $score, 'right' => $right, 'total' => $total, 'pass_mark' => (int) $course->pass_mark,
                'tries_left' => $allowed === null ? null : max(0, $allowed - $used - 1)];
        });
        if ($res['passed']) {
            $res += HrTalent::completeCourse($agencyId, $a, $ackName, $userId);
        }

        return $res;
    }
}
