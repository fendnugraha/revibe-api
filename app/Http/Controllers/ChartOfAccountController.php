<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Account;
use App\Models\Journal;
use Illuminate\Http\Request;
use App\Models\AccountBalance;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\DataResource;

class ChartOfAccountController extends Controller
{
    public $startDate;
    public $endDate;
    protected $appends = ['balance'];

    /**
     * Display a listing of the resource.
     */
    public function __construct()
    {
        $this->startDate = Carbon::now()->startOfMonth();
        $this->endDate = Carbon::now()->endOfMonth();
    }

    public function index(Request $request)
    {
        $chartOfAccounts = ChartOfAccount::with(['account', 'warehouse'])
            ->when($request->search, function ($query, $search) {
                $query->where('acc_name', 'like', '%' . $search . '%')
                    ->orWhere('acc_code', 'like', '%' . $search . '%');
            })
            ->orderBy('acc_code')->paginate(10)->onEachSide(0);
        return new DataResource($chartOfAccounts, true, "Successfully fetched chart of accounts");
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $chartOfAccount = new ChartOfAccount();
        $request->validate(
            [
                'category_id' => 'required',  // Make sure category_id is present
                'name' => 'required|string|max:255|unique:chart_of_accounts,acc_name',
                'st_balance' => 'nullable|numeric',  // Allow st_balance to be nullable
            ],
            [
                'category_id.required' => 'Category account tidak boleh kosong.',
                'name.required' => 'Nama akun harus diisi.',
                'name.unique' => 'Nama akun sudah digunakan, silakan pilih nama lain.',
            ]
        );

        $chartOfAccount->create([
            'acc_code' => $chartOfAccount->acc_code($request->category_id),
            'acc_name' => $request->name,
            'account_id' => $request->category_id,
            'st_balance' => $request->st_balance ?? 0,
        ]);

        return response()->json([
            'message' => 'Chart of account created successfully',
            'chart_of_account' => $chartOfAccount
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $chartOfAccount = ChartOfAccount::with(['account', 'warehouse'])->find($id);
        return new DataResource($chartOfAccount, true, "Successfully fetched chart of account");
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ChartOfAccount $chartOfAccount)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request)
    {
        $chartOfAccount = ChartOfAccount::find($request->id);
        $request->validate(
            [
                'id' => 'required|exists:chart_of_accounts,id',
                'acc_name' => 'required|string|max:255|unique:chart_of_accounts,acc_name,' . $chartOfAccount->id,
                'st_balance' => 'nullable|numeric',
            ],
            [
                'acc_name.required' => 'Nama akun harus diisi.',
                'acc_name.unique' => 'Nama akun sudah digunakan, silakan pilih nama lain. ID:' . $chartOfAccount->id,
            ]
        );

        try {
            $chartOfAccount->update([
                'acc_name' => $request->acc_name,
                'st_balance' => $request->st_balance ?? 0,
            ]);

            $chartOfAccount->updateInitEquityBalance();
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            AccountBalance::truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            return response()->json([
                'message' => 'Chart of account updated successfully',
                'chart_of_account' => $chartOfAccount
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to update chart of account: ' . $e->getMessage());

            return response()->json([
                'message' => 'Failed to update chart of account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $chartOfAccount = ChartOfAccount::find($id);

        if ($chartOfAccount->is_locked) {
            return response()->json([
                'message' => 'Chart of account is locked and cannot be deleted.',
            ], 403);
        }

        if (!$chartOfAccount) {
            return response()->json([
                'message' => 'Chart of account not found.',
            ], 404); // Return a 404 error if not found
        }

        try {
            $journalExists = Journal::where('debt_code', $chartOfAccount->acc_code)
                ->orWhere('cred_code', $chartOfAccount->acc_code)
                ->exists();

            if ($journalExists) {
                return response()->json([
                    'message' => 'Chart of account cannot be deleted because it is used in a journal entry.',
                ], 400);
            }
            // Deleting the Chart of Account
            $chartOfAccount->delete();

            // Return a success response
            return response()->json([
                'message' => 'Chart of account deleted successfully',
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to delete chart of account. ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getAccountCategories()
    {
        $accounts = Account::all();
        return new DataResource($accounts, true, "Successfully fetched accounts");
    }

    public function getCashAndBankByWarehouse($warehouse)
    {
        $chartOfAccounts = ChartOfAccount::with('warehouse')->where('warehouse_id', $warehouse)->orderBy('acc_code', 'asc')->get();
        return response()->json([
            'success' => true,
            'message' => 'Successfully fetched chart of accounts',
            'data' => $chartOfAccounts
        ]);
    }

    public function deleteAll(Request $request)
    {
        // Retrieve the records that are about to be deleted
        $accounts = ChartOfAccount::whereIn('id', $request->ids)->get();

        // Check if any of the records are locked
        $lockedAccounts = $accounts->filter(function ($account) {
            return $account->is_locked;
        });

        if ($lockedAccounts->isNotEmpty()) {
            return response()->json(
                [
                    'message' => 'Some chart of accounts are locked and cannot be deleted.',
                    'locked_accounts' => $lockedAccounts->pluck('id'), // Optionally return the ids of locked accounts
                ],
                403
            );
        }

        // Perform the deletion if no accounts are locked
        $deletedCount = ChartOfAccount::whereIn('id', $request->ids)->delete();

        return response()->json([
            'message' => 'All chart of accounts deleted successfully',
            'deleted_count' => $deletedCount
        ], 200);
    }

    public function getCashAndBank()
    {
        $chartOfAccounts = ChartOfAccount::with('warehouse')->whereIn('account_id', [1, 2])->orderBy('acc_code', 'asc')->get();
        return new DataResource($chartOfAccounts, true, "Successfully fetched chart of accounts");
    }

    public function balanceSheetReport($endDate)
    {
        $endDate = now()->endOfDay();

        $accounts = ChartOfAccount::with(['entriesWithJournal' => function ($query) use ($endDate) {
            $query->where('journals.date_issued', '<=', $endDate)->where('status', 'Active');
        }, 'account'])->get();

        $assets = $accounts->whereIn('account_id', \range(1, 18))->groupBy('account_id');
        $liabilities = $accounts->whereIn('account_id', \range(19, 25))->groupBy('account_id');
        $equity = $accounts->where('account_id', 26)->groupBy('account_id');

        function calculateBalanceBs($acc)
        {
            $entries = collect($acc['entriesWithJournal']);
            $balanceFromJournal = $acc->account->status == "D"
                ? $entries->sum('debit') - $entries->sum('credit')
                : $entries->sum('credit') - $entries->sum('debit');

            return $acc->st_balance + $balanceFromJournal;
        }


        $data = collect([
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
        ])->map(function ($group) {
            return [
                'total' => $group->flatten(1)->sum(fn($acc) => calculateBalanceBs($acc)) ?? 0,
                'accounts' => $group->map(function ($accounts) {
                    $first = $accounts[0];
                    $accGroupName = $first['account']['name'];

                    return [
                        'acc_name' => $accGroupName,
                        'balance' => collect($accounts)->sum(fn($acc) => calculateBalanceBs($acc)) ?? 0,
                        'coa' => collect($accounts)->map(function ($acc) {
                            return [
                                'acc_name' => $acc['acc_name'],
                                'balance' => calculateBalanceBs($acc) ?? 0,
                            ];
                        })->values()->toArray(),
                    ];
                })->values()->toArray(),
            ];
        })->toArray();

        $profitloss = [
            'profitLoss' => Journal::profitLossCount('2020-01-01', $endDate),
        ];
        $balanceSheet = array_merge($data, $profitloss);

        return response()->json([
            'success' => true,
            'message' => 'Successfully fetched profit and loss',
            'data' => $balanceSheet
        ]);
    }

    public function profitLossReport($startDate, $endDate)
    {
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $accounts = ChartOfAccount::with(['entriesWithJournal' => function ($query) use ($startDate, $endDate) {
            $query->whereBetween('journals.date_issued', [$startDate, $endDate]);
        }, 'account'])->get();

        $revenue = $accounts->whereIn('account_id', \range(27, 30))->groupBy('account_id');
        $cost = $accounts->whereIn('account_id', \range(31, 32))->groupBy('account_id');
        $expense = $accounts->whereIn('account_id', \range(33, 45))->groupBy('account_id');

        function calculateBalance($acc)
        {
            $entries = collect($acc['entriesWithJournal']);
            return $acc->account->status == "D"
                ? $entries->sum('debit') - $entries->sum('credit')
                : $entries->sum('credit') - $entries->sum('debit');
        }

        $profitLoss = collect([
            'revenue' => $revenue,
            'cost' => $cost,
            'expense' => $expense,
        ])->map(function ($group) {
            return [
                'total' => $group->flatten(1)->sum(fn($acc) => calculateBalance($acc)),
                'accounts' => $group->map(function ($accounts) {
                    $first = $accounts[0];
                    $accGroupName = $first['account']['name'];

                    return [
                        'acc_name' => $accGroupName,
                        'balance' => collect($accounts)->sum(fn($acc) => calculateBalance($acc)),
                        'coa' => collect($accounts)->map(function ($acc) {
                            return [
                                'acc_name' => $acc['acc_name'],
                                'balance' => calculateBalance($acc),
                            ];
                        })->values()->toArray(),
                    ];
                })->values()->toArray(),
            ];
        })->toArray();


        return response()->json([
            'success' => true,
            'message' => 'Successfully fetched profit and loss',
            'data' => $profitLoss
        ]);
    }

    public function cashFlowReport($startDate, $endDate)
    {
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $accounts = ChartOfAccount::with(['entriesWithJournal' => function ($query) use ($startDate, $endDate) {
            $query->whereBetween('journals.date_issued', [$startDate, $endDate]);
        }, 'account'])
            ->get();

        $revenue = $accounts->whereIn('account_id', \range(27, 30))->groupBy('account_id');
        $receivable = $accounts->whereIn('account_id', [4, 5])->groupBy('account_id');
        $inventory = $accounts->whereIn('account_id', [6, 7])->groupBy('account_id');
        $investment = $accounts->whereIn('account_id', [10, 11, 12])->groupBy('account_id');
        $assets = $accounts->whereIn('account_id', [13, 14, 15, 16, 17, 18])->groupBy('account_id');
        $payable = $accounts->whereIn('account_id', \range(19, 25))->groupBy('account_id');
        $equity = $accounts->where('account_id', 26)->groupBy('account_id');
        $expense = $accounts->whereIn('account_id', \range(33, 45))->groupBy('account_id');

        function calculateBalanceCf($acc)
        {
            $entries = collect($acc['entriesWithJournal']);
            return $acc->account->status == "D"
                ? $entries->sum('debit') - $entries->sum('credit')
                : $entries->sum('credit') - $entries->sum('debit');
        }

        $startBalance = Journal::cashflowCount('2020-01-01', $startDate);
        $endBalance = Journal::cashflowCount('2020-01-01', $endDate);

        $data = collect([
            'revenue' => $revenue,
            'receivable' => $receivable,
            'inventory' => $inventory,
            'investment' => $investment,
            'assets' => $assets,
            'payable' => $payable,
            'equity' => $equity,
            'expense' => $expense,
        ])->map(function ($group) {
            return [
                'total' => $group->flatten(1)->sum(fn($acc) => calculateBalanceCf($acc)),
                'accounts' => $group->map(function ($accounts) {
                    $first = $accounts[0];
                    $accGroupName = $first['account']['name'];

                    return [
                        'acc_name' => $accGroupName,
                        'balance' => collect($accounts)->sum(fn($acc) => calculateBalanceCf($acc)),
                        'coa' => collect($accounts)->map(function ($acc) {
                            return [
                                'acc_name' => $acc['acc_name'],
                                'balance' => calculateBalanceCf($acc),
                            ];
                        })->values()->toArray(),
                    ];
                })->values()->toArray(),
            ];
        })->toArray();

        $cashFlowBalance = [
            'start_balance' => $startBalance,
            'end_balance' => $endBalance,
        ];

        $cashFlow = array_merge($cashFlowBalance, $data);

        return response()->json([
            'success' => true,
            'message' => 'Successfully fetched cash flow',
            'data' => $cashFlow
        ]);
    }

    public function cashFlowReportX($startDate, $endDate)
    {
        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $journal = new Journal();

        $chartOfAccounts = ChartOfAccount::with(['account'])->get();
        $cashBank = $chartOfAccounts->whereIn('account_id', [1, 2]);

        $cashBankCodes = $cashBank->pluck('id');

        $transactions = $journal->with(['debt', 'cred'])
            ->selectRaw('debt_code, cred_code, SUM(amount) as total')
            ->where(function ($query) use ($cashBankCodes) {
                $query->whereIn('debt_code', $cashBankCodes)
                    ->orWhereIn('cred_code', $cashBankCodes);
            })
            ->whereBetween('date_issued', [$startDate, $endDate])
            ->groupBy('debt_code', 'cred_code')
            ->get();

        foreach ($chartOfAccounts as $value) {
            $debit = $transactions->where('cred_code', $value->id)
                ->sum('total');
            $credit = $transactions->where('debt_code', $value->id)
                ->sum('total');

            $value->balance = $debit - $credit;
        }

        $initBalance = $chartOfAccounts->whereIn('account_id', [1, 2])->sum('st_balance');

        $startBalance = $initBalance + $journal->cashflowCount('0000-00-00', $startDate);
        $endBalance = $initBalance + $journal->cashflowCount('0000-00-00', $endDate);

        $revenue = $chartOfAccounts->whereIn('account_id', \range(27, 30))->groupBy('account_id');
        $receivable = $chartOfAccounts->whereIn('account_id', [4, 5])->groupBy('account_id');
        $inventory = $chartOfAccounts->whereIn('account_id', [6, 7])->groupBy('account_id');
        $investment = $chartOfAccounts->whereIn('account_id', [10, 11, 12])->groupBy('account_id');
        $assets = $chartOfAccounts->whereIn('account_id', [13, 14, 15, 16, 17, 18])->groupBy('account_id');
        $payable = $chartOfAccounts->whereIn('account_id', \range(19, 25))->groupBy('account_id');
        $equity = $chartOfAccounts->where('account_id', 26)->groupBy('account_id');
        $expense = $chartOfAccounts->whereIn('account_id', \range(33, 45))->groupBy('account_id');

        $cashflow = [
            'start_balance' => $startBalance,
            'end_balance' => $endBalance,
            'revenue' => [
                'total' => $revenue->flatten()->sum('balance'),
                'accounts' => $revenue->map(function ($a) {
                    return [
                        'acc_name' => $a->first()->account->name,
                        'balance' => intval($a->sum('balance')),
                        'coa' => $a->map(function ($coa) {
                            return [
                                'acc_name' => $coa->acc_name,
                                'balance' => intval($coa->balance)
                            ];
                        })
                            ->values()
                            ->toArray()
                    ];
                })
                    ->values()
                    ->toArray()
            ],
            'receivable' => [
                'total' => $receivable->flatten()->sum('balance'),
                'accounts' => $receivable->map(function ($a) {
                    return [
                        'acc_name' => $a->first()->account->name,
                        'balance' => intval($a->sum('balance')),
                        'coa' => $a->map(function ($coa) {
                            return [
                                'acc_name' => $coa->acc_name,
                                'balance' => intval($coa->balance)
                            ];
                        })
                            ->values()
                            ->toArray()
                    ];
                })
                    ->values()
                    ->toArray()
            ],
            'inventory' => [
                'total' => $inventory->flatten()->sum('balance'),
                'accounts' => $inventory->map(function ($a) {
                    return [
                        'acc_name' => $a->first()->account->name,
                        'balance' => intval($a->sum('balance')),
                        'coa' => $a->map(function ($coa) {
                            return [
                                'acc_name' => $coa->acc_name,
                                'balance' => intval($coa->balance)
                            ];
                        })
                            ->values()
                            ->toArray()
                    ];
                })
                    ->values()
                    ->toArray()
            ],
            'investment' => [
                'total' => $investment->flatten()->sum('balance'),
                'accounts' => $investment->map(function ($a) {
                    return [
                        'acc_name' => $a->first()->account->name,
                        'balance' => intval($a->sum('balance')),
                        'coa' => $a->map(function ($coa) {
                            return [
                                'acc_name' => $coa->acc_name,
                                'balance' => intval($coa->balance)
                            ];
                        })
                            ->values()
                            ->toArray()
                    ];
                })
                    ->values()
                    ->toArray()
            ],
            'assets' => [
                'total' => $assets->flatten()->sum('balance'),
                'accounts' => $assets->map(function ($a) {
                    return [
                        'acc_name' => $a->first()->account->name,
                        'balance' => intval($a->sum('balance')),
                        'coa' => $a->map(function ($coa) {
                            return [
                                'acc_name' => $coa->acc_name,
                                'balance' => intval($coa->balance)
                            ];
                        })
                            ->values()
                            ->toArray()
                    ];
                })
                    ->values()
                    ->toArray()
            ],
            'payable' => [
                'total' => $payable->flatten()->sum('balance'),
                'accounts' => $payable->map(function ($a) {
                    return [
                        'acc_name' => $a->first()->account->name,
                        'balance' => intval($a->sum('balance')),
                        'coa' => $a->map(function ($coa) {
                            return [
                                'acc_name' => $coa->acc_name,
                                'balance' => intval($coa->balance)
                            ];
                        })
                            ->values()
                            ->toArray()
                    ];
                })
                    ->values()
                    ->toArray()
            ],
            'equity' => [
                'total' => $equity->flatten()->sum('balance'),
                'accounts' => $equity->map(function ($a) {
                    return [
                        'acc_name' => $a->first()->account->name,
                        'balance' => intval($a->sum('balance')),
                        'coa' => $a->map(function ($coa) {
                            return [
                                'acc_name' => $coa->acc_name,
                                'balance' => intval($coa->balance)
                            ];
                        })
                            ->values()
                            ->toArray()
                    ];
                })
                    ->values()
                    ->toArray()
            ],
            'expense' => [
                'total' => $expense->flatten()->sum('balance'),
                'accounts' => $expense->map(function ($a) {
                    return [
                        'acc_name' => $a->first()->account->name,
                        'balance' => intval($a->sum('balance')),
                        'coa' => $a->map(function ($coa) {
                            return [
                                'acc_name' => $coa->acc_name,
                                'balance' => intval($coa->balance)
                            ];
                        })
                            ->values()
                            ->toArray()
                    ];
                })
                    ->values()
                    ->toArray()
            ],
        ];

        return response()->json([
            'success' => true,
            'message' => 'Successfully fetched cash flow report',
            'data' => $cashflow
        ]);
    }

    public function getExpenses()
    {
        $chartOfAccounts = ChartOfAccount::whereIn('account_id', range(33, 45))->get();
        return new DataResource($chartOfAccounts, true, "Successfully fetched chart of accounts");
    }

    public function getCashBankBalance($warehouse, $endDate)
    {

        $journal = new Journal();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $transactions = $journal->with(['debt', 'cred'])
            ->selectRaw('debt_code, cred_code, SUM(amount) as total, warehouse_id')
            ->whereBetween('date_issued', [Carbon::create(0000, 1, 1, 0, 0, 0)->startOfDay(), $endDate])
            // ->where('warehouse_id', Auth::user()->warehouse_id) // Tambahkan filter di query
            ->groupBy('debt_code', 'cred_code', 'warehouse_id')
            ->get();

        $chartOfAccounts = ChartOfAccount::with(['account'])->where('warehouse_id', $warehouse)->get();

        foreach ($chartOfAccounts as $value) {
            $debit = $transactions->where('debt_code', $value->id)->sum('total');
            $credit = $transactions->where('cred_code', $value->id)->sum('total');

            $value->balance = ($value->account->status == "D")
                ? ($value->st_balance + $debit - $credit)
                : ($value->st_balance + $credit - $debit);
        }

        return new DataResource($chartOfAccounts, true, "Successfully fetched chart of accounts");
    }

    public function dailyDashboard($warehouse, $startDate, $endDate)
    {
        $journal = new Journal();

        $startDate = $startDate ? Carbon::parse($startDate)->startOfDay() : Carbon::now()->startOfDay();
        $endDate = $endDate ? Carbon::parse($endDate)->endOfDay() : Carbon::now()->endOfDay();

        $transactions = $journal->selectRaw('debt_code, cred_code, SUM(amount) as total, warehouse_id')
            ->whereBetween('date_issued', [Carbon::create(0000, 1, 1, 0, 0, 0)->startOfDay(), $endDate])
            // ->where('warehouse_id', $warehouse)
            ->groupBy('debt_code', 'cred_code', 'warehouse_id')
            ->get();

        $chartOfAccounts = ChartOfAccount::with(['account'])->where(fn($query) => $warehouse == "all" ? $query : $query->where('warehouse_id', $warehouse))->get();

        foreach ($chartOfAccounts as $value) {
            $debit = $transactions->where('debt_code', $value->id)->sum('total');
            $credit = $transactions->where('cred_code', $value->id)->sum('total');

            $value->balance = ($value->account->status == "D")
                ? ($value->st_balance + $debit - $credit)
                : ($value->st_balance + $credit - $debit);
        }

        $trxForSalesCount = $journal->whereBetween('date_issued', [$startDate, $endDate])
            ->where(fn($query) => $warehouse == "all" ?
                $query : $query->where('warehouse_id', $warehouse))
            ->get();

        $dailyReport = [
            'totalCash' => $chartOfAccounts->where('account_id', 1)->sum('balance'),
            'totalBank' => $chartOfAccounts->where('account_id', 2)->sum('balance'),
            'totalTransfer' => $trxForSalesCount->where('trx_type', 'Transfer Uang')->sum('amount'),
            'totalCashWithdrawal' => $trxForSalesCount->where('trx_type', 'Tarik Tunai')->sum('amount'),
            'totalCashDeposit' => $trxForSalesCount->where('trx_type', 'Deposit')->sum('amount'),
            'totalVoucher' => $trxForSalesCount->where('trx_type', 'Voucher & SP')->sum('amount'),
            'totalAccessories' => $trxForSalesCount->where('trx_type', 'Accessories')->sum('amount'),
            'totalExpense' => $trxForSalesCount->where('trx_type', 'Pengeluaran')->sum('fee_amount'),
            'totalFee' => $trxForSalesCount->where('fee_amount', '>', 0)->sum('fee_amount'),
            'profit' => $trxForSalesCount->sum('fee_amount'),
            'salesCount' => $trxForSalesCount->whereIn('trx_type', ['Transfer Uang', 'Tarik Tunai', 'Deposit', 'Voucher & SP'])->count(),
        ];

        return new DataResource($dailyReport, true, "Successfully fetched chart of accounts");
    }

    public function getAllAccounts()
    {
        $chartOfAccounts = ChartOfAccount::with(['account'])->orderBy('acc_code')->get();
        return new DataResource($chartOfAccounts, true, "Successfully fetched chart of accounts");
    }

    public function getAccountByAccountId(Request $request)
    {
        $accountIds = $request->input('account_ids', []);

        // Ensure it's an array
        if (!is_array($accountIds)) {
            $accountIds = explode(',', $accountIds); // Convert comma-separated values into an array
        }

        $chartOfAccounts = ChartOfAccount::with(['account'])
            ->whereIn('account_id', $accountIds)
            ->orderBy('acc_code')
            ->get();

        return new DataResource($chartOfAccounts, true, "Successfully fetched chart of accounts");
    }
}
