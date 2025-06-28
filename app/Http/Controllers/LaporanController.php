<?php

namespace App\Http\Controllers;

use App\Http\Resources\BarangResource;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Models\TransactionType;
use App\Services\BarangService;
use Illuminate\Support\Str;
use App\Services\TransactionService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class LaporanController extends Controller
{
    protected $transactionService;
    protected $barangService;


    public function __construct(TransactionService $transactionService, BarangService $barangService)
    {
        $this->transactionService = $transactionService;
        $this->barangService = $barangService;
    }

    public function laporanstokByCategory($category_id)
    {
        $user = Auth::user();
        $isSuperadmin = $user->hasRole('superadmin');
        $userId = $user->id;

        $barangs = $this->barangService->getAllBarang($userId, $isSuperadmin)
            ->where('barangcategory_id', $category_id);

        if ($barangs->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada barang pada kategori ini.',
                'data' => []
            ], 404);
        }

        return BarangResource::collection($barangs);
    }

    public function exportStokPdfByCategory($category_id)
    {
        $user = Auth::user();
        $isSuperadmin = $user->hasAnyRole(['superadmin', 'admin']);
        $barangs = $this->barangService->getAllBarang($user->id, $isSuperadmin)
            ->where('barangcategory_id', $category_id);

        if ($barangs->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada barang pada kategori ini.',
                'pdf_url' => null
            ], 404);
        }

        $logoPath = 'logo_icon.png';
        if (Storage::disk('public')->exists($logoPath)) {
            $logoBin    = Storage::disk('public')->get($logoPath);
            $logoBase64 = base64_encode($logoBin);
            $logoExt    = pathinfo($logoPath, PATHINFO_EXTENSION);
            $logoSrc    = "data:image/{$logoExt};base64,{$logoBase64}";
        } else {
            $logoSrc = '';
        }

        $html = "
    <html>
    <head>
      <meta charset='utf-8'>
      <title>Laporan Stok Barang per Kategori</title>
      <style>
        body { font-family: sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 20px; }
        .header img { max-height: 80px; margin-bottom: 10px; }
        .header h1 { font-size: 24px; margin: 0; }
        table { width:100%; border-collapse: collapse; margin-top:10px; }
        th, td { border:1px solid #ccc; padding:8px; font-size:12px; }
        th { background-color:#f4f4f4; }
        tr:nth-child(even) td { background-color: #fafafa; }
        img.item-img { max-width:60px; max-height:60px; }
      </style>
    </head>
    <body>
      <div class='header'>";
        if ($logoSrc) {
            $html .= "<img src='{$logoSrc}' alt='Logo' />";
        }
        $html .= "
        <h1>Laporan Stok Barang per Kategori</h1>
      </div>
      <table>
        <thead>
          <tr>
            <th>No</th>
            <th>Nama Barang</th>
            <th>Kode</th>
            <th>Gambar</th>
            <th>Gudang</th>
            <th>Stok Tersedia</th>
            <th>Stok Maintenance</th>
            <th>Stok Peminjaman</th>
          </tr>
        </thead>
        <tbody>";

        $no = 1;
        foreach ($barangs as $barang) {
            $relImg = $barang->barang_gambar;
            if (Storage::disk('public')->exists($relImg)) {
                $bin    = Storage::disk('public')->get($relImg);
                $ext    = pathinfo($relImg, PATHINFO_EXTENSION);
                $b64    = base64_encode($bin);
                $imgSrc = "data:image/{$ext};base64,{$b64}";
            } else {
                $imgSrc = '';
            }

            foreach ($barang->gudangs as $gudang) {
                $tersedia = $gudang->pivot->stok_tersedia   ?? 0;
                $maint    = $gudang->pivot->stok_maintenance ?? 0;
                $pinjam   = $gudang->pivot->stok_dipinjam    ?? 0;

                $html .= "
          <tr>
            <td>{$no}</td>
            <td>{$barang->barang_nama}</td>
            <td>{$barang->barang_kode}</td>
            <td><img class='item-img' src='{$imgSrc}' /></td>
            <td>{$gudang->name}</td>
            <td>{$tersedia}</td>
            <td>{$maint}</td>
            <td>{$pinjam}</td>
          </tr>";
                $no++;
            }
        }

        $html .= "
        </tbody>
      </table>
    </body>
    </html>";

        $pdf     = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
        $pdfPath = "laporan/laporan_stok_category_{$category_id}.pdf";
        Storage::disk('public')->put($pdfPath, $pdf->output());

        return response()->json([
            'message' => 'Laporan stok PDF per kategori berhasil dibuat.',
            'pdf_url' => asset('storage/' . $pdfPath),
        ], 200);
    }

    public function exportStokExcelByCategory($category_id)
    {
        $user         = Auth::user();
        $isSuperadmin = $user->hasAnyRole(['superadmin', 'admin']);
        $barangs      = $this->barangService->getAllBarang($user->id, $isSuperadmin)
            ->where('barangcategory_id', $category_id);

        if ($barangs->isEmpty()) {
            return response()->json([
                'message' => 'Tidak ada barang pada kategori ini.',
                'excel_url' => null
            ], 404);
        }

        $folder = storage_path('app/public/laporan');
        if (! File::isDirectory($folder)) {
            File::makeDirectory($folder, 0755, true);
        }

        $filePath = $folder . "/laporan_stok_category_{$category_id}.csv";
        $handle   = fopen($filePath, 'w');

        fputcsv($handle, [
            'No',
            'Nama Barang',
            'Kode',
            'Gudang',
            'Stok Tersedia',
            'Stok Maintenance',
            'Stok Peminjaman',
        ]);

        $no = 1;
        foreach ($barangs as $barang) {
            foreach ($barang->gudangs as $g) {
                fputcsv($handle, [
                    $no++,
                    $barang->barang_nama,
                    $barang->barang_kode,
                    $g->name,
                    $g->pivot->stok_tersedia   ?? 0,
                    $g->pivot->stok_maintenance ?? 0,
                    $g->pivot->stok_dipinjam    ?? 0,
                ]);
            }
        }

        fclose($handle);

        return response()->json([
            'message'   => 'Laporan stok CSV per kategori berhasil dibuat.',
            'excel_url' => asset("storage/laporan/laporan_stok_category_{$category_id}.csv"),
        ], 200);
    }

   
    public function laporanstok()
    {
        $user = Auth::user();

        $isSuperadmin = $user->hasRole('superadmin');
        $userId = $user->id;

        $barangs = $this->barangService->getAllBarang($userId, $isSuperadmin);

        return BarangResource::collection($barangs);
    }


    public function exportStokPdf()
    {
        $user         = Auth::user();

        $isSuperadmin = $user->hasAnyRole(['superadmin', 'admin']);

        $barangs      = $this->barangService->getAllBarang($user->id, $isSuperadmin);

        Pdf::setOptions(['isRemoteEnabled' => true]);

        // === Embed Logo ===
        $logoPath = 'logo_icon.png'; // file di storage/app/public/logo_icon.png
        if (Storage::disk('public')->exists($logoPath)) {
            $logoBin    = Storage::disk('public')->get($logoPath);
            $logoBase64 = base64_encode($logoBin);
            $logoExt    = pathinfo($logoPath, PATHINFO_EXTENSION);
            $logoSrc    = "data:image/{$logoExt};base64,{$logoBase64}";
        } else {
            $logoSrc = '';
        }

        // === Bangun HTML ===
        $html = "
        <html>
        <head>
          <meta charset='utf-8'>
          <title>Laporan Stok Barang</title>
          <style>
            body { font-family: sans-serif; margin: 20px; }
            .header { text-align: center; margin-bottom: 20px; }
            .header img { max-height: 80px; margin-bottom: 10px; }
            .header h1 { font-size: 24px; margin: 0; }
            table { width:100%; border-collapse: collapse; margin-top:10px; }
            th, td { border:1px solid #ccc; padding:8px; font-size:12px; }
            th { background-color:#f4f4f4; }
            tr:nth-child(even) td { background-color: #fafafa; }
            img.item-img { max-width:60px; max-height:60px; }
          </style>
        </head>
        <body>
          <div class='header'>";

        if ($logoSrc) {
            $html .= "<img src='{$logoSrc}' alt='Logo' />";
        }

        $html .= "
            <h1>Laporan Stok Barang</h1>
          </div>

          <table>
            <thead>
              <tr>
                <th>No</th>
                <th>Nama Barang</th>
                <th>Kode</th>
                <th>Gambar</th>
                <th>Gudang</th>
                <th>Stok Tersedia</th>
                <th>Stok Maintenance</th>
                <th>Stok Peminjaman</th>
              </tr>
            </thead>
            <tbody>";

        $no = 1;
        foreach ($barangs as $barang) {
            // embed gambar barang
            $relImg = $barang->barang_gambar;
            if (Storage::disk('public')->exists($relImg)) {
                $bin    = Storage::disk('public')->get($relImg);
                $ext    = pathinfo($relImg, PATHINFO_EXTENSION);
                $b64    = base64_encode($bin);
                $imgSrc = "data:image/{$ext};base64,{$b64}";
            } else {
                $imgSrc = '';
            }

            foreach ($barang->gudangs as $gudang) {
                $tersedia = $gudang->pivot->stok_tersedia   ?? 0;
                $maint    = $gudang->pivot->stok_maintenance ?? 0;
                $pinjam   = $gudang->pivot->stok_dipinjam    ?? 0;

                $html .= "
              <tr>
                <td>{$no}</td>
                <td>{$barang->barang_nama}</td>
                <td>{$barang->barang_kode}</td>
                <td><img class='item-img' src='{$imgSrc}' /></td>
                <td>{$gudang->name}</td>
                <td>{$tersedia}</td>
                <td>{$maint}</td>
                <td>{$pinjam}</td>
              </tr>";
                $no++;
            }
        }

        $html .= "
            </tbody>
          </table>
        </body>
        </html>";

        // Generate & simpan

        $pdf     = Pdf::loadHTML($html)->setPaper('a4', 'portrait');
        $pdfPath = 'laporan/laporan_stok.pdf';
        Storage::disk('public')->put($pdfPath, $pdf->output());

        return response()->json([
            'message' => 'Laporan stok PDF berhasil dibuat.',
            'pdf_url' => asset('storage/' . $pdfPath),
        ], 200);
    }

    public function exportStokExcel(Request $request)
    {
        $user         = Auth::user();
        $isSuperadmin = $user->hasAnyRole(['superadmin', 'admin']);
        $barangs      = $this->barangService->getAllBarang($user->id, $isSuperadmin);

        // 1. Pastikan folder ada
        $folder = storage_path('app/public/laporan');
        if (! File::isDirectory($folder)) {
            File::makeDirectory($folder, 0755, true);
        }

        // 2. Buat file dengan ekstensi .csv
        $filePath = $folder . '/laporan_stok.csv';
        $handle   = fopen($filePath, 'w');

        // 3. Header kolom (tanpa gambar)
        fputcsv($handle, [
            'No',
            'Nama Barang',
            'Kode',
            'Gudang',
            'Stok Tersedia',
            'Stok Maintenance',
            'Stok Peminjaman',
        ]);

        // 4. Isi data
        $no = 1;
        foreach ($barangs as $barang) {
            foreach ($barang->gudangs as $g) {
                fputcsv($handle, [
                    $no++,
                    $barang->barang_nama,
                    $barang->barang_kode,
                    $g->name,
                    $g->pivot->stok_tersedia   ?? 0,
                    $g->pivot->stok_maintenance ?? 0,
                    $g->pivot->stok_dipinjam    ?? 0,
                ]);
            }
        }

        fclose($handle);

        // 5. Kembalikan URL .csv
        return response()->json([
            'message'   => 'Laporan stok CSV berhasil dibuat.',
            'excel_url' => asset('storage/laporan/laporan_stok.csv'),
        ], 200);
    }


     public function laporantransaksi(Request $request)
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

        return TransactionResource::collection($query->paginate(10)->withQueryString());
    }

    
    public function generateTransaksiReportPdf(Request $request)
    {
        $query = \App\Models\Transaction::with([
            'user',
            'transactionType',
            'transactionDetails.barang',
            'transactionDetails.gudang'
        ]);
        if (! $request->user()->hasRole('superadmin')) {
            $query->where('user_id', $request->user()->id);
        }
        if ($request->filled('transaction_type_id')) {
            $query->where('transaction_type_id', $request->transaction_type_id);
        }
        if ($request->filled(['transaction_date_start', 'transaction_date_end'])) {
            $query->whereBetween('transaction_date', [
                $request->transaction_date_start,
                $request->transaction_date_end
            ]);
        }
        $transaksis = $query->get();

        $logoRel = 'logo_icon.png';
        if (Storage::disk('public')->exists($logoRel)) {
            $bin     = Storage::disk('public')->get($logoRel);
            $ext     = pathinfo($logoRel, PATHINFO_EXTENSION);
            $logoB64 = base64_encode($bin);
            $logoSrc = "data:image/{$ext};base64,{$logoB64}";
        } else {
            $logoSrc = '';
        }

        $html = "
    <html>
    <head>
      <meta charset='utf-8'>
      <title>Laporan Transaksi</title>
      <style>
        body { font-family: sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 20px; }
        .header img { max-height: 80px; margin-bottom: 10px; }
        .header h1 { font-size: 24px; margin: 0; }
        table { width:100%; border-collapse: collapse; margin-top:10px; }
        th, td { border:1px solid #ccc; padding:6px; font-size:12px; }
        th { background-color:#f4f4f4; }
        tr:nth-child(even) td { background-color:#fafafa; }
      </style>
    </head>
    <body>
      <div class='header'>";
        if ($logoSrc) {
            $html .= "<img src='{$logoSrc}' alt='Logo' />";
        }
        $html .= "<h1>Laporan Transaksi</h1>
      </div>
      <table>
        <thead>
          <tr>
            <th>No</th>
            <th>Kode</th>
            <th>Tanggal</th>
            <th>User</th>
            <th>Tipe</th>
            <th>Barang</th>
            <th>Gudang</th>
            <th>Qty</th>
            <th>Tgl Kembali</th>
            <th>Description</th>
          </tr>
        </thead>
        <tbody>";

        $no = 1;
        foreach ($transaksis as $trx) {
            foreach ($trx->transactionDetails as $det) {
                $tglKembali = $det->tanggal_kembali
                    ? date('Y-m-d H:i', strtotime($det->tanggal_kembali))
                    : '-';
                $desc = $trx->description ?? '-';
                $html .= "
          <tr>
            <td>{$no}</td>
            <td>{$trx->transaction_code}</td>
            <td>" . date('Y-m-d H:i', strtotime($trx->transaction_date)) . "</td>
            <td>{$trx->user->name}</td>
            <td>{$trx->transactionType->name}</td>
            <td>{$det->barang->barang_nama}</td>
            <td>{$det->gudang->name}</td>
            <td>{$det->quantity}</td>
            <td>{$tglKembali}</td>
            <td>{$desc}</td>
          </tr>";
                $no++;
            }
        }

        $html .= "
        </tbody>
      </table>
    </body>
    </html>";

        Pdf::setOptions(['isRemoteEnabled' => true]);
        $pdf     = Pdf::loadHTML($html)->setPaper('a4', 'landscape');
        $folder  = storage_path('app/public/laporan');
        if (! File::isDirectory($folder)) {
            File::makeDirectory($folder, 0755, true);
        }
        $fileName = 'laporan_transaksi.pdf';
        Storage::disk('public')->put("laporan/{$fileName}", $pdf->output());

        return response()->json([
            'message'  => 'Laporan transaksi PDF berhasil dibuat.',
            'pdf_url'  => asset('storage/laporan/' . $fileName),
        ], 200);
    }

    public function generateTransaksiTypeReportPdf(Request $request, $typeId)
    {
        $type = TransactionType::find($typeId);
        if (! $type) {
            return response()->json(['message' => 'Jenis transaksi tidak ditemukan'], 404);
        }
        $typeName = $type->name;
        $slug     = Str::slug(Str::lower($typeName));

        $query = Transaction::with([
            'user',
            'transactionType',
            'transactionDetails.barang',
            'transactionDetails.gudang'
        ])->where('transaction_type_id', $typeId);

        if (! $request->user()->hasRole('superadmin')) {
            $query->where('user_id', $request->user()->id);
        }
        if ($request->filled(['transaction_date_start', 'transaction_date_end'])) {
            $query->whereBetween('transaction_date', [
                $request->transaction_date_start,
                $request->transaction_date_end
            ]);
        }
        $transaksis = $query->get();

        $logoRel  = 'logo_icon.png';
        if (Storage::disk('public')->exists($logoRel)) {
            $bin     = Storage::disk('public')->get($logoRel);
            $ext     = pathinfo($logoRel, PATHINFO_EXTENSION);
            $logoB64 = base64_encode($bin);
            $logoSrc = "data:image/{$ext};base64,{$logoB64}";
        } else {
            $logoSrc = '';
        }

        $html = "
    <html>
    <head>
      <meta charset='utf-8'>
      <title>Laporan {$typeName}</title>
      <style>
        body { font-family: sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 20px; }
        .header img { max-height: 80px; margin-bottom: 10px; }
        .header h1 { font-size: 24px; margin: 0; }
        table { width:100%; border-collapse: collapse; margin-top:10px; }
        th, td { border:1px solid #ccc; padding:6px; font-size:12px; }
        th { background-color:#f4f4f4; }
        tr:nth-child(even) td { background-color:#fafafa; }
      </style>
    </head>
    <body>
      <div class='header'>";
        if ($logoSrc) {
            $html .= "<img src='{$logoSrc}' alt='Logo' />";
        }
        $html .= "<h1>Laporan Transaksi: {$typeName}</h1>
      </div>
      <table>
        <thead>
          <tr>
            <th>No</th>
            <th>Kode</th>
            <th>Tanggal</th>
            <th>User</th>
            <th>Barang</th>
            <th>Gudang</th>
            <th>Qty</th>
            <th>Tgl Kembali</th>
            <th>Description</th>
          </tr>
        </thead>
        <tbody>";

        $no = 1;
        foreach ($transaksis as $trx) {
            foreach ($trx->transactionDetails as $det) {
                $tglKembali = $det->tanggal_kembali
                    ? date('Y-m-d H:i', strtotime($det->tanggal_kembali))
                    : '-';
                $desc = $trx->description ?? '-';
                $html .= "
          <tr>
            <td>{$no}</td>
            <td>{$trx->transaction_code}</td>
            <td>" . date('Y-m-d H:i', strtotime($trx->transaction_date)) . "</td>
            <td>{$trx->user->name}</td>
            <td>{$det->barang->barang_nama}</td>
            <td>{$det->gudang->name}</td>
            <td>{$det->quantity}</td>
            <td>{$tglKembali}</td>
            <td>{$desc}</td>
          </tr>";
                $no++;
            }
        }

        $html .= "
        </tbody>
      </table>
    </body>
    </html>";

        Pdf::setOptions(['isRemoteEnabled' => true]);
        $pdf      = Pdf::loadHTML($html)->setPaper('a4', 'landscape');
        $dir      = storage_path('app/public/laporan');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }
        $fileName = "laporan_transaksi_{$slug}.pdf";
        Storage::disk('public')->put("laporan/{$fileName}", $pdf->output());

        return response()->json([
            'message' => "Laporan transaksi '{$typeName}' berhasil dibuat.",
            'pdf_url' => asset("storage/laporan/{$fileName}"),
        ], 200);
    }


    public function generateTransaksiTypeReportexcel(Request $request, $typeId)
    {
        // 1. Ambil jenis transaksi & slug
        $type = TransactionType::find($typeId);
        if (! $type) {
            return response()->json(['message' => 'Jenis transaksi tidak ditemukan'], 404);
        }
        $typeName = $type->name;                              // misal "Barang Masuk"
        $slug     = Str::slug(Str::lower($typeName));         // misal "barang-masuk"

        // 2. Query transaksi sesuai jenis (dan hak akses)
        $query = Transaction::with(['user', 'transactionDetails.barang', 'transactionDetails.gudang'])
            ->where('transaction_type_id', $typeId);

        if (! $request->user()->hasRole('superadmin')) {
            $query->where('user_id', $request->user()->id);
        }
        if ($request->filled(['transaction_date_start', 'transaction_date_end'])) {
            $query->whereBetween('transaction_date', [
                $request->transaction_date_start,
                $request->transaction_date_end
            ]);
        }
        $transaksis = $query->get();

        // 3. Pastikan folder penyimpanan ada
        $folder = storage_path('app/public/laporan');
        if (! File::isDirectory($folder)) {
            File::makeDirectory($folder, 0755, true);
        }

        // 4. Buka file .csv
        $fileName = "laporan_transaksi_{$slug}.csv";
        $filePath = "{$folder}/{$fileName}";
        $handle   = fopen($filePath, 'w');

        // 4.a Tulis BOM UTF-8 agar Excel mengenali
        fwrite($handle, "\xEF\xBB\xBF");

        // 5. Tulis header (delimiter ,)
        fputcsv($handle, [
            'No',
            'Kode',
            'Tanggal',
            'User',
            'Barang',
            'Gudang',
            'Qty',
            'Tgl Kembali',
            'Description',
        ]);

        // 6. Isi setiap baris
        $no = 1;
        foreach ($transaksis as $trx) {
            foreach ($trx->transactionDetails as $det) {
                $tglKembali = $det->tanggal_kembali
                    ? date('Y-m-d H:i', strtotime($det->tanggal_kembali))
                    : '-';
                fputcsv($handle, [
                    $no++,
                    $trx->transaction_code,
                    date('Y-m-d H:i', strtotime($trx->transaction_date)),
                    $trx->user->name,
                    $det->barang->barang_nama,
                    $det->gudang->name,
                    $det->quantity,
                    $tglKembali,
                    $trx->description,
                ]);
            }
        }

        fclose($handle);

        // 7. Kembalikan JSON dengan link unduh
        return response()->json([
            'message'   => "Laporan transaksi '{$typeName}' (CSV) berhasil dibuat.",
            'excel_url'   => asset("storage/laporan/{$fileName}"),
        ], 200);
    }

    public function generateAllTransaksiexcel(Request $request)
    {
        // 1. Ambil semua transaksi (filtered by role)
        $query = Transaction::with([
            'user',
            'transactionType',
            'transactionDetails.barang',
            'transactionDetails.gudang'
        ]);
        if (! $request->user()->hasRole('superadmin')) {
            $query->where('user_id', $request->user()->id);
        }
        // Tambahkan filter tanggal jika ada
        if ($request->filled(['transaction_date_start', 'transaction_date_end'])) {
            $query->whereBetween('transaction_date', [
                $request->transaction_date_start,
                $request->transaction_date_end
            ]);
        }
        $transaksis = $query->get();

        // 2. Pastikan folder penyimpanan ada
        $folder = storage_path('app/public/laporan');
        if (! File::isDirectory($folder)) {
            File::makeDirectory($folder, 0755, true);
        }

        // 3. Buka file CSV
        $fileName = 'laporan_transaksi_all.csv';
        $filePath = "{$folder}/{$fileName}";
        $handle   = fopen($filePath, 'w');

        // 3.a Sisipkan BOM UTF-8
        fwrite($handle, "\xEF\xBB\xBF");

        // 4. Tulis header (delimiter ,)
        fputcsv($handle, [
            'No',
            'Kode',
            'Tanggal',
            'User',
            'Tipe',
            'Barang',
            'Gudang',
            'Qty',
            'Tgl Kembali',
            'Description',
        ]);

        // 5. Isi setiap baris
        $no = 1;
        foreach ($transaksis as $trx) {
            foreach ($trx->transactionDetails as $det) {
                $tglKembali = $det->tanggal_kembali
                    ? date('Y-m-d H:i', strtotime($det->tanggal_kembali))
                    : '-';
                fputcsv($handle, [
                    $no++,
                    $trx->transaction_code,
                    date('Y-m-d H:i', strtotime($trx->transaction_date)),
                    $trx->user->name,
                    $trx->transactionType->name,
                    $det->barang->barang_nama,
                    $det->gudang->name,
                    $det->quantity,
                    $tglKembali,
                    $trx->description,
                ]);
            }
        }

        fclose($handle);

        // 6. Kembalikan JSON dengan link unduh
        return response()->json([
            'message'   => 'Laporan transaksi keseluruhan (CSV) berhasil dibuat.',
            'excel_url'   => asset("storage/laporan/{$fileName}"),
        ], 200);
    }
}
