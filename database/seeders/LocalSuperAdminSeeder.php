<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class LocalSuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('LocalSuperAdminSeeder may only run outside production.');
        }

        $this->call(PlatformRolesSeeder::class);

        DB::transaction(function (): void {
            $user = User::query()->updateOrCreate(
                ['email' => 'exowork@gmail.com'],
                [
                    'name' => 'EXOWORK Super Admin',
                    'password' => Hash::make('123456789'),
                    'status' => 'active',
                    'disabled_at' => null,
                ],
            );

            DB::table('user_roles')->updateOrInsert([
                'user_id' => $user->id,
                'role_id' => DB::table('roles')->where('key', 'superadmin')->value('id'),
            ]);
        });
    }
}
