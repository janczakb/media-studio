<?php

namespace UseFlex\MediaStudio\Standalone;

use Illuminate\Console\Command;

final class CreateAccount extends Command
{
    protected $signature = 'media-studio:account {email} {--name=}';

    protected $description = 'Create a standalone Studio account using an interactive hidden password prompt.';

    public function handle(AccountActions $accounts): int
    {
        if (! config('media-studio.auth.enabled')) {
            $this->error('Standalone authentication is disabled.');

            return self::FAILURE;
        }
        $password = $this->secret('Password (minimum 12 characters, upper/lower case and number)');
        $confirmation = $this->secret('Confirm password');
        $accounts->provision(['name' => $this->option('name') ?: $this->ask('Name'), 'email' => $this->argument('email'), 'password' => $password, 'password_confirmation' => $confirmation]);
        $this->info('Account created. Verify the email address through the sign-in flow.');

        return self::SUCCESS;
    }
}
