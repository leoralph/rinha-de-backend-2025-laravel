<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessPayment;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $summary = DB::table('payments')
            ->selectRaw('processor, SUM(amount) as amount, COUNT(*) as count')
            ->groupBy('processor')
            ->when($request->from, function ($query) use ($request) {
                return $query->where('created_at', '>=', date('Y-m-d H:i:s', strtotime($request->from)));
            })
            ->when($request->to, function ($query) use ($request) {
                return $query->where('created_at', '<=', date('Y-m-d H:i:s', strtotime($request->to)));
            })
            ->get()
            ->mapWithKeys(function ($item) {
                return [
                    $item->processor => [
                        'totalAmount' => $item->amount,
                        'totalRequests' => $item->count,
                    ]
                ];
            });

        if (!isset($summary['default'])) {
            $summary['default'] = ['totalAmount' => 0, 'totalRequests' => 0];
        }

        if (!isset($summary['fallback'])) {
            $summary['fallback'] = ['totalAmount' => 0, 'totalRequests' => 0];
        }

        return response()->json($summary->toArray());
    }

    public function store(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
            'correlationId' => 'required|uuid|max:255',
        ]);

        dispatch(new ProcessPayment($request->correlationId, $request->amount));

        return response(null, 201);
    }
}
