<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'chat:vapid-keys';

    protected $description = 'Generate a VAPID key pair for Web Push';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();
        $this->line('Add these to .env (keep the private key secret):');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->line('VAPID_SUBJECT=mailto:you@yourcompany.com');

        return self::SUCCESS;
    }
}
