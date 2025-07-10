<?php

namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class ProcessPayment implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private string $correlationId,
        private float $amount
    ) {
    }

    public function retryUntil(): ?\DateTimeInterface
    {
        return now()->addMinutes(5);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        [$failing, $responseTime] = cache()->remember('status', 5, function () {
            $response = Http::get('http://payment-processor-default:8080/payments/service-health')->json();

            return [
                $response['failing'],
                $response['minResponseTime'],
                now()
            ];
        });

        if ($failing || $responseTime > 50) {
            $this->release();
            return;
        }

        $body = [
            'amount' => $this->amount,
            'correlationId' => $this->correlationId,
            'requestedAt' => now()->toIso8601String(),
        ];

        $response = Http::post("http://payment-processor-default:8080/payments", $body);

        if ($response->failed()) {
            $this->release();
            return;
        }

        Payment::insert([
            'id' => $this->correlationId,
            'amount' => $this->amount,
            'processor' => 'default',
        ]);
    }
}
