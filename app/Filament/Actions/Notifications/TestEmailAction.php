<?php

namespace App\Filament\Actions\Notifications;

use Closure;
use Exception;
use Filament\Forms\Components\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

class TestEmailAction extends Action
{
    public Closure $settingsCallback;

    public static function getDefaultName(): ?string
    {
        return 'mail_test';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('Test'));

        $this->successNotificationTitle(__('Test email sent successfully'));

        $this->failureNotificationTitle(__('Error'));

        $this->icon('heroicon-m-envelope');

        $this->color('gray');

        $this->action(fn () => $this->testSendingEmail());
    }

    public function setSettings(Closure $settingsCallback): self
    {
        $this->settingsCallback = $settingsCallback;

        return $this;
    }

    protected function testSendingEmail(): void
    {
        $settings = call_user_func($this->settingsCallback);

        $user = Auth::user();
        if (! $user || empty($settings['from_address'])) {
            Notification::make()
                ->title('Error')
                ->body('Could not determine recipient or sender address')
                ->danger()
                ->send();

            return;
        }

        // Extract name and email from from_address
        $from_adrs = trim($settings['from_address'] ?? '');
        $matches = [];
        $name = null;
        $email = null;

        // Match: "Name" <email> or Name <email>
        if (preg_match('/^"?([^"]*?)"?\s*<\s*([^>]+)\s*>$/', $from_adrs, $matches)) {
            $name = trim($matches[1]);
            $email = trim($matches[2], " \t\n\r\0\x0B\"<>");
        } elseif (filter_var($from_adrs, FILTER_VALIDATE_EMAIL)) {
            // Just an email
            $email = trim($from_adrs, " \t\n\r\0\x0B\"<>");
            $name = null;
        } else {
            // fallback: try to extract email from string
            if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $from_adrs, $matches)) {
                $email = trim($matches[1], " \t\n\r\0\x0B\"<>");
                $name = trim(str_replace($matches[1], '', $from_adrs), " \t\n\r\0\x0B\"<>");
            }
        }

        // Validate email before sending
        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Notification::make()
                ->title('Error')
                ->body('Invalid sender email format')
                ->danger()
                ->send();

            return;
        }

        try {
            // Use Laravel's Mail facade
            $to = $user->email; // or any test email
            $fromEmail = $email;
            $fromName = $settings['nickname'] ?? $name ?? $email;

            Mail::raw(
                'Hello, this is a test email sent to verify your notification settings. No action is required.',
                function ($message) use ($to, $fromEmail, $fromName) {
                    $message->to($to)
                        ->subject('Test Email Notifications')
                        ->from($fromEmail, $fromName);
                }
            );

            Notification::make()
                ->title('Test email sent successfully')
                ->body('A test email has been sent to your address.')
                ->success()
                ->send();
        } catch (Exception $e) {
            Notification::make()
                ->title('Failed to send test email')
                ->body('Error: '.$e->getMessage())
                ->danger()
                ->send();
        }
    }
}
