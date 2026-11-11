<?php

namespace App\Console\Commands;

use App\Enums\AppRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/** There is no public sign-up: create the first person (the super admin) here, then invite everyone else. */
class CreateSuperAdmin extends Command
{
    protected $signature = 'chat:create-super-admin {--email=} {--name=} {--password=}';

    protected $description = 'Create the super admin account (the first user)';

    public function handle(): int
    {
        if (User::query()->where('app_role', AppRole::SuperAdmin->value)->exists()) {
            $this->error('A super admin already exists. Invite people from the Users page instead.');

            return self::FAILURE;
        }

        $email = Str::lower(trim($this->option('email') ?: text('Email', required: true)));
        $name = $this->option('name') ?: text('Display name', default: Str::before($email, '@'));
        $password = $this->option('password') ?: password('Password (min 8 characters)', required: true, validate: fn ($v) => strlen($v) < 8 ? 'At least 8 characters.' : null);

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->fill(['display_name' => $name, 'password' => $password]);
        $user->app_role = AppRole::SuperAdmin;
        $user->is_active = true;
        $user->joined_at ??= now();
        $user->save();

        $this->info("Super admin {$email} is ready.");

        return self::SUCCESS;
    }
}
