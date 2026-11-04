<?php

namespace Modules\IdentityTenancy\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Modules\IdentityTenancy\Infrastructure\Persistence\User;

/**
 * Creates a login for local use while registration is disabled (minimal
 * Fortify). The password is prompted without echo and never logged.
 * --verified marks the email as verified, which invitations require.
 */
final class CreateUserCommand extends Command
{
    protected $signature = 'orbixa:user:create {email} {--name= : Display name} {--verified : Mark the email as verified}';

    protected $description = 'Create a user with a password prompted without echo (registration is disabled)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $name = (string) ($this->option('name') ?: strstr($email, '@', true));

        $password = (string) $this->secret('Password (min. 12 characters)');
        $confirmation = (string) $this->secret('Repeat password');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
            [
                'email' => ['required', 'email', 'max:254', 'unique:'.User::class.',email'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'string', 'min:12', 'confirmed'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        if ($this->option('verified')) {
            $user->email_verified_at = now();
        }
        $user->save();

        $this->info("User {$email} created".($this->option('verified') ? ' (email verified).' : '.'));

        return self::SUCCESS;
    }
}
