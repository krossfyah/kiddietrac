<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sales CRM (platform-level) + `sales_rep` role.
 * Leads for selling KiddieTrac to prospective agencies — owned by sales reps /
 * platform admins, NOT agency-scoped tenant data. Pipeline stages, activities +
 * follow-up tasks, quotes/proposals (preset plans + free-form lines).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Extend the role enum (keep ALL existing values; append sales_rep).
        try {
            DB::statement("ALTER TABLE role_assignments MODIFY COLUMN role ENUM('agency_admin','centre_director','educator','guardian','auditor','platform_admin','home_visitor','sales_rep') NOT NULL");
        } catch (\Throwable $e) {
            // enum already includes sales_rep, or column differs — non-fatal
        }

        if (! Schema::hasTable('sales_leads')) {
            Schema::create('sales_leads', function (Blueprint $t) {
                $t->id();
                $t->string('name');                          // primary contact
                $t->string('company')->nullable();           // prospect org / childcare
                $t->string('email')->nullable();
                $t->string('phone')->nullable();
                $t->string('title')->nullable();
                $t->string('source')->default('manual');     // manual | marketing-site | referral
                $t->string('stage')->default('new');         // new|contacted|qualified|proposal|negotiation|won|lost
                $t->string('status')->default('open');       // open | won | lost
                $t->unsignedBigInteger('owner_id')->nullable(); // users.id (assigned sales rep)
                $t->decimal('value', 12, 2)->nullable();     // estimated deal value
                $t->date('expected_close')->nullable();
                $t->date('follow_up_date')->nullable();      // quick next-follow-up (mirrors open task)
                $t->text('notes')->nullable();
                $t->string('lost_reason')->nullable();
                $t->timestamp('last_activity_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['stage', 'status']);
                $t->index('owner_id');
            });
        }

        if (! Schema::hasTable('sales_activities')) {
            Schema::create('sales_activities', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('lead_id');
                $t->unsignedBigInteger('user_id')->nullable(); // who logged it
                $t->string('type')->default('note');           // note|call|email|meeting|stage|followup
                $t->text('body')->nullable();
                $t->date('due_date')->nullable();              // for followup tasks
                $t->boolean('done')->default(false);
                $t->timestamp('done_at')->nullable();
                $t->boolean('reminded')->default(false);       // follow-up cron guard
                $t->timestamps();
                $t->index('lead_id');
                $t->index(['type', 'done', 'due_date']);
            });
        }

        if (! Schema::hasTable('sales_products')) {
            Schema::create('sales_products', function (Blueprint $t) {
                $t->id();
                $t->string('name');
                $t->text('description')->nullable();
                $t->decimal('price', 12, 2)->default(0);
                $t->string('unit')->default('month');          // month | year | one-time
                $t->boolean('active')->default(true);
                $t->integer('sort')->default(0);
                $t->timestamps();
            });
            // Editable placeholder plans — superadmin should set real pricing.
            $now = now();
            DB::table('sales_products')->insert([
                ['name' => 'Starter',            'description' => 'Single centre — attendance, billing & parent records',        'price' => 49.00,  'unit' => 'month',    'active' => 1, 'sort' => 1, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Growth',             'description' => 'Up to 3 centres — parent app, messaging & photos',            'price' => 129.00, 'unit' => 'month',    'active' => 1, 'sort' => 2, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Agency',             'description' => 'Multi-centre agency — all features + priority support',        'price' => 299.00, 'unit' => 'month',    'active' => 1, 'sort' => 3, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Setup & onboarding', 'description' => 'One-time implementation, training & data import',             'price' => 500.00, 'unit' => 'one-time', 'active' => 1, 'sort' => 4, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        if (! Schema::hasTable('sales_quotes')) {
            Schema::create('sales_quotes', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('lead_id');
                $t->string('number')->nullable();              // KT-Q-000001
                $t->string('title')->nullable();
                $t->string('status')->default('draft');        // draft | sent | accepted | declined
                $t->decimal('subtotal', 12, 2)->default(0);
                $t->decimal('discount', 12, 2)->default(0);
                $t->decimal('total', 12, 2)->default(0);
                $t->string('billing_period')->nullable();      // monthly | annual
                $t->date('valid_until')->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index('lead_id');
            });
        }

        if (! Schema::hasTable('sales_quote_items')) {
            Schema::create('sales_quote_items', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('quote_id');
                $t->unsignedBigInteger('product_id')->nullable();
                $t->string('description');
                $t->decimal('qty', 10, 2)->default(1);
                $t->decimal('unit_price', 12, 2)->default(0);
                $t->decimal('line_total', 12, 2)->default(0);
                $t->integer('sort')->default(0);
                $t->timestamps();
                $t->index('quote_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quote_items');
        Schema::dropIfExists('sales_quotes');
        Schema::dropIfExists('sales_products');
        Schema::dropIfExists('sales_activities');
        Schema::dropIfExists('sales_leads');
        // enum left intact (dropping a value could orphan assignments)
    }
};
