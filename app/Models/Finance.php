<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Model;

class Finance extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    public function contact()
    {
        return $this->belongsTo(Contact::class);
    }

    public function account()
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_code', 'id');
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class, 'invoice', 'invoice');
    }

    public function journal()
    {
        return $this->belongsTo(Journal::class);
    }

    public function generateFinanceInvoice($contact_id, $type)
    {
        $prefix = $type == 'Payable' ? 'PY' : 'RC';

        $lastCode = self::where('contact_id', $contact_id)
            ->where('type', $type)
            ->selectRaw('MAX(RIGHT(code, 4)) AS lastCode')
            ->value('lastCode');

        $nextCode = str_pad((int) $lastCode + 1, 7, '0', STR_PAD_LEFT);

        return $prefix . '-BK-' . date('dmY') . '-' . $contact_id . '-' . $nextCode;
    }
}
