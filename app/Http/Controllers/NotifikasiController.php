<?php

namespace App\Http\Controllers;

use App\Events\StockMinimumReached;
use App\Models\Barang;
use App\Models\BarangGudang;
use App\Models\Gudang;
use App\Models\Notifikasi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotifikasiController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        if (!$user || !$user->role) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $role = strtolower($user->role->name);

        if ($role === 'superadmin') {
            // Ambil semua notifikasi belum dibaca
            $notifikasi = Notifikasi::where('read', false)->orWhereNull('read')->get();
        } elseif ($role === 'operator') {
            $gudangIds = Gudang::where('user_id', $user->id)->pluck('id');

            $notifikasi = Notifikasi::where(function ($query) use ($gudangIds) {
                $query->where('read', false)
                    ->orWhereNull('read');
            })->whereIn('gudang_id', $gudangIds)->get();
        } else {
            $notifikasi = collect(); // Kosong untuk role lain
        }

        return response()->json($notifikasi);
    }

    public function markAsRead($id)
    {
        $notifikasi = Notifikasi::findOrFail($id);
        $notifikasi->read = true;
        $notifikasi->save();

        return response()->json(['message' => 'Notifikasi sudah dibaca']);
    }
    public function markAllAsRead()
    {
        Notifikasi::where('read', false)->update(['read' => true]);

        return response()->json(['message' => 'Semua notifikasi telah ditandai sebagai dibaca']);
    }
    function checkStockAndNotify($barangId, $gudangId, $stokTersedia)
    {
        $barang = Barang::find($barangId);
        $gudang = Gudang::find($gudangId);
        $barangGudang = BarangGudang::where('barang_id', $barangId)
            ->where('gudang_id', $gudangId)
            ->first();

        $stokMinimum = $barang->stok_minimum ?? 1;

        if ($stokTersedia == 0 && !$barangGudang->notified) {
            $title = "Stok Barang Habis";
            $message = "Stok barang {$barang->barang_nama} di gudang {$gudang->name} telah habis.";

            Notifikasi::create([
                'title' => $title,
                'message' => $message,
                'barang_id' => $barangId,
                'gudang_id' => $gudangId,
                'read' => false
            ]);

            $barangGudang->update(['notified' => true]);
            event(new StockMinimumReached($title, $message, $barangId, $gudangId));
        } elseif ($stokTersedia <= $stokMinimum && !$barangGudang->notified) {
            $title = "Stok Barang Minimum Tercapai";
            $message = "Stok barang {$barang->barang_nama} di gudang {$gudang->name} telah mencapai batas minimum.";

            Notifikasi::create([
                'title' => $title,
                'message' => $message,
                'barang_id' => $barangId,
                'gudang_id' => $gudangId,
                'read' => false
            ]);

            $barangGudang->update(['notified' => true]);
            event(new StockMinimumReached($title, $message, $barangId, $gudangId));
        }

        // Reset flag notified jika stok kembali naik di atas batas minimum
        if ($stokTersedia > $stokMinimum && $barangGudang->notified) {
            $barangGudang->update(['notified' => false]);
        }
    }
}
