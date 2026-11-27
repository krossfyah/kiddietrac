<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Signature stamp (2026-11-27): every signature records the signer's IP address and the approximate location
 * resolved from it, at signing time (App\Support\SignatureStamp). New nullable columns only — nothing existing is
 * changed. NULL location = signed before this existed; '' = recorded but the address could not be placed.
 */
return new class extends Migration
{
    /** table => [column => after-column|null]; ip columns are varchar(45), location columns varchar(190). */
    private const COLS = [
        'managed_form_signoffs'   => ['signer_location' => 'ip_address', 'countersigner_ip' => 'countersigner_role', 'countersigner_location' => 'countersigner_ip'],
        'signed_documents'        => ['signer_location' => 'ip_address'],
        'document_workflow_steps' => ['signer_location' => 'signer_ip'],
        'edocument_signatures'    => ['signer_location' => 'ip_address'],
        'field_trip_permissions'  => ['signer_ip' => 'signature_data', 'signer_location' => 'signer_ip'],
        'hr_reviews'              => ['employee_signed_ip' => 'employee_signed_name', 'employee_signed_location' => 'employee_signed_ip',
                                      'manager_signed_ip' => 'manager_signed_name', 'manager_signed_location' => 'manager_signed_ip'],
        'hr_course_assignments'   => ['acknowledged_ip' => 'acknowledged_name', 'acknowledged_location' => 'acknowledged_ip'],
        'incidents'               => ['recorder_signature_location' => 'recorder_signature_ip', 'closer_signature_location' => 'closer_signature_ip'],
        'incident_acknowledgments'=> ['signer_location' => 'ip_address'],
        'medications'             => ['parent_signed_ip' => 'parent_signature_data', 'parent_signed_location' => 'parent_signed_ip'],
        'policy_acknowledgements' => ['location' => 'ip'],
        'prospect_ndas'           => ['signer_location' => 'ip_address'],
        'report_cards'            => ['educator_signed_ip' => 'admin_signature', 'educator_signed_location' => 'educator_signed_ip',
                                      'admin_signed_ip' => 'educator_signed_location', 'admin_signed_location' => 'admin_signed_ip'],
        'sales_quotes'            => ['client_sign_location' => 'client_sign_ip', 'countersign_location' => 'countersign_ip'],
        'roa_months'              => ['signed_off_ip' => 'signed_off_at', 'signed_off_location' => 'signed_off_ip'],
        'payment_refunds'         => ['approver_signature_location' => 'approver_signature_ip'],
    ];

    public function up(): void
    {
        foreach (self::COLS as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($cols as $col => $after) {
                if (Schema::hasColumn($table, $col)) {
                    continue;
                }
                Schema::table($table, function (Blueprint $t) use ($table, $col, $after) {
                    $len = str_ends_with($col, '_ip') || $col === 'ip' ? 45 : 190;
                    $c = $t->string($col, $len)->nullable();
                    if ($after && Schema::hasColumn($table, $after)) {
                        $c->after($after);
                    }
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLS as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (array_keys($cols) as $col) {
                if (Schema::hasColumn($table, $col)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($col));
                }
            }
        }
    }
};
