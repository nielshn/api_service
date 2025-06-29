<?php

namespace App\Services;

use App\Models\BarangGudang;
use App\Models\Gudang;
use App\Repositories\TransactionRepository;
use Exception;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TransactionService
{
    protected $transactionRepo;

    public function __construct(TransactionRepository $transactionRepo)
    {
        $this->transactionRepo = $transactionRepo;
    }

    public function processTransaction($request)
    {
        DB::beginTransaction();
        try {
            $transaction = $this->transactionRepo->createTransaction($request);
            DB::commit();
            return [
                'success' => true,
                'message' => 'Transaksi berhasil!',
                'data' => $transaction
            ];
        } catch (Exception $e) {
            DB::rollBack();
            return [
                'success' => false,
                'message' => 'Transaksi gagal!',
                'error' => $e->getMessage()
            ];
        }
    }

   public function checkBarcode($barcode)
{
    $barang = $this->transactionRepo->findBarangByKode($barcode);

    if (!$barang) {
        return [
            'success' => 'false',
            'message' => 'Barang tidak ditemukan.',
        ];
    }

    $user = Auth::user();
    $gudang = Gudang::where('user_id', $user->id)->first();

    // Default stok
    $stokTersedia = 0;
    $stokDipinjam = 0;
    $stokMaintenance = 0;

    if ($gudang) {
        $barangGudang = BarangGudang::where('barang_id', $barang->id)
            ->where('gudang_id', $gudang->id)
            ->first();

        if ($barangGudang) {
            $stokTersedia = $barangGudang->stok_tersedia;
            $stokDipinjam = $barangGudang->stok_dipinjam;
            $stokMaintenance = $barangGudang->stok_maintenance;
        }
    }

    return [
        'success' => 'true',
        'data' => [
            'barang_kode' => $barang->barang_kode,
            'barang_nama' => $barang->barang_nama,
            'kategori' => $barang->category ? $barang->category->name : null,
            'stok_tersedia' => $stokTersedia,
            'stok_dipinjam' => $stokDipinjam,
            'stok_maintenance' => $stokMaintenance,
            'gambar' => asset('storage/' . $barang->barang_gambar),
            'satuan' => $barang->satuan ? $barang->satuan->name : 'Tidak Diketahui',
        ]
    ];
}


    public function find($id)
    {
        return $this->transactionRepo->find($id);
    }
    public function updateTransactionByKode($kode, $request)
    {
        // Tidak perlu DB::beginTransaction() di sini, sudah ada di repository
        return $this->transactionRepo->updateTransactionWithDetailsByKode($kode, $request);
    }
}
