<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/*
 * The way back in when nobody can let you in.
 *
 * A manager who forgets their password asks the owner, who fixes it in the
 * app. The owner has nobody above them and no inbox to mail a link to, so
 * their way back is this command over SSH — which is the right shape for it,
 * because reaching the server at all is already proof of who you are.
 */
class SetPassword extends Command
{
    protected $signature = 'twz:set-password
                            {username : The account to change}
                            {--password= : Skip the prompt (visible in shell history)}';

    protected $description = 'Set an account password directly, for when the owner is locked out';

    public function handle(): int
    {
        $username = mb_strtolower(trim($this->argument('username')));

        $user = User::query()->whereRaw('lower(username) = ?', [$username])->first();

        if ($user === null) {
            $this->error("No account with the username \"{$username}\".");
            $this->line('Known accounts: '.User::query()->pluck('username')->implode(', '));

            return self::FAILURE;
        }

        $password = $this->option('password') ?: $this->secret('New password');

        if (! is_string($password) || mb_strlen($password) < 8) {
            $this->error('That password is under 8 characters. Nothing was changed.');

            return self::FAILURE;
        }

        $user->forceFill([
            'password' => $password,
            // Every remembered device is signed out along with the change
            'remember_token' => Str::random(60),
        ])->save();

        $this->info("Password set for {$user->username} ({$user->role}).");

        return self::SUCCESS;
    }
}
