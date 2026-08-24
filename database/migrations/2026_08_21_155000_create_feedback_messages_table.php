<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('feedback_id')->constrained('feedback')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_staff')->default(false)->index();
            $table->timestamps();

            $table->index(['feedback_id', 'created_at']);
        });

        Schema::table('feedback', function (Blueprint $table) {
            $table->timestamp('last_message_at')->nullable()->after('reviewed_at')->index();
            $table->string('last_message_by', 16)->nullable()->after('last_message_at'); // user|staff
        });

        // Seed thread opener from existing feedback rows
        $rows = DB::table('feedback')->select('id', 'user_id', 'message', 'created_at', 'updated_at')->get();
        foreach ($rows as $row) {
            $exists = DB::table('feedback_messages')->where('feedback_id', $row->id)->exists();
            if ($exists || blank($row->message)) {
                continue;
            }

            DB::table('feedback_messages')->insert([
                'id' => (string) Str::uuid(),
                'feedback_id' => $row->id,
                'user_id' => $row->user_id,
                'body' => $row->message,
                'is_staff' => false,
                'created_at' => $row->created_at,
                'updated_at' => $row->created_at,
            ]);

            DB::table('feedback')->where('id', $row->id)->update([
                'last_message_at' => $row->created_at,
                'last_message_by' => 'user',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('feedback', function (Blueprint $table) {
            $table->dropColumn(['last_message_at', 'last_message_by']);
        });

        Schema::dropIfExists('feedback_messages');
    }
};
