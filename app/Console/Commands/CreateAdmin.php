<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email} {--name=Administrator}';

    protected $description = 'Create an administrator account with an interactively supplied password';

    public function handle(): int
    {
        $data = ['email' => mb_strtolower($this->argument('email')), 'name' => $this->option('name'), 'password' => $this->secret('Password (9+ characters, uppercase, lowercase and a symbol)')];
        $validator = Validator::make($data, ['email' => 'required|email|unique:users,email', 'name' => 'required|string|max:255', 'password' => ['required', Password::defaults()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $user = new User($data);
        $user->role = 'admin';
        $user->save();
        $this->info('Administrator created.');

        return self::SUCCESS;
    }
}
