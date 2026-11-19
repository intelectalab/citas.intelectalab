<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('waitlist_offers') || !Schema::hasTable('waitlist_entries')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE public.waitlist_offers (
                id uuid DEFAULT gen_random_uuid() NOT NULL PRIMARY KEY,
                tenant_id uuid NOT NULL REFERENCES public.tenants(id) ON DELETE CASCADE,
                waitlist_entry_id uuid NOT NULL REFERENCES public.waitlist_entries(id) ON DELETE CASCADE,
                appointment_id uuid NOT NULL REFERENCES public.appointments(id) ON DELETE CASCADE,
                status character varying(20) DEFAULT 'open' NOT NULL,
                notified_at timestamp with time zone DEFAULT now() NOT NULL,
                expires_at timestamp with time zone NOT NULL,
                closed_at timestamp with time zone,
                created_at timestamp with time zone DEFAULT now() NOT NULL,
                CONSTRAINT waitlist_offers_status_check CHECK (status IN ('open', 'expired', 'booked', 'void')),
                CONSTRAINT waitlist_offers_once UNIQUE (waitlist_entry_id, appointment_id)
            )
        SQL);

        DB::statement('CREATE INDEX waitlist_offers_open_idx ON public.waitlist_offers (tenant_id, status, expires_at)');
        DB::statement('CREATE INDEX waitlist_offers_appointment_idx ON public.waitlist_offers (appointment_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_offers');
    }
};
