<?php
require __DIR__.'/vendor/autoload.php'; $a=require __DIR__.'/bootstrap/app.php';
$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
foreach (DB::table('support_tickets')->where('status','open')->orderByDesc('id')->limit(2)->get() as $t) {
    echo "===== #{$t->id} ({$t->status}) {$t->created_at} =====\n{$t->subject}\n\n{$t->body}\n\n";
}
printf("open: %s\n", DB::table('support_tickets')->where('status','open')->orderBy('id')->pluck('id')->implode(', '));
