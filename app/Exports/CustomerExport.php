<?php

            namespace App\Exports;
            
            use App\Models\Transaction_Detail;
            use App\Models\Customer;
            use App\Models\Pembelian;
            use App\Models\Produk;
            use Maatwebsite\Excel\Concerns\FromCollection;
            use Maatwebsite\Excel\Concerns\WithHeadings;
            use Maatwebsite\Excel\Concerns\WithMapping;
            
            class CustomerExport implements FromCollection, WithHeadings, WithMapping
            {
                public function collection()
                {
                    return Pembelian::with(['customer', 'details.produk'])->get();
                }
            
                public function headings(): array
                {
                    return [
                        'Nama Pelanggan',
                        'No HP Pelanggan',
                        'Poin Pelanggan',
                        'Produk',
                        'Total Harga',
                        'Total Bayar',
                        'Total Diskon Poin',
                        'Total Kembalian',
                        'Tanggal Pembelian'
                    ];
                }
            
                public function map($transaction): array
                {
                    $products = $transaction->details->map(function ($detail) {
                        return $detail->produk->nama_produk . ' (' . $detail->quantity . ' : Rp ' . number_format($detail->sub_total, 2, ',', '.') . ')';
                    })->implode(', ');
            
                    return [
                        $transaction->customer->nama ?? 'Bukna Member',
                        $transaction->customer->no_hp ?? '-',
                        $transaction->customer->total_point ?? 0,
                        $products,
                        'Rp ' . number_format($transaction->total_price, 2, ',', '.'),
                        'Rp ' . number_format($transaction->total_payment, 2, ',', '.'),
                        'Rp ' . number_format($transaction->used_point, 2, ',', '.'),
                        'Rp ' . number_format($transaction->total_return, 2, ',', '.'),
                        $transaction->created_at->format('d-m-Y'),
                    ];
                }
            }