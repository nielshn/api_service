<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use App\Models\Transaction;
use App\Repositories\TransactionRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Routing\Controllers\Middleware;

class TransactionController extends Controller
{
    protected $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    public static function middleware(): array
    {
        return [
            'auth:api',
            new Middleware('permission:view_transaction', only: ['index']),
            new Middleware('permission:create_transaction', only: ['store']),
            new Middleware('permission:update_transaction', only: ['update']),
        ];
    }


    public function index(Request $request)
    {
        $query = Transaction::with([
            'user',
            'transactionType',
            'transactionDetails.barang',
            'transactionDetails.gudang'
        ]);

        if (!$request->user()->hasRole('superadmin')) {
            $query->where('user_id', $request->user()->id);
        }

        if ($request->filled('transaction_type_id')) {
            $query->where('transaction_type_id', $request->transaction_type_id);
        }

        if ($request->filled('transaction_code')) {
            $query->where('transaction_code', 'LIKE', "%{$request->transaction_code}%");
        }

        if ($request->filled(['transaction_date_start', 'transaction_date_end'])) {
            $query->whereBetween('transaction_date', [$request->transaction_date_start, $request->transaction_date_end]);
        }

        // return TransactionResource::collection($query->get());
        return TransactionResource::collection(
            $query->latest()->get()
        );
    }

    public function store(Request $request)
    {
        try {
            $result = app(TransactionRepository::class)->createTransaction($request);
            return response()->json([
                'message' => 'Transaksi berhasil dibuat.',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 422);
        }
    }

    public function checkBarcode($barcode)
    {
        $result = $this->transactionService->checkBarcode($barcode);

        // Memastikan status yang digunakan sesuai (true/false)
        if ($result['success'] == 'false') {
            return response()->json($result, 404);
        }

        return response()->json($result, 200); // Status true mengembalikan 200
    }

    public function show($id)
    {
        $transaction = $this->transactionService->find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        return new TransactionResource($transaction);
    }

   public function update(TransactionRequest $request, $kode)
{
    try {
        $transaction = $this->transactionService->updateTransactionByKode($kode, $request);

        return response()->json([
            'message' => 'Transaksi berhasil diperbarui.',
            'data' => new TransactionResource($transaction)
        ], 200);

    } catch (ModelNotFoundException $e) {
        return response()->json([
            'message' => 'Transaksi tidak ditemukan.'
        ], 404);

    } catch (\Exception $e) {
        return response()->json([
            'message' => $e->getMessage()
        ], 422);
    }
}
}
