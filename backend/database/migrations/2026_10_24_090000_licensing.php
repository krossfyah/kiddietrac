<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* LICENSING (2026-10-24, App\Support\Licences). Part numbers on every plan, add-on, service and
   discount; a licence per agency per plan/add-on with a term, renewal and expiry; invoice lines
   so renewals and part numbers reach the platform invoice; the part number kept on each
   proposal line. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_products', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_products', 'part_number')) $t->string('part_number', 20)->nullable()->unique();
            if (! Schema::hasColumn('sales_products', 'plan_code')) $t->string('plan_code', 20)->nullable();      // starter|growth|enterprise for a plan
            if (! Schema::hasColumn('sales_products', 'feature_keys')) $t->text('feature_keys')->nullable();       // JSON, what an add-on switches on
        });
        // kind becomes plan | addon | service | discount (was plan | discount)
        try { DB::statement("ALTER TABLE sales_products MODIFY kind VARCHAR(12) NOT NULL DEFAULT 'plan'"); } catch (\Throwable $e) {}

        /* The existing catalogue, sorted into plans / add-ons / services / discounts and numbered.
           Plans carry the feature level they unlock; add-ons the features they switch on. */
        // Add-ons first: "Reseller (Agency Bundle)" must not be read as the Agency plan.
        $known = [
            'reseller' => ['addon', null, ['reseller']], 'whitelabel' => ['addon', null, ['white_label']], 'white label' => ['addon', null, ['white_label']],
            'sms' => ['addon', null, ['sms']],
            'starter' => ['plan', 'starter', null], 'growth' => ['plan', 'growth', null], 'agency' => ['plan', 'enterprise', null],
        ];
        foreach (DB::table('sales_products')->orderBy('id')->get() as $p) {
            if (! empty($p->part_number)) continue;
            $n = mb_strtolower($p->name);
            $kind = $p->kind === 'discount' ? 'discount' : ($p->unit === 'one-time' ? 'service' : 'plan');
            $planCode = null; $features = null;
            foreach ($known as $word => [$k, $pc, $fk]) {
                if (str_contains($n, $word)) { $kind = $p->kind === 'discount' ? 'discount' : ($p->unit === 'one-time' ? 'service' : $k); $planCode = $pc; $features = $fk; break; }
            }
            $prefix = ['plan' => 'KT-PLN', 'addon' => 'KT-ADD', 'service' => 'KT-SVC', 'discount' => 'KT-DSC'][$kind];
            $last = DB::table('sales_products')->where('part_number', 'like', $prefix . '-%')->orderByDesc('part_number')->value('part_number');
            $next = $last ? ((int) substr($last, strlen($prefix) + 1)) + 1 : 1;
            DB::table('sales_products')->where('id', $p->id)->update([
                'kind' => $kind, 'part_number' => $prefix . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT),
                'plan_code' => $kind === 'plan' ? $planCode : null, 'feature_keys' => $features ? json_encode($features) : null,
            ]);
        }

        Schema::table('sales_quote_items', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_quote_items', 'part_number')) $t->string('part_number', 20)->nullable();
        });
        // Existing proposal lines take their product's number.
        try {
            DB::statement('UPDATE sales_quote_items i JOIN sales_products p ON p.id = i.product_id SET i.part_number = p.part_number WHERE i.part_number IS NULL');
        } catch (\Throwable $e) {}

        if (! Schema::hasTable('agency_licences')) {
            Schema::create('agency_licences', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('product_id')->nullable();
                $t->string('part_number', 20)->nullable()->index();
                $t->string('name', 160);
                $t->string('kind', 12);                         // plan | addon
                $t->string('plan_code', 20)->nullable();
                $t->text('feature_keys')->nullable();           // JSON
                $t->decimal('qty', 10, 2)->default(1);
                $t->unsignedInteger('unit_price_cents')->default(0);
                $t->string('currency', 3)->default('CAD');
                $t->string('interval', 12)->default('monthly');  // monthly | annual | quarterly
                $t->date('starts_at');
                $t->date('expires_at')->nullable();
                $t->boolean('auto_renew')->default(true);
                $t->string('status', 12)->default('active')->index();   // active | grace | expired | cancelled
                $t->date('grace_until')->nullable();
                $t->string('source', 12)->default('manual');    // quote | manual
                $t->unsignedBigInteger('quote_id')->nullable();
                $t->unsignedBigInteger('last_invoice_id')->nullable();
                $t->timestamp('last_renewed_at')->nullable();
                $t->string('reminded_for', 24)->nullable();   // 'expiry:days' of the last reminder sent
                $t->string('notes', 500)->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('platform_invoice_lines')) {
            Schema::create('platform_invoice_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('invoice_id')->index();
                $t->unsignedBigInteger('licence_id')->nullable();
                $t->string('part_number', 20)->nullable();
                $t->string('description', 255);
                $t->decimal('qty', 10, 2)->default(1);
                $t->integer('unit_cents')->default(0);
                $t->integer('amount_cents')->default(0);
                $t->date('period_start')->nullable();
                $t->date('period_end')->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoice_lines');
        Schema::dropIfExists('agency_licences');
        Schema::table('sales_quote_items', fn (Blueprint $t) => $t->dropColumn('part_number'));
        Schema::table('sales_products', fn (Blueprint $t) => $t->dropColumn(['part_number', 'plan_code', 'feature_keys']));
    }
};
