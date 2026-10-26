<?php

namespace App\Domain\Notification\Jobs;

use App\Domain\Notification\ReportDelivery;
use App\Domain\Settings\SettingsRepository;
use App\Support\Branding;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/** One message to one channel, queued so a slow or failing channel never holds up the others. */
class DeliverMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public string $channel, public string $subject, public string $text, public ?string $html = null, public ?string $pdfPath = null) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(SettingsRepository $settings, ReportDelivery $delivery): void
    {
        match ($this->channel) {
            'telegram' => $this->telegram($settings),
            'email' => $this->email($delivery),
            'whatsapp' => $this->whatsapp($settings, $delivery),
            default => throw new RuntimeException("Saluran tidak dikenal: {$this->channel}"),
        };
    }

    private function telegram(SettingsRepository $settings): void
    {
        $token = $settings->get('telegram.bot_token');
        $chat = $settings->get('telegram.chat_id');
        if (! $token || ! $chat) {
            return;
        }
        Http::post("https://api.telegram.org/bot{$token}/sendMessage", ['chat_id' => $chat, 'text' => $this->subject === '' ? $this->text : "{$this->subject}\n\n{$this->text}"])->throw();
        if ($this->pdfPath !== null && is_file($this->pdfPath)) {
            Http::attach('document', (string) file_get_contents($this->pdfPath), basename($this->pdfPath))->post("https://api.telegram.org/bot{$token}/sendDocument", ['chat_id' => $chat, 'caption' => $this->subject])->throw();
        }
    }

    private function email(ReportDelivery $delivery): void
    {
        $to = $delivery->emails();
        if ($to === []) {
            return;
        }
        // The sender address comes from .env (it must be a domain verified with the mail provider); the name is the cafe's own.
        $fromAddress = (string) config('mail.from.address');
        $fromName = Branding::profile()['name'];
        $html = $this->html;
        $pdf = $this->pdfPath !== null && is_file($this->pdfPath) ? $this->pdfPath : null;
        if ($html === null) {
            Mail::raw($this->text, function ($m) use ($to, $pdf, $fromAddress, $fromName) {
                $m->to($to)->from($fromAddress, $fromName)->subject($this->subject);
                if ($pdf !== null) {
                    $m->attach($pdf, ['as' => basename($pdf), 'mime' => 'application/pdf']);
                }
            });

            return;
        }
        Mail::send([], [], function ($m) use ($to, $html, $pdf, $fromAddress, $fromName) {
            $m->to($to)->from($fromAddress, $fromName)->subject($this->subject)->html($html)->text($this->text);
            if ($pdf !== null) {
                $m->attach($pdf, ['as' => basename($pdf), 'mime' => 'application/pdf']);
            }
        });
    }

    /** Fonnte (api.fonnte.com) style gateway: token in the Authorization header, numbers with country code 62. */
    private function whatsapp(SettingsRepository $settings, ReportDelivery $delivery): void
    {
        $token = $settings->get('whatsapp.token');
        $targets = $delivery->whatsappTargets();
        if (! $token || $targets === []) {
            return;
        }
        $res = Http::withHeaders(['Authorization' => (string) $token])->asForm()->post('https://api.fonnte.com/send', [
            'target' => implode(',', $targets), 'message' => $this->subject === '' ? $this->text : "*{$this->subject}*\n\n{$this->text}", 'countryCode' => '62',
        ])->throw();
        if ($res->json('status') === false) {
            throw new RuntimeException('WhatsApp ditolak gateway: '.(string) ($res->json('reason') ?? 'tidak diketahui'));
        }
    }
}
