<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Setting;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $setting = Setting::where('key', 'print_layouts')->first();
        if ($setting && $setting->value) {
            $data = json_decode($setting->value, true);
            if (isset($data['transfer']['elements'])) {
                foreach ($data['transfer']['elements'] as $k => &$v) {
                    if (isset($v['text'])) {
                        $v['text'] = str_replace(['{{رقم_الحوالة}}', 'حوالة'], ['{{رقم_السند}}', 'سند صرف'], $v['text']);
                    }
                    if (isset($v['customLabel'])) {
                        $v['customLabel'] = str_replace(['رقم الحوالة', 'حوالة'], ['رقم السند', 'سند صرف'], $v['customLabel']);
                    }
                }
                $setting->value = json_encode($data, JSON_UNESCAPED_UNICODE);
                $setting->save();
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $setting = Setting::where('key', 'print_layouts')->first();
        if ($setting && $setting->value) {
            $data = json_decode($setting->value, true);
            if (isset($data['transfer']['elements'])) {
                foreach ($data['transfer']['elements'] as $k => &$v) {
                    if (isset($v['text'])) {
                        $v['text'] = str_replace(['{{رقم_السند}}', 'سند صرف'], ['{{رقم_الحوالة}}', 'حوالة'], $v['text']);
                    }
                    if (isset($v['customLabel'])) {
                        $v['customLabel'] = str_replace(['رقم السند', 'سند صرف'], ['رقم الحوالة', 'حوالة'], $v['customLabel']);
                    }
                }
                $setting->value = json_encode($data, JSON_UNESCAPED_UNICODE);
                $setting->save();
            }
        }
    }
};
