<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserAndAccountSeeder extends Seeder
{
    /**
     * 단일 사용자(id=1) + 기본 계좌 1개를 보장한다.
     * 이미 존재하면 중복 생성하지 않는다.
     */
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'chiikawa',
                'email' => 'chiikawa@localhost',
                'password' => Hash::make('changeme'),
            ]
        );

        Account::firstOrCreate(
            ['user_id' => $user->id, 'name' => '한투'],
            [
                'account_type' => 'demo',
                'broker' => 'KIS',
            ]
        );
    }
}
