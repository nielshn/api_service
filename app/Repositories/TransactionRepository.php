<?php

namespace App\Repositories;

use App\Http\Controllers\NotifikasiController;
use App\Models\{Transaction, TransactionDetail, Barang, BarangGudang, Gudang, User};
use Illuminate\Support\Facades\Auth;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class TransactionRepository
{
    protected $notifcontroller;

    public function __construct(NotifikasiController $notifcontroller)
    {
        $this->notifcontroller = $notifcontroller;
    }

    public function createTransaction($request)
    {
        DB::beginTransaction();
        try {
            $user = Auth::user();
            $userId = $user->id;
            $gudangId = $this->getGudangIdByUserId($userId);

            $transaction = Transaction::create([
                'user_id' => $userId,
                'transaction_type_id' => $request->transaction_type_id,
                'transaction_code' => $this->generateTransactionCode($request->transaction_type_id),
                'transaction_date' => now(),
                'description' => $request->description ?? null,
            ]);

            foreach ($request->items as $item) {
                $item['gudang_id'] = $gudangId;
                $this->processTransactionItem($transaction->id, $item, $request->transaction_type_id);
            }

            DB::commit();

            return $transaction->load([
                'user:id,name',
                'transactionType:id,name',
                'transactionDetails.barang:id,barang_kode,barang_nama',
                'transactionDetails.gudang:id,name',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function getGudangIdByUserId($userId)
    {
        $gudang = Gudang::where('user_id', $userId)->first();
        if (!$gudang) {
            throw new Exception("Tidak ditemukan gudang yang terdaftar untuk user login.");
        }
        return $gudang->id;
    }

    private function generateTransactionCode($typeId)
    {
        $prefixes = [
            1 => 'MSK',
            2 => 'KLR',
            3 => 'PJM',
            4 => 'KMB',
            5 => 'MTC',
            6 => 'FIX'
        ];

        $prefix = $prefixes[$typeId] ?? 'UNK';

        $lastTransaction = Transaction::where('transaction_type_id', $typeId)->latest('id')->first();
        $number = $lastTransaction ? str_pad($lastTransaction->id + 1, 3, '0', STR_PAD_LEFT) : '001';

        return "TRX-{$prefix}-{$number}";
    }

    private function processTransactionItem($transactionId, $item, $transactionType)
    {
        // Validasi quantity tidak boleh 0 atau kurang dari 1
        if (!isset($item['quantity']) || !is_numeric($item['quantity']) || $item['quantity'] < 1) {
            throw new Exception("Quantity untuk barang {$item['barang_kode']} harus lebih dari 0.");
        }

        $barang = Barang::where('barang_kode', $item['barang_kode'])->first();
        if (!$barang) {
            throw new Exception("Barang dengan kode {$item['barang_kode']} tidak ditemukan di data Barang.");
        }

        $barangGudang = BarangGudang::where('barang_id', $barang->id)
            ->where('gudang_id', $item['gudang_id'])
            ->first();

        // Validasi berdasarkan tipe transaksi
        if (in_array($transactionType, [2, 3, 4, 5, 6]) && !$barangGudang) {
            throw new Exception("Barang {$barang->barang_nama} belum tersedia di gudang. Masukkan terlebih dahulu.");
        }

        // Validasi kategori barang vs transaksi
        $this->validateItemTransaction($barang, $barangGudang, $item, $transactionType);

        // Proses transaksi
        match ($transactionType) {
            1 => $this->handleBarangMasuk($barang, $item),
            2 => $this->handleBarangKeluar($barang->id, $item),
            3 => $this->handlePeminjaman($barang->id, $item),
            4 => $this->handlePengembalian($barang->id, $item),
            5 => $this->handleMaintanance($barang->id, $item),
            6 => $this->handleMaintenanceReturn($barang->id, $item),
        };

        // Catat ke transaction_detail
        TransactionDetail::create([
            'transaction_id' => $transactionId,
            'barang_id' => $barang->id,
            'gudang_id' => $item['gudang_id'],
            'quantity' => $item['quantity'],
            'tanggal_kembali' => $transactionType == 4 ? now() : null,
        ]);
    }

    public function validateItemTransaction($barang, $barangGudang, $item, $transactionType)
    {
        // Validasi kategori barang vs transaksi
        $validTransactionType = match (true) {
            $barang->barangcategory_id == 1 => in_array($transactionType, [1, 2]),
            $barang->barangcategory_id == 2 => in_array($transactionType, [1, 3, 4, 5, 6]),
            default => false,
        };

        if (!$validTransactionType) {
            throw new Exception("Jenis transaksi tidak valid untuk barang {$barang->barang_nama}.");
        }

        // Validasi stok keluar, pinjam, maintenance
        if (in_array($transactionType, [2, 3, 5]) && (!$barangGudang || $barangGudang->stok_tersedia < $item['quantity'])) {
            throw new Exception("Stok tidak mencukupi untuk barang {$barang->barang_nama}.");
        }

        // Custom error untuk pengembalian peminjaman (4) dan selesai maintenance (6)
        if ($transactionType == 4 && (!$barangGudang || $barangGudang->stok_dipinjam < $item['quantity'])) {
            throw new Exception("Jumlah pengembalian peminjaman barang {$barang->barang_nama} melebihi stok yang sedang dipinjam.");
        }
        if ($transactionType == 6 && (!$barangGudang || $barangGudang->stok_maintenance < $item['quantity'])) {
            throw new Exception("Jumlah pengembalian maintenance barang {$barang->barang_nama} melebihi stok yang sedang maintenance.");
        }
    }

    private function getOrCreateBarangGudang($barangId, $gudangId)
    {
        $barangGudang = BarangGudang::firstOrCreate(
            [
                'barang_id' => $barangId,
                'gudang_id' => $gudangId
            ],
            ['stok_tersedia' => 0, 'stok_dipinjam' => 0, 'stok_maintenance' => 0]
        );
        return $barangGudang;
    }

    private function handleBarangMasuk($barang, $item)
    {
        $this->getOrCreateBarangGudang($barang->id, $item['gudang_id']);
        BarangGudang::where('barang_id', $barang->id)
            ->where('gudang_id', $item['gudang_id'])
            ->increment('stok_tersedia', $item['quantity']);
    }

    private function handleBarangKeluar($barangId, $item)
    {
        DB::transaction(function () use ($barangId, $item) {
            BarangGudang::where('barang_id', $barangId)
                ->where('gudang_id', $item['gudang_id'])
                ->decrement('stok_tersedia', $item['quantity']);

            $barangGudang = BarangGudang::where('barang_id', $barangId)
                ->where('gudang_id', $item['gudang_id'])
                ->first();

            $this->notifcontroller->checkStockAndNotify($barangId, $item['gudang_id'], $barangGudang->stok_tersedia);
        });
    }

    private function handlePeminjaman($barangId, $item)
    {
        DB::transaction(function () use ($barangId, $item) {
            BarangGudang::where('barang_id', $barangId)
                ->where('gudang_id', $item['gudang_id'])
                ->decrement('stok_tersedia', $item['quantity']);

            BarangGudang::where('barang_id', $barangId)
                ->where('gudang_id', $item['gudang_id'])
                ->increment('stok_dipinjam', $item['quantity']);

            $barangGudang = BarangGudang::where('barang_id', $barangId)
                ->where('gudang_id', $item['gudang_id'])
                ->first();

            $this->notifcontroller->checkStockAndNotify($barangId, $item['gudang_id'], $barangGudang->stok_tersedia);
        });
    }

    private function handlePengembalian($barangId, $item)
    {
        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->increment('stok_tersedia', $item['quantity']);

        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->decrement('stok_dipinjam', $item['quantity']);
    }

    private function handleMaintanance($barangId, $item)
    {
        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->increment('stok_maintenance', $item['quantity']);

        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->decrement('stok_tersedia', $item['quantity']);
    }

    private function handleMaintenanceReturn($barangId, $item)
    {
        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->increment('stok_tersedia', $item['quantity']);

        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->decrement('stok_maintenance', $item['quantity']);
    }

    public function findBarangByKode($kode)
    {
        return Barang::where('barang_kode', $kode)->first();
    }

    public function searchBarangByNama($keyword)
    {
        return Barang::where('barang_nama', 'like', '%' . $keyword . '%')
            ->orWhere('barang_kode', 'like', '%' . $keyword . '%')
            ->limit(10)
            ->get();
    }

    public function find($id)
    {
        $transaction = Transaction::with([
            'user:id,name',
            'transactionType:id,name',
            'transactionDetails.barang:id,barang_kode,barang_nama',
            'transactionDetails.gudang:id,name'
        ])->find($id);

        if (!$transaction) {
            throw new \Exception('Transaksi tidak ditemukan.');
        }

        return $transaction;
    }

    public function updateTransactionWithDetailsByKode($kode, $request)
    {
            DB::beginTransaction();
    try {
        // Ambil transaksi berdasarkan kode
        $transaction = Transaction::where('transaction_code', $kode)->with('transactionDetails.barang')->first();
        if (!$transaction) {
            throw new Exception("Transaksi dengan kode {$kode} tidak ditemukan.");
        }

        // Pastikan batas waktu update < 24 jam
        $transactionDate = $transaction->transaction_date instanceof \Carbon\Carbon
            ? $transaction->transaction_date
            : \Carbon\Carbon::parse($transaction->transaction_date);

        if (now()->greaterThan($transactionDate->copy()->addHours(24))) {
            throw new Exception('Transaksi hanya dapat diperbarui dalam waktu 24 jam setelah dibuat.');
        }

        // Tidak boleh ubah tipe transaksi
        if (
            isset($request->transaction_type_id) &&
            $request->transaction_type_id != $transaction->transaction_type_id
        ) {
            throw new Exception('Tipe transaksi tidak boleh diubah pada update transaksi.');
        }

        $transactionTypeId = $transaction->transaction_type_id;

        // Validasi items wajib ada
        if (!isset($request->items) || !is_array($request->items) || count($request->items) === 0) {
            throw new Exception('Daftar items transaksi tidak boleh kosong.');
        }

        // Cek apakah barang_kode diubah (tidak boleh)
        $oldBarangKode = $transaction->transactionDetails->pluck('barang.barang_kode')->sort()->values()->toArray();
        $newBarangKode = collect($request->items)->pluck('barang_kode')->sort()->values()->toArray();

        if ($oldBarangKode !== $newBarangKode) {
            throw new Exception('Barang tidak boleh diubah. Gunakan hanya barang yang sama seperti sebelumnya.');
        }

        $gudangId = $this->getGudangIdByUserId($transaction->user_id);

        // Validasi semua item
        foreach ($request->items as $i => $item) {
            // Validasi kode
            if (empty($item['barang_kode'])) {
                throw new Exception("Item ke-" . ($i + 1) . ": kode barang tidak boleh kosong.");
            }

            // Validasi quantity
            if (
                !isset($item['quantity']) ||
                !is_numeric($item['quantity']) ||
                $item['quantity'] < 1
            ) {
                throw new Exception("Item ke-" . ($i + 1) . ": quantity harus lebih dari 0.");
            }

            $barang = Barang::where('barang_kode', $item['barang_kode'])->first();
            if (!$barang) {
                throw new Exception("Item ke-" . ($i + 1) . ": Barang dengan kode {$item['barang_kode']} tidak ditemukan.");
            }

            $barangGudang = BarangGudang::where('barang_id', $barang->id)
                ->where('gudang_id', $gudangId)
                ->first();

            if (in_array($transactionTypeId, [2, 3, 4, 5, 6]) && !$barangGudang) {
                throw new Exception("Item ke-" . ($i + 1) . ": Barang {$barang->barang_nama} belum tersedia di gudang.");
            }

            $item['gudang_id'] = $gudangId;
            $this->validateItemTransaction($barang, $barangGudang, $item, $transactionTypeId);
        }

        // Update deskripsi
        $transaction->update([
            'description' => $request->description ?? $transaction->description,
        ]);

        // Rollback stok lama
        foreach ($transaction->transactionDetails as $oldDetail) {
            $barang = Barang::find($oldDetail->barang_id);
            $item = [
                'barang_kode' => $barang->barang_kode,
                'quantity' => $oldDetail->quantity,
                'gudang_id' => $oldDetail->gudang_id,
            ];
            $this->rollbackTransactionItem($transactionTypeId, $barang, $item);
        }

        // Hapus semua detail lama
        $transaction->transactionDetails()->delete();

        // Proses ulang dan insert detail baru
        foreach ($request->items as $item) {
            $item['gudang_id'] = $gudangId;
            $this->processTransactionItem($transaction->id, $item, $transactionTypeId);
        }

        DB::commit();
        return $this->find($transaction->id);
    } catch (\Exception $e) {
        DB::rollBack();
        throw $e;
    }

    }

    /**
     * Rollback efek stok dari detail transaksi lama.
     */
    private function rollbackTransactionItem($transactionType, $barang, $item)
    {
        // Rollback berarti kebalikan proses
        match ($transactionType) {
            1 => $this->rollbackBarangMasuk($barang, $item),
            2 => $this->rollbackBarangKeluar($barang->id, $item),
            3 => $this->rollbackPeminjaman($barang->id, $item),
            4 => $this->rollbackPengembalian($barang->id, $item),
            5 => $this->rollbackMaintanance($barang->id, $item),
            6 => $this->rollbackMaintenanceReturn($barang->id, $item),
            default => null,
        };
    }

    private function rollbackBarangMasuk($barang, $item)
    {
        BarangGudang::where('barang_id', $barang->id)
            ->where('gudang_id', $item['gudang_id'])
            ->decrement('stok_tersedia', $item['quantity']);
    }

    private function rollbackBarangKeluar($barangId, $item)
    {
        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->increment('stok_tersedia', $item['quantity']);
    }

    private function rollbackPeminjaman($barangId, $item)
    {
        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->increment('stok_tersedia', $item['quantity']);

        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->decrement('stok_dipinjam', $item['quantity']);
    }

    private function rollbackPengembalian($barangId, $item)
    {
        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->decrement('stok_tersedia', $item['quantity']);

        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->increment('stok_dipinjam', $item['quantity']);
    }

    private function rollbackMaintanance($barangId, $item)
    {
        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->decrement('stok_maintenance', $item['quantity']);

        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->increment('stok_tersedia', $item['quantity']);
    }

    private function rollbackMaintenanceReturn($barangId, $item)
    {
        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->decrement('stok_tersedia', $item['quantity']);

        BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $item['gudang_id'])
            ->increment('stok_maintenance', $item['quantity']);
    }
}
