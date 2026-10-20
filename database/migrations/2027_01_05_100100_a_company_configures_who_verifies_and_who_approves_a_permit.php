<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v2.127.0 -- A PERMIT HAS TWO GATES, AND EACH COMPANY DECIDES WHO HOLDS THEM.
 *
 * Until now PTW had ONE approval gate: `canManageHse()` wrote
 * `hse_approver_id` and the permit went straight from submitted to
 * approved. ADR 065 recorded that as a fact and refused to invent a second
 * stage to make a stamp design fit -- correctly, because inventing
 * authority to satisfy a picture is backwards.
 *
 * The owner has now specified the second stage as a product requirement:
 * HSE VERIFIES, and somebody else APPROVES. That is a different thing from
 * inventing it, and it is what this table configures.
 *
 * WHY A TABLE AND NOT `company_settings`. `CompanySetting` is keyed by
 * TENANT, and a permit belongs to an operating unit (`company_id`). A
 * tenant running three yards may route a hot-work permit to a different
 * SPV HSE in each of them, and a tenant-keyed store cannot express that.
 * The row is keyed by company, which gives per-company configuration and
 * tenant isolation together -- a company already belongs to exactly one
 * tenant, and `CompanyOwnedScope` enforces it on every read.
 *
 * WHY ROLES **AND** USERS, per stage. The brief asks for both ("jabatan/
 * role yang bertanggung jawab ... serta pengguna atau penerima notifikasi
 * yang berwenang"), and they answer different questions. A ROLE is a
 * standing rule that survives staff turnover. A NAMED USER is who actually
 * gets told. A company may use either, or both; the union is the authority
 * and the union is the notification list, so the person who is asked to
 * act is always somebody who may act. That is ADR 061's rule -- an offer
 * is bound to what the server allows -- applied to a notification.
 *
 * NULL IS NOT EMPTY. A company that has configured nothing keeps the
 * behaviour it had before this release: verification falls back to
 * `canManageHse()` and approval to `config('workflow.approvers')`. An
 * empty configuration would mean "nobody may approve anything", which
 * would silently stop every yard that has not visited Settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ptw_workflow_settings', function (Blueprint $table) {
            $table->id();

            // One row per operating unit. Unique, because two rows would
            // be two answers to one question and nothing would say which
            // one is in force.
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();

            /*
             * Null means "not configured, use the default". An empty array
             * means the company deliberately named no roles -- which is
             * legal only when it named users instead, and the service
             * refuses to leave a stage with no holder at all.
             */
            $table->json('verify_roles')->nullable();
            $table->json('verify_user_ids')->nullable();
            $table->json('approve_roles')->nullable();
            $table->json('approve_user_ids')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ptw_workflow_settings');
    }
};
