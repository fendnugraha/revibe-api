<?php

namespace App\Models;

use App\Models\Account;
use App\Models\Journal;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class ChartOfAccount extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'acc_code' => 'string',
        'acc_name' => 'string',
        'account_id' => 'integer',
        'warehouse_id' => 'integer',
        'st_balance' => 'integer',
    ];

    const MODAL_EQUITY = 13;
    const INVENTORY = 10;
    const INCOME_FROM_SALES = 16;
    const PURCHASE_DISCOUNT = 18;
    const COST_OF_GOODS_SOLD = 21;
    const SALES_DISCOUNT = 44;
    const DEFAULT_RECEIVABLE = 7;
    const DEFAULT_PAYABLE = 11;
    const SHIPPING_EXPENSE = 40;

    public function entries()
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function entriesWithJournal()
    {
        return $this->hasMany(JournalEntry::class, 'chart_of_account_id')
            ->join('journals', 'journal_entries.journal_id', '=', 'journals.id')
            ->select('journal_entries.*', 'journals.date_issued');
    }


    public function acc_code($account_id)
    {
        $accounts = Account::find($account_id);

        $lastCode = DB::table('chart_of_accounts')
            ->select(DB::raw('MAX(RIGHT(acc_code,3)) AS lastCode'))
            ->where('account_id', $account_id)
            ->get();

        if ($lastCode[0]->lastCode != null) {
            $kd = $lastCode[0]->lastCode + 1;
        } else {
            $kd = "001";
        }

        return $accounts->code . '-' . \sprintf("%03s", $kd);
    }
    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function updateInitEquityBalance()
    {
        $assets = $this->whereIn('account_id', \range(1, 18))->sum('st_balance');
        $liabilities = $this->whereIn('account_id', \range(19, 25))->sum('st_balance');
        $equity = $this->where('account_id', 26)->where('acc_code', '!=', '30100-001')->sum('st_balance');

        $this->where('acc_code', '30100-001')->update(['st_balance' => $assets - $liabilities - $equity]);

        return $assets - $liabilities - $equity;
    }
}
