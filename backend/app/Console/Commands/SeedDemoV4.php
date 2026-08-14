<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[AsCommand(
    name: 'kiddietrac:seed-v4-extras',
    description: 'v4: clock in demo educators, seed photos/messages/observations for richer demo',
)]
final class SeedDemoV4 extends Command
{
    protected $signature = 'kiddietrac:seed-v4-extras
                            {--reset : Wipe v4 demo extras first}';

    public function handle(): int
    {
        if ($this->option('reset')) {
            $this->warn('Resetting v4 demo extras...');
            DB::table('observations')->where('title', 'LIKE', '[Demo]%')->delete();
            DB::table('messages')->delete();
            DB::table('conversations')->delete();
            DB::table('media')->where('caption', 'LIKE', '[Demo]%')->delete();
            DB::table('ai_daily_digests')->delete();
        }

        DB::transaction(function (): void {
            $this->info('Clocking in demo educators...');
            $this->clockInEducators();

            $this->info('Seeding demo photos...');
            $this->seedPhotos();

            $this->info('Seeding demo messages...');
            $this->seedMessages();

            $this->info('Seeding demo observations...');
            $this->seedObservations();
        });

        $this->newLine();
        $this->info('✓ v4 demo extras seeded.');
        $this->line('  - Director dashboard now shows non-zero "Staff on floor"');
        $this->line('  - Each demo parent has photos & a conversation thread');
        $this->line('  - Children have learning observations attached');

        return self::SUCCESS;
    }

    private function clockInEducators(): void
    {
        $educators = DB::table('users')
            ->join('role_assignments', 'role_assignments.user_id', '=', 'users.id')
            ->whereIn('users.email', ['educator@kiddietrac.com', 'educator2@kiddietrac.com'])
            ->where('role_assignments.role', 'educator')
            ->where('role_assignments.active', true)
            ->select('users.id', 'users.first_name', 'role_assignments.centre_id')
            ->get();

        foreach ($educators as $ed) {
            // Skip if already clocked in today
            $existing = DB::table('time_punches')
                ->where('user_id', $ed->id)
                ->whereDate('punched_in_at', now())
                ->whereNull('punched_out_at')
                ->exists();

            if ($existing) {
                $this->line("  · {$ed->first_name} already clocked in");
                continue;
            }

            DB::table('time_punches')->insert([
                'user_id' => $ed->id,
                'centre_id' => $ed->centre_id,
                'punched_in_at' => now()->setTime(7, 30),
                'source' => 'kiosk',
                'created_at' => now(),
            ]);
            $this->line("  · Clocked in {$ed->first_name}");
        }
    }

    private function seedPhotos(): void
    {
        $educator = DB::table('users')->where('email', 'educator@kiddietrac.com')->first();
        if (!$educator) return;

        $children = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->where('families.family_name', 'LIKE', '[Demo]%')
            ->select('children.*')
            ->get();

        $samplePhotos = [
            ['caption' => '[Demo] Block tower we built together!', 'hours_ago' => 2],
            ['caption' => '[Demo] Outdoor play in the sandbox', 'hours_ago' => 4],
            ['caption' => '[Demo] Story time circle', 'hours_ago' => 6],
        ];

        foreach ($children as $child) {
            foreach ($samplePhotos as $idx => $photo) {
                $exists = DB::table('media')
                    ->where('child_id', $child->id)
                    ->where('caption', $photo['caption'])
                    ->exists();
                if ($exists) continue;

                // Use a placeholder image URL — in production this would be a real upload
                $placeholderUrl = "https://placehold.co/800x600/8EC73C/FFFFFF/png?text=" . urlencode("Photo {$idx}");

                DB::table('media')->insert([
                    'child_id' => $child->id,
                    'media_type' => 'photo',
                    'file_url' => $placeholderUrl,
                    'storage_path' => null,
                    'caption' => $photo['caption'],
                    'taken_at' => now()->subHours($photo['hours_ago']),
                    'uploaded_by_id' => $educator->id,
                    'mime_type' => 'image/png',
                    'size_bytes' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function seedMessages(): void
    {
        $parent = DB::table('users')->where('email', 'parent@kiddietrac.com')->first();
        $educator = DB::table('users')->where('email', 'educator@kiddietrac.com')->first();
        $aria = DB::table('children')->where('first_name', 'Aria')->first();
        if (!$parent || !$educator || !$aria) return;

        $enrollment = DB::table('enrollments')
            ->where('child_id', $aria->id)
            ->whereNull('end_date')
            ->first();
        if (!$enrollment) return;

        // Get or create conversation
        $convo = DB::table('conversations')
            ->where('parent_id', $parent->id)
            ->where('room_id', $enrollment->room_id)
            ->where('child_id', $aria->id)
            ->first();

        if (!$convo) {
            $convoId = DB::table('conversations')->insertGetId([
                'parent_id' => $parent->id,
                'room_id' => $enrollment->room_id,
                'child_id' => $aria->id,
                'last_activity_at' => now(),
                'created_at' => now()->subDays(2),
                'updated_at' => now(),
            ]);
        } else {
            $convoId = $convo->id;
        }

        // Skip if messages already exist
        if (DB::table('messages')->where('conversation_id', $convoId)->exists()) {
            return;
        }

        $messages = [
            ['from' => $parent->id, 'body' => 'Hi! Aria had a runny nose last night. She should be fine but please let me know if it gets worse.', 'sent_ago' => 'subHours:6'],
            ['from' => $educator->id, 'body' => "Thanks for the heads up! She's doing great today — no signs of a cold. We'll keep an eye on her.", 'sent_ago' => 'subHours:5'],
            ['from' => $parent->id, 'body' => 'Wonderful, thank you!', 'sent_ago' => 'subHours:5'],
            ['from' => $educator->id, 'body' => "Aria did so well with story time today — she actually raised her hand to ask a question about the book! Such growth.", 'sent_ago' => 'subHours:2'],
        ];

        foreach ($messages as $i => $m) {
            [$method, $val] = explode(':', $m['sent_ago']);
            $sentAt = now()->{$method}((int) $val)->addMinutes($i * 3);

            DB::table('messages')->insert([
                'conversation_id' => $convoId,
                'sender_id' => $m['from'],
                'body' => $m['body'],
                'sent_at' => $sentAt,
                'read_at' => $m['from'] === $educator->id ? null : $sentAt->copy()->addMinutes(5),
                'created_at' => $sentAt,
                'updated_at' => $sentAt,
            ]);
        }

        DB::table('conversations')->where('id', $convoId)->update([
            'last_activity_at' => now()->subHours(2),
        ]);
    }

    private function seedObservations(): void
    {
        $educator = DB::table('users')->where('email', 'educator@kiddietrac.com')->first();
        if (!$educator) return;

        $samples = [
            [
                'child_first' => 'Aria',
                'domain' => 'language_literacy',
                'title' => '[Demo] Asked thoughtful question during story time',
                'body' => 'During our reading of "The Tiger Who Came to Tea", Aria raised her hand and asked "Why does the tiger drink all the tea?" She was making predictions about the story and connecting the tiger\'s actions to her own experience of being hungry. Wonderful early reasoning.',
                'hours_ago' => 3,
            ],
            [
                'child_first' => 'Aria',
                'domain' => 'social_emotional',
                'title' => '[Demo] Helped a friend who was upset',
                'body' => 'When Oliver was crying about a broken block tower, Aria walked over, patted his back and said "It\'s okay, we can build it again." She then sat with him and helped rebuild. Beautiful empathy.',
                'hours_ago' => 24,
            ],
            [
                'child_first' => 'Oliver',
                'domain' => 'physical',
                'title' => '[Demo] Mastered the climbing ladder',
                'body' => 'Oliver made it all the way to the top of the climber today for the first time without help. He looked nervous halfway up but kept going. Big confidence boost.',
                'hours_ago' => 5,
            ],
            [
                'child_first' => 'Finn',
                'domain' => 'creative_arts',
                'title' => '[Demo] Painted a sun with three colors',
                'body' => 'Finn experimented with mixing yellow and red paint on his sun painting. He noticed it turned orange and got very excited. Spent 20 minutes at the easel.',
                'hours_ago' => 7,
            ],
        ];

        foreach ($samples as $s) {
            $child = DB::table('children')
                ->where('first_name', $s['child_first'])
                ->first();
            if (!$child) continue;

            $exists = DB::table('observations')
                ->where('child_id', $child->id)
                ->where('title', $s['title'])
                ->exists();
            if ($exists) continue;

            DB::table('observations')->insert([
                'child_id' => $child->id,
                'domain' => $s['domain'],
                'title' => $s['title'],
                'body' => $s['body'],
                'observed_at' => now()->subHours($s['hours_ago']),
                'recorded_by_id' => $educator->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
