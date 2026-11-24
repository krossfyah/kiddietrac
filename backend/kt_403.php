<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

$rows = DB::table('audit_logs')->where('action','like','get:api/v1/parent/documents%')
    ->orderByDesc('created_at')->limit(200)->get(['created_at','user_id','payload']);
echo "parent/documents audit rows (last 200): ".$rows->count()."\n\n";

$byUser = [];
foreach ($rows as $r) { $byUser[(int)$r->user_id][] = $r->created_at; }

echo "who, and are they actually a guardian?\n";
foreach ($byUser as $uid => $times) {
    $u = DB::table('users')->where('id',$uid)->first(['first_name','last_name','email']);
    $roles = DB::table('role_assignments')->where('user_id',$uid)->where('active',1)->pluck('role')->unique()->implode(',');
    $guardRows = DB::table('guardians')->where('user_id',$uid)->count();
    $kids = DB::table('guardians as g')->join('children as c','c.family_id','=','g.family_id')
        ->where('g.user_id',$uid)->whereNull('c.deleted_at')->count();
    printf("  #%-4d %-22s roles=[%-28s] guardian_rows=%d children=%d   %d hits, last %s\n",
        $uid, trim(($u->first_name ?? '').' '.($u->last_name ?? '')), $roles, $guardRows, $kids,
        count($times), substr(end($times),0,19));
}
echo "\ntotal 403s vs 200s on that path (last 200 rows):\n";
$f = $rows->filter(fn($r)=>str_contains((string)$r->payload,'"status":403'))->count();
echo "  403: $f    other: ".($rows->count()-$f)."\n";
