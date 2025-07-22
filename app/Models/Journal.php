<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    protected $guarded = [
        'id',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'fee_amount' => 'float',
        'debt_code' => 'integer',
        'cred_code' => 'integer',
    ];

    public function scopeFilterJournals($query, array $filters)
    {
        $query->when(!empty($filters['search']), function ($query) use ($filters) {
            $search = $filters['search'];
            $query->where(function ($query) use ($search) {
                $query->where('invoice', 'like', '%' . $search . '%')
                    ->orWhere('description', 'like', '%' . $search . '%')
                    ->orWhere('cred_code', 'like', '%' . $search . '%')
                    ->orWhere('debt_code', 'like', '%' . $search . '%')
                    ->orWhere('date_issued', 'like', '%' . $search . '%')
                    ->orWhere('trx_type', 'like', '%' . $search . '%')
                    ->orWhere('status', 'like', '%' . $search . '%')
                    ->orWhere('amount', 'like', '%' . $search . '%')
                    ->orWhere('fee_amount', 'like', '%' . $search . '%')
                    ->orWhereHas('debt', function ($query) use ($search) {
                        $query->where('acc_name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('cred', function ($query) use ($search) {
                        $query->where('acc_name', 'like', '%' . $search . '%');
                    });
            });
        });
    }

    public function scopeFilterAccounts($query, array $filters)
    {
        $query->when(!empty($filters['account']), function ($query) use ($filters) {
            $account = $filters['account'];
            $query->where('cred_code', $account)->orWhere('debt_code', $account);
        });
    }

    public function scopeFilterMutation($query, array $filters)
    {
        $query->when($filters['searchHistory'] ?? false, function ($query, $search) {
            $query->where(function ($query) use ($search) {
                $query->whereHas('debt', function ($q) use ($search) {
                    $q->where('acc_name', 'like', '%' . $search . '%');
                })
                    ->orWhereHas('cred', function ($q) use ($search) {
                        $q->where('acc_name', 'like', '%' . $search . '%');
                    });
            });
        });
    }

    public function debt()
    {
        return $this->belongsTo(ChartOfAccount::class, 'debt_code', 'id');
    }

    public function cred()
    {
        return $this->belongsTo(ChartOfAccount::class, 'cred_code', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function transaction()
    {
        return $this->hasMany(Transaction::class, 'invoice', 'invoice');
    }

    public function entries()
    {
        return $this->hasMany(JournalEntry::class);
    }

    public static function generateJournalInvoice($prefix, $table, $condition = [])
    {
        $userId = auth()->user()->id;
        $today = now()->toDateString();

        return DB::transaction(function () use ($prefix, $table, $condition, $userId, $today) {
            $lastInvoice = DB::table($table)
                ->lockForUpdate()
                ->where($condition)
                ->where('user_id', $userId)
                ->whereDate('created_at', $today)
                ->max(DB::raw('CAST(SUBSTRING_INDEX(invoice, ".", -1) AS UNSIGNED)')); // Ambil angka terakhir

            $nextNumber = $lastInvoice ? $lastInvoice + 1 : 1;

            $newInvoice = implode('.', [
                $prefix,                    // Contoh: JRN
                now()->format('dmY'),      // Tanggal: 22072025
                $userId,                   // User ID
                str_pad($nextNumber, 7, '0', STR_PAD_LEFT) // Nomor: 0000001
            ]);

            return $newInvoice;
        });
    }

    public static function sales_journal()
    {
        return self::generateJournalInvoice('SO.BK', 'transactions', [['transaction_type', '=', 'Sales'], ['satatus', '=', 3]]);
    }

    public static function order_journal()
    {
        $userId = auth()->user()->id;
        $today = now()->toDateString();

        $lastInvoice = Transaction::where(function ($query) {
            $query->where('transaction_type', 'Sales')
                ->orWhere('transaction_type', 'Order');
        })
            ->where('user_id', $userId)
            ->whereDate('created_at', $today)
            ->max(DB::raw('CAST(SUBSTRING_INDEX(invoice, ".", -1) AS UNSIGNED)')); // Ambil angka terakhir

        $nextNumber = $lastInvoice ? $lastInvoice + 1 : 1;

        return 'RO.BK' . now()->format('dmY') . '.' . $userId . '.' . str_pad($nextNumber, 7, '0', STR_PAD_LEFT);
    }


    public static function purchase_journal()
    {
        // Untuk purchase journal, kita menambahkan kondisi agar hanya mengembalikan yang quantity > 0
        return self::generateJournalInvoice('PO.BK', 'transactions', [['quantity', '>', 0], ['transaction_type', '=', 'Purchase']]);
    }

    public static function endBalanceBetweenDate($account_code, $start_date, $end_date)
    {
        $initBalance = ChartOfAccount::with('account')->where('id', $account_code)->first();

        $transactions = self::where(function ($query) use ($account_code) {
            $query
                ->where('debt_code', $account_code)
                ->orWhere('cred_code', $account_code);
        })
            ->whereBetween('date_issued', [
                $start_date,
                $end_date,
            ])
            ->get();

        $debit = $transactions->where('debt_code', $account_code)->sum('amount');
        $credit = $transactions->where('cred_code', $account_code)->sum('amount');

        if ($initBalance->account->status == "D") {
            return $initBalance->st_balance + $debit - $credit;
        } else {
            return $initBalance->st_balance + $credit - $debit;
        }
    }

    public static function equityCount($end_date, $includeEquity = true)
    {
        $coa = ChartOfAccount::all();

        foreach ($coa as $coaItem) {
            $coaItem->balance = self::endBalanceBetweenDate($coaItem->acc_code, '0000-00-00', $end_date);
        }

        $initBalance = $coa->where('acc_code', '30100-001')->first()->st_balance;
        $assets = $coa->whereIn('account_id', \range(1, 18))->sum('balance');
        $liabilities = $coa->whereIn('account_id', \range(19, 25))->sum('balance');
        $equity = $coa->where('account_id', 26)->sum('balance');

        // Use Eloquent to update a specific record
        ChartOfAccount::where('acc_code', '30100-001')->update(['st_balance' => $initBalance + $assets - $liabilities - $equity]);

        // Return the calculated equity
        return ($includeEquity ? $initBalance : 0) + $assets - $liabilities - ($includeEquity ? $equity : 0);
    }

    public function profitLossCount($start_date, $end_date)
    {
        // Use relationships if available
        $start_date = Carbon::parse($start_date)->copy()->startOfDay();
        $end_date = Carbon::parse($end_date)->copy()->endOfDay();

        $coa = ChartOfAccount::with('account')->whereIn('account_id', \range(27, 45))->get();

        $transactions = $this->selectRaw('debt_code, cred_code, SUM(amount) as total')
            ->whereBetween('date_issued', [$start_date, $end_date])
            ->groupBy('debt_code', 'cred_code')
            ->get();

        foreach ($coa as $value) {
            $debit = $transactions->where('debt_code', $value->acc_code)->sum('total');
            $credit = $transactions->where('cred_code', $value->acc_code)->sum('total');

            $value->balance = ($value->account->status == "D") ? ($value->st_balance + $debit - $credit) : ($value->st_balance + $credit - $debit);
        }

        // Use collections for filtering
        $revenue = $coa->whereIn('account_id', \range(27, 30))->sum('balance');
        $cost = $coa->whereIn('account_id', \range(31, 32))->sum('balance');
        $expense = $coa->whereIn('account_id', \range(33, 45))->sum('balance');

        // Use Eloquent to update a specific record if it exists
        $specificRecord = ChartOfAccount::where('acc_code', '30100-002')->first();
        if ($specificRecord) {
            $specificRecord->update(['st_balance' => $revenue - $cost - $expense]);
        }

        // Return the calculated profit or loss
        return $revenue - $cost - $expense;
    }

    public function cashflowCount($start_date, $end_date)
    {
        $cashAccount = ChartOfAccount::all();

        $transactions = $this->selectRaw('debt_code, cred_code, SUM(amount) as total')
            ->whereBetween('date_issued', [$start_date, $end_date])
            ->groupBy('debt_code', 'cred_code')
            ->get();

        foreach ($cashAccount as $value) {
            $debit = $transactions->where('debt_code', $value->acc_code)->sum('total');

            $credit = $transactions->where('cred_code', $value->acc_code)->sum('total');

            $value->balance = $debit - $credit;
        }

        $result = $cashAccount->whereIn('account_id', [1, 2])->sum('balance');

        return $result;
    }
}
