<?php

            namespace App\Http\Controllers;
            
            use Illuminate\Http\Request;
            use App\Models\Produk;
            use App\Models\Customer;
            use App\Models\Pembelian;
            use App\Models\Transaction_detail;
            use Illuminate\Support\Facades\DB;
            use Barryvdh\DomPDF\Facade\PDF;
            use App\Exports\CustomerExport;
            use Maatwebsite\Excel\Facades\Excel;
            use Illuminate\Support\Facades\Auth;
            use Carbon\Carbon;
            
            class PembelianController extends Controller
            {
                /**
                 * Display a listing of the resource.
                 */
                public function index(Request $request)
            {
                $search = $request->input('search');
                $perPage = $request->input('per_page', 10); // default 10 jika tidak diset
            
                if (Auth::user()->role == 'admin') {
                    $sale = Pembelian::with('customer', 'details.produk', 'user')
                        ->when($search, function ($query, $search) {
                            return $query->whereHas('customer', function ($query) use ($search) {
                                $query->where('nama', 'like', "%{$search}%");
                            })
                            ->orWhere('total_price', 'like', "%{$search}%")
                            ->orWhereDate('created_at', 'like', "%{$search}%");
                        })
                        ->paginate($perPage)
                        ->appends(['search' => $search, 'per_page' => $perPage]); // Tambah untuk persist query string
                } else {
                    $sale = Pembelian::with('customer', 'details.produk', 'user')
                        ->where('user_id', Auth::id())
                        ->when($search, function ($query, $search) {
                            return $query->whereHas('customer', function ($query) use ($search) {
                                $query->where('nama', 'like', "%{$search}%");
                            })
                            ->orWhere('total_price', 'like', "%{$search}%")
                            ->orWhereDate('created_at', 'like', "%{$search}%");
                        })
                        ->paginate($perPage)
                        ->appends(['search' => $search, 'per_page' => $perPage]);
                }
            
                return view('pembelian.index', compact('sale', 'search', 'perPage'));
            }
            
                
            
                /**
                 * Show the form for creating a new resource.
                 */
                public function create()
                {
                    $data = Produk::all();
                    return view('pembelian.create', compact('data'));
                }
            
                public function sale(Request $request)
                {
                    if (!$request->has('products')) {
                        return redirect()->back()->with('error', 'Tidak ada produk yang dipilih.');
                    }
                
                    $products = $request->input('products');
                    
                    $parsedProducts = [];
                    $total = 0;
                
                    foreach ($products as $productString) {
                        list($id, $name, $price, $quantity, $subtotal) = explode(';', $productString);
                        
                        $price = (int) $price;
                        $quantity = (int) $quantity;
                        $subtotal = (int) $subtotal;
                
                        $parsedProducts[] = [
                            'id' => $id,
                            'name' => $name,
                            'price' => $price,
                            'quantity' => $quantity,
                            'subtotal' => $subtotal
                        ];
                        
                        $total += $subtotal;
                    }
                
                    session(['selected_products' => $parsedProducts, 'total' => $total]);
                
                    return view('pembelian.sale', [
                        'products' => $parsedProducts,
                        'total' => $total
                    ]);
                }
                
                /**
                 * Store a newly created resource in storage.
                 */
                public function store(Request $request)
                { 
                    DB::beginTransaction();
                    try {
                        $transaction = new Pembelian();
                        $transaction->user_id = auth()->id();
                        $transaction->total_price = $request->total;
                        $transaction->total_payment = str_replace(['Rp.', '.', ','], '', $request->total_pay);
                        $transaction->total_return = $transaction->total_payment - $transaction->total_price;
                        $transaction->point = round($transaction->total_price / 100);
                        $transaction->save();
            
                        foreach ($request->products as $product) {
                            $productData = explode(';', $product);
                            $detail = new Transaction_detail();
                            $detail->transaction_id = $transaction->id;
                            $detail->produk_id = $productData[0];
                            $detail->quantity = $productData[3];
                            $detail->sub_total = $productData[4];
                            $detail->save();
            
                            $produk = Produk::find($productData[0]);
                            if ($produk) {
                                $produk->stok -= $productData[3];
                                $produk->save();
                            }
                        }
            
                        if ($request->member == 'Member') {
                            $customer = Customer::where('no_hp', $request->no_hp)->first();
                        
                            if (!$customer) {
                                $customer = new Customer();
                                $customer->no_hp = $request->no_hp;
                                $customer->total_point = 0;
                                $customer->save(); 
                            }
                        
                            $transactionCount = Pembelian::where('customer_id', $customer->id)->count();
                            if ($transactionCount == 0) {
                                $request->merge(['check_poin' => 'Tidak']); 
                            }
                    
                            if ($request->check_poin == 'Ya' && $transactionCount > 0) {
                                $poinDigunakan = $request->poin_digunakan; 
                                $customer->total_point -= $poinDigunakan;
                                $customer->save();
                            }
                            \Log::info('Total point sebelum ditambah: ' . $customer->total_point);
                            \Log::info('Point dari transaksi: ' . $transaction->point);
                        
                            $customer->total_point += $transaction->point;
                        
                            \Log::info('Total point setelah ditambah: ' . $customer->total_point);
                        
                            $customer->save();
                        
                            $transaction->customer_id = $customer->id;
                            $transaction->save();
                        
                            DB::commit();
                            return redirect()->route('pembelian.member', $transaction->id);
                        } else {
                            DB::commit();
                            return redirect()->route('pembelian.show', $transaction->id);
                        }
                    } catch (\Exception $e) {
                        DB::rollBack();
                        return redirect()->back()->with('error', 'Terjadi kesalahan: ' . $e->getMessage());
                    }
                }
            
                /**
                 * Display the specified resource.
                 */
                public function show($id)
                {
                    $transaction = Pembelian::with('customer')->findOrFail($id);
                    $transactionDetails = Transaction_detail::where('transaction_id', $id)->get();     
            
                    return view('pembelian.sale_detail', [
                        'transaction' => $transaction,
                        'transactionDetails' => $transactionDetails,
                        'customer' => $transaction->customer,
                    ]); 
                }
            
                public function showMember($id)
                {
                    $transaction = Pembelian::findOrFail($id);
                    $transactionDetails = Transaction_detail::where('transaction_id', $id)->get();
                    $customer = $transaction->customer;
                    $transactionCount = Pembelian::where('customer_id', $customer->id)->count();
            
                    return view('pembelian.member', [
                        'transaction' => $transaction,
                        'transactionDetails' => $transactionDetails,
                        'transactionCount' => $transactionCount,
                        'customer' => $customer,
                    ]);
                }
            
                /**
                 * Show the form for editing the specified resource.
                 */
                public function memberStore(Request $request)
                {
                    $request->validate([
                        'nama' => 'required|string|max:100',
                        'no_hp' => 'required|string|max:12',
                        'transaction_id' => 'required|exists:pembelians,id',
                        'check_poin' => 'nullable|in:Ya', 
                    ]);
            
                    $customer = Customer::updateOrCreate(
                        ['no_hp' => $request->no_hp],
                        [
                            'nama' => $request->nama,
                        ]
                    );
            
                    $transaction = Pembelian::find($request->transaction_id);
                    if (!$transaction) {
                        return redirect()->back()->with('error', 'Transaksi tidak ditemukan.');
                    }
            
                    if ($request->check_poin == 'Ya') {
                        $used_point = $customer->total_point;
                    
                        $customer->total_point = 0; 
                        $customer->save();
                    
                        $transaction->used_point = $used_point;
                        $transaction->total_price -= $used_point;
                        
                        // Perhitungan ulang total_return dengan harga setelah dipotong poin
                        $transaction->total_return = $transaction->total_payment - $transaction->total_price;
                    }
            
                    $transaction->customer_id = $customer->id;
                    $transaction->save();
            
                    return redirect()->route('pembelian.show', $transaction->id)->with('success', 'Transaksi berhasil disimpan.');
                }
            
                public function exportPdf($id)
                {
                    $transaction = Pembelian::with(['customer', 'user'])->findOrFail($id);
                    $transactionDetails = Transaction_detail::where('transaction_id', $id)->with('produk')->get();
                    $hargaBeforePoint = $transaction->total_price + $transaction->used_point;
            
                    $pdf = PDF::loadView('pembelian.invoice', compact('transaction', 'transactionDetails', 'hargaBeforePoint'));
            
                    return $pdf->download('invoice-' . $id . '.pdf');
            
                }
            
                public function exportExcel()
                {
                    return Excel::download(new CustomerExport, 'Laporan-Pembelian.xlsx');
                }
            
                /**
                 * Update the specified resource in storage.
                 */
                public function detail(Request $request, string $id)
                {
                    // $transaction = Transaction::with(['customer', 'user'])->findOrFail($id);
                    $transactionDetails = Transaction_detail::where('transaction_id', $id)->with('produk')->get();
            
                    return view('pembelian.index', compact('transaction', 'transactionDetails'));
                }
            
                /**
                 * Remove the specified resource from storage.
                 */
               public function showDasboard()
{
    $todaySalesCount = null;
    $lastTransactionTime = null;
    $chartData = [];
    
    if (Auth::user()->role == 'employe') {
        $todaySalesCount = Pembelian::whereDate('created_at', Carbon::today())->count();
        
        $lastTransaction = Pembelian::latest()->first();
        $lastTransactionTime = $lastTransaction ? $lastTransaction->created_at->format('d M Y H:i') : 'Belum ada transaksi';
    }
    
    $startDate = Carbon::now()->subDays(12)->startOfDay();
    $endDate = Carbon::now()->endOfDay();
    
    $transactions = DB::table('pembelians')
        ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
        ->whereBetween('created_at', [$startDate, $endDate])
        ->groupBy('date')
        ->orderBy('date', 'asc')
        ->get()
        ->keyBy('date');
    
    for ($i = 0; $i < 13; $i++) {
        $date = Carbon::now()->subDays(12 - $i)->toDateString();
        $chartData[] = [
            'date' => Carbon::parse($date)->format('d F Y'),
            'count' => $transactions->has($date) ? $transactions[$date]->count : 0
        ];
    }
    
    // Tambahkan perhitungan jumlah member dan non-member untuk pembelian hari ini
    $memberSalesCount = Pembelian::whereNotNull('customer_id')
        ->whereDate('created_at', Carbon::today())
        ->count();
    
    $nonMemberSalesCount = Pembelian::whereNull('customer_id')
        ->whereDate('created_at', Carbon::today())
        ->count();
    
    // Ambil data penjualan produk untuk pie chart
    $productSales = DB::table('transaction_details')
        ->join('produks', 'transaction_details.produk_id', '=', 'produks.id')
        ->select('produks.nama_produk', DB::raw('SUM(transaction_details.quantity) as total_terjual'))
        ->groupBy('produks.nama_produk')
        ->orderByDesc('total_terjual')
        ->get();
    
    $productLabels = $productSales->pluck('nama_produk');
    $productData = $productSales->pluck('total_terjual');
    
    return view('home', compact(
        'todaySalesCount',
        'lastTransactionTime',
        'chartData',
        'productLabels',
        'productData',
        'memberSalesCount',
        'nonMemberSalesCount'
    ));
}

                
            }