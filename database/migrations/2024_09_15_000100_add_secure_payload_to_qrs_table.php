<?php

use App\Models\Qr;
use App\Services\Qr\SecureQrCodeProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qrs', function (Blueprint $table): void {
            if (! Schema::hasColumn('qrs', 'display_code')) {
                $table->string('display_code', 32)->nullable()->after('ticket_id');
            }

            if (! Schema::hasColumn('qrs', 'payload')) {
                $table->string('payload', 512)->nullable()->after('display_code');
            }
        });

        /** @var SecureQrCodeProvider $provider */
        $provider = App::make(SecureQrCodeProvider::class);

        Model::withoutEvents(function () use ($provider): void {
            Qr::withTrashed()->with('ticket')->lazy()->each(function (Qr $qr) use ($provider): void {
                $ticket = $qr->ticket;

                if ($ticket === null) {
                    return;
                }

                $generated = $provider->generate($ticket);

                $qr->forceFill([
                    'display_code' => $generated->displayCode,
                    'payload' => $generated->payload,
                ])->saveQuietly();
            });
        });

        if (Schema::hasColumn('qrs', 'code')) {
            Schema::table('qrs', function (Blueprint $table): void {
                $table->dropUnique('qrs_code_unique');
            });

            Schema::table('qrs', function (Blueprint $table): void {
                $table->dropColumn('code');
            });
        }

        Schema::table('qrs', function (Blueprint $table): void {
            $table->unique('display_code');
            $table->unique('payload');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('qrs')) {
            return;
        }

        Schema::table('qrs', function (Blueprint $table): void {
            if (! Schema::hasColumn('qrs', 'code')) {
                $table->string('code')->nullable()->after('ticket_id');
            }
        });

        Model::withoutEvents(function (): void {
            Qr::withTrashed()->lazy()->each(function (Qr $qr): void {
                if ($qr->display_code !== null) {
                    $qr->forceFill(['code' => $qr->display_code])->saveQuietly();
                }
            });
        });

        Schema::table('qrs', function (Blueprint $table): void {
            $table->unique('code');
        });

        Schema::table('qrs', function (Blueprint $table): void {
            if (Schema::hasColumn('qrs', 'display_code')) {
                $table->dropUnique('qrs_display_code_unique');
                $table->dropColumn('display_code');
            }

            if (Schema::hasColumn('qrs', 'payload')) {
                $table->dropUnique('qrs_payload_unique');
                $table->dropColumn('payload');
            }
        });
    }
};
