<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E11 (بعد E11a): أعمدة إضافية فقط بلا إعادة بناء — الأنواع الواردة (الاقتباس والوسائط والإحالة والبحث المطبَّع)،
 * ورقمنا المستقبِل، ورمز الخطأ الداخلي، ومفتاح الإرسال، وحالة ربط المحادثة بعميل والدمج.
 * الهوية نفسها (wa_user_id وphone القابل للفراغ) في 2026_10_14_000009. متكررة الأمان (hasColumn/hasIndex).
 */
return new class extends Migration
{
    private const CONVERSATIONS = [
        'phone_number_id' => ['string', 64],
        'referral' => ['json'],
        'client_link' => ['string', 10],       // auto | manual | blocked
        'client_linked_by' => ['unsignedBigInteger'],
        'client_linked_at' => ['timestamp'],
        'merged_into_id' => ['unsignedBigInteger'],
    ];

    private const MESSAGES = [
        'phone_number_id' => ['string', 64],
        'reply_to_wamid' => ['string', 128],
        'error_code' => ['string', 32],        // رمز داخلي (MetaErrorCatalog)؛ رقم ميتا في meta_code
        'send_key' => ['string', 64],
        'media_id' => ['string', 128],
        'media_mime' => ['string', 100],
        'media_sha256' => ['string', 100],
        'media_filename' => ['string', 255],
        'media_size' => ['unsignedInteger'],
        'media_path' => ['string', 255],
        'body_norm' => ['text'],
        'referral' => ['json'],
    ];

    public function up(): void
    {
        $this->addColumns('wa_conversations', self::CONVERSATIONS);
        $this->addColumns('wa_messages', self::MESSAGES);

        if (Schema::hasTable('wa_messages')) {
            Schema::table('wa_messages', function (Blueprint $t) {
                if (!Schema::hasIndex('wa_messages', 'wa_messages_reply_to_wamid_index')) {
                    $t->index('reply_to_wamid');
                }
                if (!Schema::hasIndex('wa_messages', 'wa_messages_send_key_unique')) {
                    $t->unique('send_key');
                }
            });
        }

        // ربط المحادثة بعميل يدوياً (E11.8): للمدير وحده، والمنح لغيره قرار المالك. بلا RolePermissionSeeder (A11)
        if (Schema::hasTable('permissions')) {
            $id = DB::table('permissions')->where('slug', 'whatsapp.link_client')->value('id')
                ?? DB::table('permissions')->insertGetId(['slug' => 'whatsapp.link_client', 'name' => 'ربط المحادثة بعميل', 'module' => 'whatsapp', 'created_at' => now(), 'updated_at' => now()]);
            if ($admin = DB::table('roles')->where('slug', 'admin')->value('id')) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $admin, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            $id = DB::table('permissions')->where('slug', 'whatsapp.link_client')->value('id');
            if ($id) {
                DB::table('role_permissions')->where('permission_id', $id)->delete();
                DB::table('permissions')->where('id', $id)->delete();
            }
        }
        foreach (['wa_messages_reply_to_wamid_index' => 'reply_to_wamid', 'wa_messages_send_key_unique' => 'send_key'] as $index => $column) {
            if (Schema::hasIndex('wa_messages', $index)) {
                Schema::table('wa_messages', fn (Blueprint $t) => str_ends_with($index, '_unique') ? $t->dropUnique($index) : $t->dropIndex($index));
            }
        }
        foreach (['wa_conversations' => self::CONVERSATIONS, 'wa_messages' => self::MESSAGES] as $table => $columns) {
            foreach (array_keys($columns) as $column) {
                if (Schema::hasColumn($table, $column)) {
                    Schema::table($table, fn (Blueprint $t) => $t->dropColumn($column));
                }
            }
        }
    }

    private function addColumns(string $table, array $columns): void
    {
        if (!Schema::hasTable($table)) {
            return;
        }
        Schema::table($table, function (Blueprint $t) use ($table, $columns) {
            foreach ($columns as $name => $spec) {
                [$type, $length] = $spec + [1 => null];
                if (!Schema::hasColumn($table, $name)) {
                    $length ? $t->{$type}($name, $length)->nullable() : $t->{$type}($name)->nullable();
                }
            }
        });
    }
};
