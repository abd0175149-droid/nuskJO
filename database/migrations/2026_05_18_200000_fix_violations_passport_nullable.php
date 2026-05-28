<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite: recreate violations table with nullable passport_number
        DB::statement('CREATE TABLE violations_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            violation_number VARCHAR(30) NOT NULL UNIQUE,
            agent_id INTEGER NOT NULL REFERENCES agents(id),
            client_id INTEGER NOT NULL REFERENCES clients(id),
            violation_type_id INTEGER NOT NULL REFERENCES violation_types(id),
            passport_number VARCHAR(30),
            passport_name VARCHAR(150),
            cost_sar DECIMAL(15,2) NOT NULL,
            violation_date DATE NOT NULL,
            description TEXT,
            notes TEXT,
            billing_status VARCHAR(20) DEFAULT "unbilled",
            invoice_id INTEGER REFERENCES invoices(id) ON DELETE SET NULL,
            status VARCHAR(20) DEFAULT "pending",
            rejection_reason TEXT,
            created_by INTEGER NOT NULL REFERENCES users(id),
            approved_by INTEGER REFERENCES users(id),
            approved_at TIMESTAMP,
            created_at TIMESTAMP,
            updated_at TIMESTAMP
        )');

        DB::statement('INSERT INTO violations_new SELECT * FROM violations');
        DB::statement('DROP TABLE violations');
        DB::statement('ALTER TABLE violations_new RENAME TO violations');
        DB::statement('CREATE INDEX violations_status_index ON violations(status)');
        DB::statement('CREATE INDEX violations_billing_status_index ON violations(billing_status)');
    }

    public function down(): void
    {
        // no-op
    }
};
