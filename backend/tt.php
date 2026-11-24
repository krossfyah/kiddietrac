<?php
require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
foreach (DB::table('support_tickets')->where('category','documentation')->get() as $t) {
  printf("ticket #%d [%s] pri=%s status=%s agency=%s\n  subject: %s\n  body: %s\n",
    $t->id, $t->category, $t->priority, $t->status, $t->agency_id, $t->subject, str_replace("\n",' | ',$t->body));
  foreach (DB::table('support_ticket_messages')->where('ticket_id',$t->id)->get() as $m)
    printf("    msg u%s: %s\n", $m->user_id, $m->body);
}
echo "\ntotal documentation tickets: ".DB::table('support_tickets')->where('category','documentation')->count()."\n";
echo "notifications raised: ".DB::table('notifications')->where('type','support_ticket')->where('created_at','>=',now()->subMinutes(5))->count()."\n";
