<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\WelcomeEmail;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[AsCommand(
    name: 'kiddietrac:test-email',
    description: 'Send a test welcome email to verify SMTP is working',
)]
final class TestEmail extends Command
{
    protected $signature = 'kiddietrac:test-email {email : Recipient email address}';

    public function handle(): int
    {
        $email = $this->argument('email');

        $this->info('Sending test email to: '.$email);
        $this->line('Using mailer: '.config('mail.default'));
        $this->line('From address: '.config('mail.from.address'));
        $this->newLine();

        try {
            Mail::to($email)->send(new WelcomeEmail(
                recipientName: 'Test User',
                recipientEmail: $email,
                tempPassword: 'TestPass123',
                centreName: 'Test Centre',
                role: 'parent',
                childNames: 'Test Child',
            ));

            $this->info('✓ Email sent successfully.');
            $this->line('Check the inbox at '.$email);
            $this->line('If you don\'t see it: check spam folder, then check Laravel log at storage/logs/');
        } catch (Throwable $e) {
            $this->error('✗ Email failed:');
            $this->line($e->getMessage());
            $this->newLine();
            $this->warn('Common fixes:');
            $this->warn('  1. Set MAIL_* variables in .env (see DEPLOY_V5A.md)');
            $this->warn('  2. Run: php artisan config:clear');
            $this->warn('  3. If using GoDaddy SMTP, verify the email account exists in cPanel → Email Accounts');
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
