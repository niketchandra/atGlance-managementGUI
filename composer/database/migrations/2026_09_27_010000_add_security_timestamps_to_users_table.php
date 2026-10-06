<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the password last changed, and the last two sign-ins, for the
     * Security & Privacy section of /profile.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('password');
            $table->timestamp('last_login_at')->nullable()->after('status');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->timestamp('previous_login_at')->nullable()->after('last_login_ip');
            $table->string('previous_login_ip', 45)->nullable()->after('previous_login_at');
        });

        // Best guess for existing accounts: the latest password-change request,
        // else the account creation time. Sign-ins start being tracked now.
        foreach (DB::table('users')->select('id', 'created_at')->get() as $user) {
            $changedAt = DB::table('activity_logs')
                ->where('user_id', $user->id)
                ->where(fn ($query) => $query
                    ->where('event', 'password.changed')
                    ->orWhere(fn ($legacy) => $legacy->whereNull('event')->where('method', 'POST')->where('path', '/password/update')))
                ->max('created_at');

            DB::table('users')->where('id', $user->id)->update(['password_changed_at' => $changedAt ?? $user->created_at]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_changed_at', 'last_login_at', 'last_login_ip', 'previous_login_at', 'previous_login_ip']);
        });
    }
};
