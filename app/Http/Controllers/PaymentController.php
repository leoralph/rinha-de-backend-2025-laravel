<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        // Converte as datas de entrada para timestamp Unix
        // Se não for fornecido, não define limites.
        $from = $request->from ? Carbon::parse($request->from)->getTimestamp() : '-inf';
        $to = $request->to ? Carbon::parse($request->to)->getTimestamp() : '+inf';

        $summary = [];

        foreach (['default', 'fallback'] as $processor) {
            $cacheKey = "payments:{$processor}";
            $totalAmount = 0;
            $totalRequests = 0;

            // Busca todos os membros cujo score (timestamp) está dentro do intervalo
            $results = cache()->getRedis()->zRangeByScore($cacheKey, $from, $to);

            $totalRequests = count($results);

            // Itera apenas nos resultados do período para somar os valores
            foreach ($results as $member) {
                // $member é "correlationId:amount", ex: "uuid-1234:150.75"
                // Pega a parte do valor após o primeiro ':'
                $amount = (float) substr($member, strpos($member, ':') + 1);
                $totalAmount += $amount;
            }

            $summary[$processor] = [
                'totalRequests' => $totalRequests,
                'totalAmount' => (float) $totalAmount,
            ];
        }

        return response()->json($summary);
    }

    public function store(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'correlationId' => 'required|uuid|max:255',
        ]);

        if (cache()->has($request->correlationId)) {
            return response(null, 201);
        }

        [$default, $fallback] = cache()->remember('gateway-statuses', 5, function () {
            $responses = Http::pool(fn(Pool $pool) => [
                $pool->get('http://payment-processor-default:8080/payments/service-health'),
                $pool->get('http://payment-processor-fallback:8080/payments/service-health'),
            ]);

            return [
                $responses[0]->json(),
                $responses[1]->json(),
            ];
        });

        $processor = 'default';

        if ($default['failing'] || $default['minResponseTime'] > $fallback['minResponseTime']) {
            $processor = 'fallback';
        }

        $body = [
            'amount' => $request->amount,
            'correlationId' => $request->correlationId,
            'requestedAt' => now()->toIso8601String(),
        ];

        $response = Http::post("http://payment-processor-{$processor}:8080/payments", $body);

        if ($response->failed() && $processor === 'default') {
            $processor = 'fallback';
            $response = Http::post("http://payment-processor-{$processor}:8080/payments", $body);
        }

        if ($response->failed()) {
            return response()->json(['error' => 'Payment processing failed'], 500);
        }

        cache()->forever($request->correlationId, true);

        // Constrói a chave do Sorted Set e o membro
        $cacheKey = "payments:{$processor}";
        $timestamp = now()->getTimestamp(); // O score (timestamp Unix)
        $member = "{$request->correlationId}:{$request->amount}"; // O membro único

        cache()->getRedis()->zAdd($cacheKey, [$member => $timestamp]);

        return response(null, 201);
    }
}
