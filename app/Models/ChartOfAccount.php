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
}
