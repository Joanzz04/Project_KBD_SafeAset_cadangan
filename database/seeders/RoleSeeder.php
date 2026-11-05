<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['BUYER', 'SELLER', 'AGENT', 'ADMIN'] as $code) {
            DB::table('roles')->insertOrIgnore(['code' => $code]);
        }
    }
}